<?php
declare(strict_types=1);

namespace IsudSdk;

/**
 * 优标宝开放 API PHP 客户端 —— 手写体验层核心。
 * cURL 实现，零第三方运行时依赖。实现 SDK-DESIGN.md 全部体验：
 * 鉴权、信封解析、异常映射、Retry-After 退避、幂等键、扣点回读、本地校验。
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://app.isud.cn/api/v1/open';
    public const MCP_BASE_URL = 'https://app.isud.cn/api/v1';

    private string $apiKey;
    private string $baseUrl;
    private string $mcpBaseUrl;
    private float $timeout;
    private int $maxRetries;
    private string $userAgent;

    /** 最近一次调用的响应元数据。 */
    public ResponseMeta $lastMeta;
    /** 每次重试前回调 function(SdkError $e, int $n, float $waitSec): void */
    public $onRetry = null;

    /** @param callable|null $transport 测试注入：fn($method,$url,$headers,$body,$timeout) => [status, body, headers] */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        string $mcpBaseUrl = self::MCP_BASE_URL,
        float $timeout = 30.0,
        int $maxRetries = 3,
        ?callable $transport = null,
    ) {
        $key = $apiKey ?? getenv('ISUD_API_KEY') ?: null;
        if (!$key) {
            throw new ValidationError('缺少 API 密钥：请传入 apiKey 或设置环境变量 ISUD_API_KEY。密钥在控制台创建（注册即送调用次数）。');
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->mcpBaseUrl = rtrim($mcpBaseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->userAgent = 'isud-sdk-php/1.0.0';
        $this->transport = $transport;
        $this->lastMeta = new ResponseMeta();
    }

    private $transport;

    /** 单次 HTTP 请求，返回 [status, body, headers(lowercase)]。 */
    private function http(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body, $this->timeout);
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) $h[] = "{$k}: {$v}";
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$respHeaders) {
                $len = strlen($headerLine);
                $pos = strpos($headerLine, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($headerLine, 0, $pos)))] = trim(substr($headerLine, $pos + 1));
                }
                return $len;
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new NetworkError('网络请求失败: ' . $err);
        }
        curl_close($ch);
        return [$status, $raw, $respHeaders];
    }

    /** 内部请求管线。 */
    private function request(array $o): mixed
    {
        $root = !empty($o['mcp']) ? $this->mcpBaseUrl : $this->baseUrl;
        $url = $root . $o['path'];
        if (!empty($o['query'])) $url .= '?' . http_build_query($o['query']);

        $headers = [
            'X-API-Key' => $this->apiKey,
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $body = null;
        if (isset($o['body'])) $body = json_encode($o['body'], JSON_UNESCAPED_UNICODE);
        if (!empty($o['idempotent'])) {
            $key = $o['idempotencyKey'] ?? '';
            if ($key === '') $key = bin2hex(random_bytes(16));
            if (strlen($key) < 1 || strlen($key) > 128) throw new ValidationError('幂等键长度必须在 1~128 字符之间。');
            $headers['Idempotency-Key'] = $key;
        }

        $attempts = $o['retries'] ?? $this->maxRetries;
        $safe = ($o['method'] ?? 'GET') === 'GET' || !empty($o['idempotent']);
        $attempt = 0;

        while (true) {
            try {
                [$status, $raw, $respHeaders] = $this->http($o['method'] ?? 'GET', $url, $headers, $body);
                $meta = $this->buildMeta($status, $respHeaders, null);

                // 裸响应端点（MCP）
                if (!empty($o['noEnvelope'])) {
                    $this->lastMeta = $meta;
                    if ($status >= 400) throw new NetworkError("HTTP {$status}: " . substr((string)$raw, 0, 200), meta: $meta, statusCode: $status);
                    return json_decode($raw, true);
                }

                $env = json_decode($raw, true);
                if (!is_array($env)) {
                    $env = ['code' => '0001', 'msg' => "HTTP {$status} 返回了非 JSON 内容"];
                }
                $meta = $this->buildMeta($status, $respHeaders, $env);
                $this->lastMeta = $meta;
                $err = ErrorMap::raiseForEnvelope($env, $status, $meta);
                if ($err !== null) {
                    if ($err instanceof RateLimitError && $err->retryAfter === null) {
                        $ra = Retry::parseRetryAfter($respHeaders['retry-after'] ?? null);
                        if ($ra !== null) $err->retryAfter = $ra;
                    }
                    throw $err;
                }
                return $env['data'] ?? null;
            } catch (SdkError $e) {
                if ($attempt >= $attempts || !$e->retryable || !$safe) throw $e;
                $wait = Retry::resolveWait($e, $attempt);
                if ($this->onRetry !== null) ($this->onRetry)($e, $attempt + 1, $wait);
                usleep((int)($wait * 1_000_000));
                $attempt++;
            }
        }
    }

    private function buildMeta(int $status, array $h, ?array $env): ResponseMeta
    {
        $m = new ResponseMeta();
        $get = fn($k) => isset($h[$k]) ? $h[$k] : null;
        $m->creditsCharged = $get('x-credits-charged') !== null ? (int)$get('x-credits-charged') : null;
        $m->creditsRemaining = $get('x-credits-remaining') !== null ? (int)$get('x-credits-remaining') : null;
        $m->rateLimit = $get('x-ratelimit-limit') !== null ? (int)$get('x-ratelimit-limit') : null;
        $m->rateRemaining = $get('x-ratelimit-remaining') !== null ? (int)$get('x-ratelimit-remaining') : null;
        $m->idempotentReplay = strtolower((string)($get('idempotency-replayed') ?? '')) === 'true';
        $m->httpStatus = $status;
        if ($env !== null && !empty($env['requestId'])) $m->requestId = (string)$env['requestId'];
        if ($m->requestId === null && $get('x-request-id') !== null) $m->requestId = $get('x-request-id');
        return $m;
    }

    // ---------------- 本地校验 ----------------
    private static function checkSize(int $size): void
    {
        if ($size < 1 || $size > 100) throw new ValidationError("size 必须在 1~100 之间（收到 {$size}）。");
    }

    // ---------------- 9 个端点 ----------------

    /** 商标检索与冲突判定（扣 10 点，自动注入幂等键）。 */
    public function searchTrademarks(
        string $jurisdictionCode,
        ?string $keyword = null,
        ?string $owner = null,
        ?array $niceClasses = null,
        ?array $statusList = null,
        int $current = 1,
        int $size = 50,
        ?string $idempotencyKey = null,
    ): array {
        if ($size === 0) $size = 50;
        self::checkSize($size);
        if (!$keyword && !$owner) throw new ValidationError('keyword 与 owner 至少提供一项。');
        $body = ['jurisdictionCode' => $jurisdictionCode, 'current' => $current, 'size' => $size];
        foreach (['keyword' => $keyword, 'owner' => $owner, 'niceClasses' => $niceClasses, 'status' => $statusList] as $k => $v) {
            if ($v !== null && $v !== '') $body[$k] = $v;
        }
        $data = $this->request([
            'method' => 'POST', 'path' => '/ip/trademark/search', 'body' => $body,
            'idempotent' => true, 'idempotencyKey' => $idempotencyKey,
        ]);
        return is_array($data) ? $data : [];
    }

    /** 外观设计检索。 */
    public function searchDesigns(string $jurisdictionCode, string $keyword, ?array $locarnoClasses = null, ?string $idempotencyKey = null): mixed
    {
        $body = ['jurisdictionCode' => $jurisdictionCode, 'keyword' => $keyword];
        if ($locarnoClasses !== null) $body['locarnoClasses'] = $locarnoClasses;
        return $this->request([
            'method' => 'POST', 'path' => '/ip/design/search', 'body' => $body,
            'idempotent' => true, 'idempotencyKey' => $idempotencyKey,
        ]);
    }

    /** 分页查询商标档案。 */
    public function listTrademarks(int $current = 1, int $size = 10, ?string $keyword = null, ?string $ownerName = null, ?string $riskLevel = null): mixed
    {
        if ($size === 0) $size = 10;
        self::checkSize($size);
        $q = ['current' => $current, 'size' => $size];
        foreach (['keyword' => $keyword, 'ownerName' => $ownerName, 'riskLevel' => $riskLevel] as $k => $v) {
            if ($v !== null && $v !== '') $q[$k] = $v;
        }
        return $this->request(['method' => 'GET', 'path' => '/ip/trademark/list', 'query' => $q]);
    }

    /** 分页查询排查报告。 */
    public function listReports(int $current = 1, int $size = 10): mixed
    {
        if ($size === 0) $size = 10;
        self::checkSize($size);
        return $this->request(['method' => 'GET', 'path' => '/ip/report/list', 'query' => ['current' => $current, 'size' => $size]]);
    }

    /** 工作台汇总统计。 */
    public function getWorkbenchStats(): mixed
    {
        return $this->request(['method' => 'GET', 'path' => '/ip/workbench/stats']);
    }

    /** 点数余额与用量查询。 */
    public function getUsage(): mixed
    {
        return $this->request(['method' => 'GET', 'path' => '/ip/usage']);
    }

    /** 按 requestId 回查请求日志。 */
    public function queryRequestLog(string $requestId): mixed
    {
        if ($requestId === '' || strlen($requestId) > 64) throw new ValidationError('requestId 必须为 1~64 字符。');
        return $this->request(['method' => 'GET', 'path' => '/ip/requests', 'query' => ['requestId' => $requestId]]);
    }

    /** MCP 健康（裸响应）。 */
    public function getMcpHealth(): mixed
    {
        return $this->request(['method' => 'GET', 'path' => '/mcp/health', 'mcp' => true, 'noEnvelope' => true, 'retries' => 0]);
    }

    /** MCP JSON-RPC 调用（默认不重试）。 */
    public function callMcp(string $method, ?array $params = null, int $id = 1): mixed
    {
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'id' => $id ?: 1];
        if ($params !== null) $payload['params'] = $params;
        return $this->request([
            'method' => 'POST', 'path' => '/mcp', 'body' => $payload,
            'mcp' => true, 'noEnvelope' => true, 'retries' => 0,
        ]);
    }

    /** 人类可读格式化。 */
    public static function formatSearchResult(array $r): string
    {
        if (!$r) return '（无结果）';
        $s = "检索: \"" . ($r['keyword'] ?? '') . "\"  法域=" . ($r['jurisdictionCode'] ?? '') . "\n";
        $s .= "结论: " . ($r['conclusion'] ?: '（无）') . "\n";
        $s .= "命中 " . ($r['total'] ?? 0) . " 条 | 高险 " . ($r['highRisk'] ?? 0)
            . " / 中险 " . ($r['mediumRisk'] ?? 0) . " / 低险 " . ($r['lowRisk'] ?? 0);
        return $s;
    }
}

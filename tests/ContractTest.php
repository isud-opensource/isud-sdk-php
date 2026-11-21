<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use IsudSdk\Client;
use IsudSdk\SdkError;
use IsudSdk\ValidationError;
use IsudSdk\RateLimitError;
use IsudSdk\ErrorMap;
use IsudSdk\Retry;

/** 纯 PHP 契约测试（无需 PHPUnit）：覆盖信封/错误映射/退避/幂等/本地校验/MCP裸响应。 */
final class ContractTest
{
    private static int $pass = 0;
    private static int $fail = 0;

    private static function check(string $name, bool $cond): void
    {
        if ($cond) { self::$pass++; echo "  ✓ {$name}\n"; }
        else { self::$fail++; echo "  ✗ {$name}\n"; }
    }

    /** 构造 mock transport：[status, body, headers] */
    private static function transport(int $status, string $body, array $headers = [], ?callable $inspect = null): callable
    {
        return function ($method, $url, $reqHeaders, $reqBody, $timeout) use ($status, $body, $headers, $inspect) {
            if ($inspect !== null) $inspect($method, $url, $reqHeaders, $reqBody);
            return [$status, $body, $headers];
        };
    }

    private static function env(string $code, string $dataJson): string
    {
        return '{"code":"' . $code . '","msg":"test msg","data":' . $dataJson . '}';
    }

    public static function run(): void
    {
        // 1. 成功信封 + 扣点回读 + 幂等键
        echo "TestSuccessEnvelope:\n";
        $seen = [];
        $c = new Client('lg_ip_test_fake_key', transport: self::transport(
            200,
            self::env('0000', '{"jurisdictionCode":"CN","keyword":"优标宝","total":3,"highRisk":1,"items":[{"markText":"优标宝","riskLevel":"high"}]}'),
            ['x-credits-charged' => '10', 'x-request-id' => 'req-123'],
            function ($m, $u, $h, $b) use (&$seen) { $seen = $h; },
        ));
        $r = $c->searchTrademarks('CN', '优标宝');
        self::check('api key header', ($seen['X-API-Key'] ?? '') === 'lg_ip_test_fake_key');
        self::check('idempotency key', isset($seen['Idempotency-Key']) && $seen['Idempotency-Key'] !== '');
        self::check('result parsed', ($r['total'] ?? 0) === 3 && ($r['items'][0]['markText'] ?? '') === '优标宝');
        self::check('credits meta', $c->lastMeta->creditsCharged === 10);
        self::check('request id meta', $c->lastMeta->requestId === 'req-123');

        // 2. 错误映射
        echo "TestErrorMapping:\n";
        $cases = [
            [401, '8889', 'AuthenticationError'], [200, '8889', 'AuthenticationError'],
            [429, '9002', 'RateLimitError'], [200, '9003', 'RateLimitError'],
            [200, '9001', 'ValidationError'], [200, '0002', 'CapabilityError'],
            [200, '0001', 'ApiError'],
        ];
        foreach ($cases as [$st, $code, $want]) {
            $cc = new Client('k', maxRetries: 0, transport: self::transport($st, self::env($code, 'null')));
            try {
                $cc->getUsage();
                self::check("{$st}/{$code} throws", false);
            } catch (SdkError $e) {
                self::check("{$st}/{$code} -> {$want}", ErrorMap::kindOf($e) === $want);
            }
        }

        // 3. 本地校验（不发请求）
        echo "TestLocalValidation:\n";
        $vc = new Client('k', transport: self::transport(200, self::env('0000', '{}'), [], function () {
            self::check('no request for invalid params', false);
        }));
        try { $vc->listTrademarks(1, 101); self::check('size=101 validation', false); }
        catch (ValidationError $e) { self::check('size=101 validation', true); }
        try { $vc->searchTrademarks('CN'); self::check('empty keyword validation', false); }
        catch (ValidationError $e) { self::check('empty keyword validation', true); }
        try { $vc->queryRequestLog(str_repeat('x', 65)); self::check('long requestId validation', false); }
        catch (ValidationError $e) { self::check('long requestId validation', true); }

        // 4. MCP 裸响应
        echo "TestMCPBareResponse:\n";
        $mc = new Client('k', transport: self::transport(200, '{"status":"ok","toolCount":13}', [], function ($m, $u) {
            self::check('mcp path', str_contains($u, '/api/v1/mcp/health'));
        }));
        $h = $mc->getMcpHealth();
        self::check('mcp health parsed', ($h['status'] ?? '') === 'ok' && ($h['toolCount'] ?? 0) === 13);

        // 5. Retry-After 解析
        echo "TestParseRetryAfter:\n";
        self::check('int retry-after', Retry::parseRetryAfter('30') === 30);
        $future = gmdate('D, d M Y H:i:s', time() + 5) . ' GMT';
        $ra = Retry::parseRetryAfter($future);
        self::check('http-date retry-after', $ra !== null && $ra >= 3 && $ra <= 5);
        self::check('garbage -> null', Retry::parseRetryAfter('garbage') === null);

        // 6. 退避重试（429 两次后成功）
        echo "TestRetryOn429:\n";
        $calls = 0;
        $rc = new Client('k', maxRetries: 2, transport: function ($m, $u, $h, $b, $t) use (&$calls) {
            $calls++;
            if ($calls < 3) return [429, self::env('9002', 'null'), []];
            return [200, self::env('0000', '{"balance":100}'), []];
        });
        $u = $rc->getUsage();
        self::check('success after retries', ($u['balance'] ?? 0) === 100 && $calls === 3);

        echo "\nPASS=" . self::$pass . " FAIL=" . self::$fail . "\n";
        if (self::$fail > 0) exit(1);
    }
}

ContractTest::run();

<?php
declare(strict_types=1);

namespace IsudSdk;

/** 每次调用都解析的响应元数据（扣点/追踪/限流），成功失败都有。 */
final class ResponseMeta
{
    public ?string $requestId = null;
    public ?int $creditsCharged = null;
    public ?int $creditsRemaining = null;
    public ?int $rateLimit = null;
    public ?int $rateRemaining = null;
    public bool $idempotentReplay = false;
    public int $httpStatus = 0;

    public function __toString(): string
    {
        return sprintf('request_id=%s credits_charged=%s credits_remaining=%s',
            $this->requestId ?? '', var_export($this->creditsCharged, true), var_export($this->creditsRemaining, true));
    }
}

/** 所有 SDK 异常的基类。 */
class SdkError extends \RuntimeException
{
    public string $bizCode; // 业务错误码（Exception::$code 是 int 且 final，故用独立字段）
    public ?string $requestId;
    public ?string $suggestion;
    public bool $retryable;
    public ResponseMeta $meta;
    public int $statusCode;
    public ?int $retryAfter;

    public function __construct(
        string $message,
        string $code = '',
        ?string $requestId = null,
        ?string $suggestion = null,
        ?bool $retryable = null,
        ?ResponseMeta $meta = null,
        int $statusCode = 0,
        ?int $retryAfter = null,
    ) {
        $this->bizCode = $code;
        $this->requestId = $requestId;
        $this->suggestion = $suggestion;
        $defaultRetryable = static::defaultRetryable();
        $this->retryable = $retryable ?? $defaultRetryable;
        $this->meta = $meta ?? new ResponseMeta();
        $this->statusCode = $statusCode;
        $this->retryAfter = $retryAfter;
        parent::__construct(self::render($message, $code, $suggestion, $requestId));
    }

    protected static function defaultRetryable(): bool { return false; }

    private static function render(string $msg, string $code, ?string $sug, ?string $rid): string
    {
        $s = $msg;
        if ($code !== '') $s .= " [code={$code}]";
        if ($sug) $s .= " 建议: {$sug}";
        if ($rid) $s .= " (requestId={$rid})";
        return $s;
    }

    /** 友好提示，不裸 traceback。 */
    public function friendly(): string
    {
        $lines = ['✗ ' . $this->getMessage()];
        if ($this->bizCode !== '') $lines[] = '  错误码: ' . $this->bizCode;
        if ($this->suggestion) $lines[] = '  建议: ' . $this->suggestion;
        if ($this->requestId) $lines[] = '  追踪ID: ' . $this->requestId . '（报障时提供）';
        if ($this->meta->creditsCharged !== null) $lines[] = '  本次扣点: ' . $this->meta->creditsCharged;
        return implode("\n", $lines);
    }
}

class AuthenticationError extends SdkError
{
    protected static function defaultRetryable(): bool { return false; }
}
class RateLimitError extends SdkError
{
    protected static function defaultRetryable(): bool { return true; }
}
class ValidationError extends SdkError
{
    protected static function defaultRetryable(): bool { return false; }
}
class CapabilityError extends SdkError
{
    protected static function defaultRetryable(): bool { return false; }
}
class ApiError extends SdkError
{
    protected static function defaultRetryable(): bool { return false; }
}
class NetworkError extends SdkError
{
    protected static function defaultRetryable(): bool { return true; }
}

/** 错误码 -> 异常类（HTTP 401/429 由 Client 优先判定）。 */
final class ErrorMap
{
    public const CODE_TO_CLASS = [
        '8889' => AuthenticationError::class,
        '9001' => ValidationError::class,
        '9002' => RateLimitError::class,
        '9003' => RateLimitError::class,
        '0002' => CapabilityError::class,
        '0001' => ApiError::class,
    ];

    public static function kindOf(SdkError $e): string
    {
        return match (true) {
            $e instanceof AuthenticationError => 'AuthenticationError',
            $e instanceof RateLimitError => 'RateLimitError',
            $e instanceof ValidationError => 'ValidationError',
            $e instanceof CapabilityError => 'CapabilityError',
            $e instanceof NetworkError => 'NetworkError',
            default => 'ApiError',
        };
    }

    /** 根据信封 + HTTP 状态码构造异常；成功（0000）返回 null。 */
    public static function raiseForEnvelope(array $env, int $httpStatus, ResponseMeta $meta): ?SdkError
    {
        $code = (string)($env['code'] ?? '');
        if ($code === '0000') return null;
        $msg = $env['msg'] ?? '';
        if ($msg === '') $msg = '请求失败';
        $rid = $env['requestId'] ?? '';
        if ($rid === '') $rid = $meta->requestId;
        $sug = isset($env['suggestion']) ? (string)$env['suggestion'] : null;
        $retryable = isset($env['retryable']) ? (bool)$env['retryable'] : null;
        $retryAfter = isset($env['retryAfter']) ? (int)$env['retryAfter'] : null;

        if ($httpStatus === 401 || $code === '8889')
            return new AuthenticationError($msg, $code, $rid, $sug, $retryable, $meta, $httpStatus);
        if ($httpStatus === 429 || $code === '9002' || $code === '9003')
            return new RateLimitError($msg, $code, $rid, $sug, $retryable, $meta, $httpStatus, $retryAfter);

        $cls = self::CODE_TO_CLASS[$code] ?? ApiError::class;
        return new $cls($msg, $code, $rid, $sug, $retryable, $meta, $httpStatus);
    }
}

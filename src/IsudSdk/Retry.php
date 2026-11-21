<?php
declare(strict_types=1);

namespace IsudSdk;

/** Retry-After 退避策略 —— 与 Python/TypeScript/Go/Java 行为对齐，见 SDK-DESIGN.md §4。 */
final class Retry
{
    public const MAX_WAIT_SECONDS = 60;
    private const BASE_BACKOFF = 1.0;
    private const MAX_BACKOFF = 30.0;

    /** 解析 Retry-After 头：整数秒 或 HTTP-date。无法解析返回 null。 */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') return null;
        $v = trim($value);
        if (ctype_digit($v)) return (int)$v;
        $ts = strtotime($v);
        if ($ts !== false) return max(0, $ts - time());
        return null;
    }

    /** 第 attempt 次（从 0 起）指数退避秒数，带抖动。 */
    public static function exponentialBackoff(int $attempt): float
    {
        $raw = min(self::BASE_BACKOFF * (2 ** $attempt), self::MAX_BACKOFF);
        return $raw * (0.5 + (mt_rand() / mt_getrandmax()) * 0.5);
    }

    /** 决定本次重试等待秒数。 */
    public static function resolveWait(SdkError $err, int $attempt): float
    {
        $wait = ($err instanceof RateLimitError && $err->retryAfter !== null)
            ? (float)$err->retryAfter
            : self::exponentialBackoff($attempt);
        return max(0.0, min($wait, self::MAX_WAIT_SECONDS));
    }
}

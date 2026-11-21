<?php
declare(strict_types=1);

// 轻量 PSR-4 autoload（无 composer 时可直接 require 本文件）。
// 注意：Errors.php 把多个异常类合并定义在一个文件里，用 classmap 补充映射。
spl_autoload_register(function (string $class): void {
    $prefix = 'IsudSdk\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix)); // e.g. "ResponseMeta" or "Sub\\Thing"

    // 合并定义在 Errors.php 中的类
    static $errorsClasses = [
        'ResponseMeta', 'SdkError', 'AuthenticationError', 'RateLimitError',
        'ValidationError', 'CapabilityError', 'ApiError', 'NetworkError', 'ErrorMap',
    ];
    if (in_array($rel, $errorsClasses, true)) {
        require __DIR__ . '/src/IsudSdk/Errors.php';
        return;
    }

    $file = __DIR__ . '/src/IsudSdk/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) require $file;
});

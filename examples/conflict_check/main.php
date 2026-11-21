<?php
declare(strict_types=1);

// 示例1：近似商标排查 —— 输入商标名 + 法域，返回风险分级与结论。
// 三步跑通：1. 填 key（export ISUD_API_KEY=...） 2. composer install（或用 autoload.php） 3. php main.php 优标宝 CN
require __DIR__ . '/../../autoload.php';

use IsudSdk\Client;
use IsudSdk\SdkError;

$mark = $argv[1] ?? '优标宝';
$jurisdiction = $argv[2] ?? 'CN';

try {
    $client = new Client();
    $res = $client->searchTrademarks($jurisdiction, $mark);
    echo Client::formatSearchResult($res), "\n";
    if ($client->lastMeta->creditsCharged !== null) {
        echo "\n本次扣点 {$client->lastMeta->creditsCharged}，余额 "
            . var_export($client->lastMeta->creditsRemaining, true)
            . "（requestId={$client->lastMeta->requestId}）\n";
    }
} catch (SdkError $e) {
    fwrite(STDERR, $e->friendly() . "\n");
    exit(1);
}

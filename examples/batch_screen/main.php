<?php
declare(strict_types=1);

// 示例2：批量风险筛查 —— 逐行读商标名 → 检索 → 汇总风险 → 导出 CSV。
// 用法：export ISUD_API_KEY=... ; php main.php marks.txt out.csv CN
require __DIR__ . '/../../autoload.php';

use IsudSdk\Client;
use IsudSdk\SdkError;

if ($argc < 3) {
    fwrite(STDERR, "用法: php main.php <输入.txt> <输出.csv> [法域]\n");
    exit(2);
}
$inPath = $argv[1];
$outPath = $argv[2];
$jurisdiction = $argv[3] ?? 'CN';

try {
    $lines = file($inPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) throw new RuntimeException("无法读取输入文件: {$inPath}");
    $marks = [];
    foreach ($lines as $line) {
        $m = trim($line);
        if ($m !== '' && $m[0] !== '#') $marks[] = $m;
    }

    $client = new Client();
    $client->onRetry = function (SdkError $e, int $n, float $wait) {
        fwrite(STDERR, sprintf("  限流，第 %d 次重试，等待 %.2fs ...\n", $n, $wait));
    };

    $rows = ["mark,riskLevel,total"];
    foreach ($marks as $i => $mark) {
        printf("[%d/%d] 筛查 %s ... ", $i + 1, count($marks), $mark);
        try {
            $res = $client->searchTrademarks($jurisdiction, $mark, null, null, null, 1, 50,
                sprintf('batch-%s-%04d', $jurisdiction, $i + 1)); // 业务单号幂等键
            $level = ($res['highRisk'] ?? 0) > 0 ? 'high' : (($res['mediumRisk'] ?? 0) > 0 ? 'medium' : 'low');
            $total = $res['total'] ?? 0;
            printf("%s（%d 条）\n", $level, $total);
            $rows[] = "{$mark},{$level},{$total}";
        } catch (SdkError $e) {
            echo "失败\n";
            $rows[] = "{$mark},error,0";
        }
    }
    file_put_contents($outPath, implode("\n", $rows) . "\n");
    echo "\n完成。结果已写入 {$outPath}\n";
} catch (SdkError $e) {
    fwrite(STDERR, $e->friendly() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "✗ " . $e->getMessage() . "\n");
    exit(1);
}

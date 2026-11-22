# isud/tm (PHP)

[![Packagist Version](https://img.shields.io/packagist/v/isud/tm)](https://packagist.org/packages/isud/tm)

优标宝开放 API 的 PHP SDK。**零第三方运行时依赖**（原生 cURL + json 扩展），契约驱动。

## 依赖

- PHP >= 8.0，需要 `ext-curl` + `ext-json`。

```bash
composer require isud/tm
```

无 Composer 也可直接 `require 'autoload.php'`（内置轻量 PSR-4）。

## 三步跑通（Time-to-Hello-World < 3 分钟）

```bash
export ISUD_API_KEY=你的密钥   # 控制台领取，注册即送调用次数
```

```php
<?php
require 'vendor/autoload.php';
use IsudSdk\Client;

$client = new Client();                     // 自动读 ISUD_API_KEY
$res = $client->searchTrademarks('CN', '优标宝');
echo Client::formatSearchResult($res), "\n";
```

```
检索: "优标宝"  法域=CN
结论: 存在 1 件高近似商标，建议调整
命中 3 条 | 高险 1 / 中险 1 / 低险 1
```

## 设计

见 [`SDK-DESIGN.md`](SDK-DESIGN.md)：信封解析、6 类异常映射、Retry-After 退避、幂等键、扣点回读——与 Python/TypeScript/Go/Java 版本逐条对齐。

- **生成层**：模型用关联数组承载（对齐 `openapi.yaml` schema），开放枚举用 string。
- **体验层** `Client.php` `Errors.php` `Retry.php`：手写，实现全部 SDK 体验。

## 9 个端点

| 方法 | HTTP |
|---|---|
| `searchTrademarks` | POST /ip/trademark/search |
| `searchDesigns` | POST /ip/design/search |
| `listTrademarks` | GET /ip/trademark/list |
| `listReports` | GET /ip/report/list |
| `getWorkbenchStats` | GET /ip/workbench/stats |
| `getUsage` | GET /ip/usage |
| `queryRequestLog` | GET /ip/requests |
| `getMcpHealth` | GET /mcp/health |
| `callMcp` | POST /mcp |

## 示例项目

- `examples/conflict_check/main.php` — 近似商标排查
- `examples/batch_screen/main.php` — 批量风险筛查（幂等键 + 退避 + 导出）

## 测试

```bash
php tests/ContractTest.php       # 纯 PHP，无需 PHPUnit
# 或 composer install && composer test   # PHPUnit 版
```

契约测试用注入的 `transport` mock HTTP 响应，不依赖真实 key、不扣点。

## License

MIT

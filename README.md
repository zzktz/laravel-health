# Laravel Health

面向 Laravel 8 及以上版本的统一健康检查 Composer 包，提供存活、就绪和兼容汇总三个接口，响应遵循《统一健康检查 API 方案》的外部契约。依赖约束支持 Laravel 8.30、9、10、11、12 和 13。

## 安装

```bash
composer require zzktz/laravel-health
php artisan vendor:publish --tag=laravel-health-config
```

Laravel 包发现会自动注册服务提供者和路由。配置发布后编辑 `config/health.php`，声明本项目需要检查的连接，并在 `.env` 中设置监控令牌：

```dotenv
HEALTH_SERVICE_NAME=my-service
HEALTH_TOKEN=替换为高熵随机令牌
HEALTH_TIMEOUT_MS=4000
```

`HEALTH_SERVICE_NAME` 只能包含英文字母、数字、点、下划线和短横线，长度为 1–100 个字符。

默认依赖清单为空，确保安装包不会因为项目没有 MySQL 或 Redis 就绪检查配置而误报故障。请只把确实属于就绪条件的连接加入清单：

```php
'checks' => [
    'mysql' => [
        ['name' => 'primary', 'connection' => 'mysql', 'required' => true],
        ['name' => 'reporting', 'connection' => 'reporting', 'required' => false],
    ],
    'redis' => [
        ['name' => 'default', 'connection' => 'default', 'required' => true],
        ['name' => 'session', 'connection' => 'session', 'required' => true],
    ],
],
```

`connection` 使用 Laravel 已配置的连接名；`name` 是响应中稳定、非敏感的逻辑名称。配置完成后执行 `php artisan config:cache` 或按项目现有流程重载配置缓存。

## 接口

| 方法和地址 | 说明 | 认证 |
| --- | --- | --- |
| `GET /health/live` | 只确认应用可以处理请求，不检查数据库或 Redis | 不需要 |
| `GET /health/ready` | 检查应用及配置的依赖 | 必须提供 Bearer Token |
| `GET /health` | 与就绪接口相同，供只支持单个地址的监控平台使用 | 必须提供 Bearer Token |

请求示例：

```bash
curl -H 'Authorization: Bearer 你的HEALTH_TOKEN' https://example.com/health/ready
```

`/health` 和 `/health/ready` 在令牌缺失或错误时返回 `404`，不提示认证配置状态。令牌通过 `hash_equals` 比较，不放入 URL。

## 状态与响应

- `healthy`：所有依赖检查成功，HTTP `200`。
- `degraded`：必需依赖成功、至少一个可选依赖失败，HTTP `200`。
- `unhealthy`：必需依赖失败或配置无效，HTTP `503`。
- `/health/live` 返回 `alive` 和 HTTP `200`，不访问 MySQL 或 Redis。

响应包括 `schema_version`、`status`、`service`、UTC `timestamp`、总耗时 `duration_ms` 和各项 `checks`。失败只返回稳定错误码，不返回异常原文、连接地址、账号或密码。所有接口均设置 `Cache-Control: no-store, private` 和 `X-Content-Type-Options: nosniff`。

包内路由默认不附加业务中间件。如需访问限制，可在 `health.middleware` 配置轻量中间件；中间件不得依赖正在被检查的数据库或 Redis。也建议在网关、防火墙或反向代理限制详细健康接口的来源。

## 配置校验与依赖

- MySQL 检查使用 Laravel 命名连接执行只读 `SELECT 1`，并断开本次探测连接。
- Redis 检查使用 Laravel 命名连接发送 `PING`，支持 `phpredis` 与 `predis`。
- 只检查显式声明的连接，不扫描所有 Laravel 连接。
- 仅接受 MySQL 数据库连接；检查项名称、连接名称、必需性和总超时均会校验。
- `health.enabled=false` 可关闭包路由。
- 详细接口默认使用 Bearer Token；`health.token` 为空时它们会返回 `404`。

## 本地测试

```bash
composer install
composer test
```

本包使用 MIT 许可证。

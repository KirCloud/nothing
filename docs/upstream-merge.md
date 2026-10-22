# 上游合并与 ez-theme 兼容维护

本次合入 `wyx2685/v2board` 的 `cfabf395644b68c318751439e8510093cd3aaeb2`，保留 Git 合并祖先，后续继续使用普通三方合并。

## 后端差异

- `PaymentService.php` 完全采用上游：通过请求 Origin / 转发头选择域名，返回 `/#/order/订单号`。ez-theme 的路由兼容在前端处理。
- 保留既有 sspanel 密码迁移、订阅 token 路径和无效节点过滤；这些历史定制仍可能产生未来的合并冲突。
- 恢复 Surge 的 AnyTLS 分发，移除 VMess 网络配置的重复赋值，避免自动合并丢失协议节点或 WebSocket 配置。
- EPay 额外保留一行修复：已签名但状态不是 `TRADE_SUCCESS` 的通知返回 `false`，防止通知控制器把字符串当数组而触发 PHP 8 的 TypeError。上游修复后可以移除此差异。
- 营销邮件命令、任务、模板、队列配置仍然移除；正常通知邮件保留。

## 前端产物补丁

生产 ez-theme 目录只有已构建文件，旧压缩包也没有源码。因此通过以下脚本在原始产物上应用可复现补丁：

```sh
python3 scripts/patch-ez-theme.py /path/to/original/dist /path/to/new/dist
```

脚本只支持此次实际部署的构建。遇到不同构建会报错，避免误替换。原目录不修改，输出目录必须不存在；网关配置、域名、密钥、样式和图片保持原样，不纳入本仓库。

补丁为 `/order/:trade_no` 添加到 `/payment?trade_no=...` 的路由重定向，保留其他 query 参数；同时为两份找回密码组件加入邮箱、新密码、确认密码的 64 字符输入上限及提交校验，注册和找回密码验证码显式使用字符串，保留前导零。修改后的 JS 使用新内容哈希文件名，webpack 映射和 HTML 入口同步更新；旧 chunk 保留供已经打开的页面使用。

每次替换主题构建，应在源码路由、表单中实现相同逻辑，再移除此产物补丁。不要为主题路由重新修改后端支付回跳格式。

## 本地联调

使用独立目录，不能复制生产 `.env`、`config/v2board.php` 或 `bootstrap/cache/config.php`。此次运行 PHP 8.2.35、Laravel 8.83.29、JWT 6.11.1、Adapterman 0.7.1 / Workerman 5.1.3，沿用已安装依赖，不执行全量依赖更新。现有旧依赖的 Composer audit 报告仍有告警，需要另行规划依赖升级。

1. 启动仅监听本机的独立 MySQL/MariaDB，端口 `13316`、数据库 `v2board_integration`，**仅向此空测试库导入** `database/install.sql`。
2. 本地 `.env` 设置 `APP_ENV=local`、独立 `APP_KEY`、`APP_URL=http://127.0.0.1:18080`、`DB_HOST=127.0.0.1`、`DB_PORT=13316`、上述数据库和测试账号、`CACHE_DRIVER=file`、`QUEUE_CONNECTION=sync`、`MAIL_MAILER=log`。本地 v2board 配置启用邮箱验证码，关闭 CAPTCHA，设置前端 URL `http://127.0.0.1:18081`、订阅 URL `http://127.0.0.1:18080`。
3. 启动 `php -S 127.0.0.1:18080 -t public server.php`，执行 `php tests/Integration/upstream.php /private/path/fixtures.json`。脚本有环境、数据库和端口保护，只创建合成账号及订单；不会连接生产数据库。
4. 仅在测试前端副本关闭 API 中间层，把静态 API 地址改为 `http://127.0.0.1:18080/api/v1`，通过 `python3 -m http.server 18081 --bind 127.0.0.1` 提供页面。
5. 启动 `python3 tests/Integration/mock-epay.py`，用 fixtures 中的账号登录，打开 `/#/order/待支付订单号`，点击支付，打开本地模拟网关并选择“模拟支付成功并返回”，应看到订单“已完成”。

macOS 联调副本需要避开 Horizon 配置中的 Linux 硬件探测；自动浏览器测试副本也需要关闭主题的开发工具检测。两项仅影响测试副本，生产产物保留原样。模拟网关仅监听 `127.0.0.1:18082`，不会接入真实收款渠道。

已通过 26 项后端 HTTP / 数据库检查，包括注册验证码前导零与消费、旧密码迁移、JWT 非字符串输入、用户资料/订阅/节点/订单/工单/套餐接口、工单创建、支付 Origin 回跳、非成功通知拒绝、错误签名、支付开通和重复通知、支付轮询、Surge AnyTLS、VMess WebSocket、找回密码边界。浏览器验证了实际 ez-theme 登录、订单读取和完整模拟支付回跳，并检查找回密码输入的 maxlength。未进行真实扣款。

## 生产发布与回退

发布前保存代码（含 Git 和 vendor）、前端、环境及缓存配置、数据库完整备份，并核对生产源码与合并前快照一致。

数据库仅补充本次所需字段，存在时跳过：

```sql
ALTER TABLE v2_server_v2node
  ADD COLUMN trusted_x_forwarded_for varchar(255) NULL
  COMMENT '信任的x-forwarded-for头部' AFTER network_settings;
```

禁止在生产导入 `install.sql`，也不要使用会 reset、删除 lock 并全量更新依赖的 `update.sh`。本次运行库版本已满足新的 Composer 约束；移除的 CORS 引用不再使用，既有 vendor 未强制清理。

源码通过 Git 快进同步；部署产物时保留 `.env`、运行配置、存储和自定义主题。先发布前端兼容路由，再启用上游后端回跳。`config:cache`、`view:clear` 和 `package:discover` 必须以 `www` 身份执行；核对 `bootstrap/cache/config.php` 为 `www:www 0644`，并通过实际 Nginx / PHP-FPM 检查，不能只检查 root 身份运行的 Workerman。

发布后重载本站 PHP-FPM、重启 Horizon 和本站 Workerman，核对节点、订单、工单、用户数量及 API。保留发布前备份和 Git stash。回退需同时恢复前后端代码及配置；新增 nullable 字段可保留，避免回退时破坏数据库数据。

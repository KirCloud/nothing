# 第三方登录与 Cloudflare Turnstile

前端仓库为 `KirCloud/nothing-front`。两种登录布局支持 Google、Telegram 和邮箱链接登录，入口由 `/api/v1/guest/comm/config` 的 `auth_providers` 决定。密钥保存在后端环境配置中。

实现参考了 EZ-THEME-R 的 Google 与 Telegram 登录，以及 EZ-Theme 的 Google 跳转和邮箱链接接口。回调使用随机状态、PKCE 和短期一次性登录票据，成功后由现有 `AuthService` 签发会话。Google 账号通过 `v2_oauth_identity` 记录稳定的 `sub` 标识。[EZ-THEME-R](https://github.com/codeman857/EZ-THEME-R/blob/f1e0dd2253a816c866308c8d21098ffe0d9d8ae2/src/views/auth/components/ThirdPartyLogin.vue) [EZ-Theme 变体](https://github.com/lasitan/EZ-Theme-master)

## Google 登录

先按现有安装或更新流程创建 `v2_oauth_identity` 表。也可单独执行此迁移。

```sh
php artisan migrate --path=database/migrations/2026_11_11_000000_create_oauth_identities_table.php --force
```

在后端 `.env` 配置以下参数。`OAUTH_FRONTEND_URL` 填用户前端的基础地址，支持带子目录。已有 `v2board.app_url` 正确指向前端时，可省略此项。

```dotenv
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://api.example.com/api/v1/passport/oauth/google/callback
OAUTH_FRONTEND_URL=https://panel.example.com
```

在 Google Cloud Console 创建 Web 应用 OAuth 凭据，并把上述 `GOOGLE_REDIRECT_URI` 原样登记为授权重定向 URI。配置完成后执行 `php artisan config:cache`，并重载实际使用的 PHP 常驻进程。

Google 验证邮箱后，新账号沿用本站的注册关闭、邀请码、邮箱白名单、IP 注册频率和试用套餐规则。已有 Gmail 或 Google Workspace 邮箱可自动关联现有账号。已有未关联 Google 身份的第三方邮箱账号使用本站邮箱登录入口。已有 Google 身份记录按 `sub` 查找，邮箱变化后仍可登录原账号。

## Telegram 登录

```dotenv
TELEGRAM_LOGIN_ENABLED=true
TELEGRAM_BOT_USERNAME=your_bot
```

沿用面板中的机器人启用状态、Token 和 Webhook。用户先在本站个人中心绑定 Telegram。登录时复制网页上的 `/login ...` 命令，并私聊发送给机器人。请求有效期为两分钟，网页自动完成登录。只有已绑定账号可以确认请求，封禁账号无法登录。

## 邮箱链接登录

```dotenv
MAIL_LINK_LOGIN_ENABLED=true
```

沿用现有邮件队列和 `mailLogin` 模板，需要邮件队列正常运行。用户在登录页填写邮箱后点击“发送邮箱登录链接”，在五分钟内使用发起请求的浏览器打开邮件链接。可在同一浏览器的新标签页完成登录。邮箱不存在时返回相同的发送结果。

## Cloudflare Turnstile

原有注册和找回密码页面已包含 `form-turnstile`、`modal-turnstile`、脚本加载和 `recaptcha_data` 提交代码。两套注册布局现已处理组件过期和错误事件，过期凭证会清空。前端优先使用后端返回的 `captcha_type`，兼容未返回此字段的旧后端。

后端原先固定调用 Google reCAPTCHA 校验，现在注册和验证码邮件共用 `CaptchaService`。在面板开启人机验证后，配置以下环境参数即可使用 Turnstile。

```dotenv
CAPTCHA_TYPE=cloudflare
CAPTCHA_SITE_KEY=your-public-site-key
CAPTCHA_SECRET_KEY=your-secret-key
```

环境中未填写 Site Key 和 Secret Key 时，使用面板原有的 `recaptcha_site_key` 和 `recaptcha_key`。`CAPTCHA_TYPE=google` 使用原有 Google 校验流程。前端 `verifyUrl` 仅为历史配置，实际校验由后端调用对应的官方接口。Turnstile Token 有效期为五分钟且只能使用一次，邮件验证码与注册表单各自获取凭证。[Cloudflare 校验文档](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)

## 验证

```sh
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/social-tests-config.php CACHE_DRIVER=array php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Feature/SocialLoginTest.php
```

测试使用内存 SQLite、模拟的 Google 和 Cloudflare 响应及邮件队列。覆盖状态校验、回调地址限制、一次性凭证、账号关联、注册规则、Telegram 确认和 Turnstile 服务端失败处理。正式凭据和真实授权需要在部署环境验证。

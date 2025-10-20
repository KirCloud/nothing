<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>到期提示</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style type="text/css">
        /* ---------- 全局基础设置 ---------- */
        body {
            margin: 0;
            padding: 0;
            background-color: #f5f5f7; /* 柔和灰底 */
            font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: #1d1d1f;
            -webkit-font-smoothing: antialiased;
            line-height: 1.6;
        }
        img { max-width: 100%; display: block; }

        /* ---------- 容器与卡片 ---------- */
        .container {
            max-width: 600px;
            margin: 36px auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        /* ---------- 头部标题 ---------- */
        .header {
            background: linear-gradient(180deg,#f7f7f9,#f2f2f5);
            text-align: center;
            padding: 28px 20px;
            border-bottom: 1px solid #e8e8ea;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #0a0a0a;
        }

        /* ---------- 内容区 ---------- */
        .content {
            padding: 32px 36px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 12px;
            color: #0b0b0b;
        }
        .message {
            font-size: 15px;
            color: #505050;
            margin: 10px 0 20px;
        }
        .highlight {
            color: #0071e3; /* Apple 蓝 */
            font-weight: 600;
        }
        .note {
            font-size: 13px;
            color: #8b8b90;
            margin-top: 16px;
        }

        /* ---------- 按钮 ---------- */
        .btn {
            display: inline-block;
            background: #0071e3;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 15px;
            transition: background 0.2s ease;
        }
        .btn:hover { background: #005bb5; }

        /* ---------- 页脚 ---------- */
        .footer {
            padding: 20px;
            text-align: center;
            font-size: 13px;
            color: #9b9b9f;
            border-top: 1px solid #f0f0f2;
            background: #fafafa;
        }
        .footer a {
            color: inherit;
            text-decoration: none;
            margin: 0 6px;
        }

        /* ---------- 响应式 ---------- */
        @media only screen and (max-width: 640px) {
            .container { margin: 18px; }
            .content { padding: 20px; }
            .greeting { font-size: 16px; }
            .message { font-size: 14px; }
            .btn { padding: 10px 20px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <!-- 外层主容器 -->
    <div class="container" role="article" aria-label="到期提示邮件">
        <!-- 头部标题 -->
        <div class="header">
            <h1>到期提示</h1>
        </div>

        <!-- 正文内容 -->
        <div class="content">
            <p class="greeting">尊敬的用户：</p>
            <p class="message">
                您的订阅套餐将于 <span class="highlight">24小时后</span> 到期，请及时续费以免影响使用。
            </p>
            <div style="text-align:center; margin-top:24px;">
                <!-- Blade 变量保持不变 -->
                <a href="{{$url}}" class="btn" target="_blank" rel="noopener noreferrer">登录 {{$name}}</a>
            </div>
            <p class="note">(本邮件由系统自动发出，请勿直接回复)</p>
        </div>

        <!-- 页脚 -->
        <div class="footer">
            <div>&copy; {{$name}}. All Rights Reserved.</div>
            <div>
                <a href="{{$url}}/#/profile">我的订阅</a> |
                <a href="{{$url}}/#/docs">使用教程</a>
            </div>
        </div>
    </div>
</body>
</html>

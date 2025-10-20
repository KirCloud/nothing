<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>邮箱验证码</title>
    <style type="text/css">
        /* 通用样式 */
        body {
            margin: 0;
            padding: 0;
            background-color: #f5f5f7;
            font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: #1d1d1f;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #f0f0f0, #fafafa);
            text-align: center;
            padding: 40px 20px;
            border-bottom: 1px solid #e5e5e7;
        }

        .header h1 {
            font-size: 26px;
            margin: 0;
            font-weight: 600;
            color: #000;
        }

        .content {
            padding: 40px 30px;
        }

        .content p {
            font-size: 16px;
            color: #515154;
            margin: 12px 0;
        }

        .code-box {
            text-align: center;
            background: #f9f9fb;
            border: 1px solid #e5e5e7;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
        }

        .code-box h2 {
            font-size: 18px;
            color: #0071e3;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .code {
            font-size: 38px;
            font-weight: 700;
            color: #1d1d1f;
            letter-spacing: 3px;
        }

        .button {
            display: inline-block;
            background: #0071e3;
            color: white !important;
            text-decoration: none;
            padding: 14px 36px;
            border-radius: 9999px;
            font-size: 16px;
            font-weight: 500;
            margin-top: 25px;
            transition: background 0.3s ease;
        }

        .button:hover {
            background: #005bb5;
        }

        .footer {
            text-align: center;
            font-size: 13px;
            color: #86868b;
            padding: 25px;
            border-top: 1px solid #e5e5e7;
            background: #fafafa;
        }

        .footer a {
            color: #86868b;
            text-decoration: none;
            margin: 0 5px;
        }

        @media only screen and (max-width: 640px) {
            .container {
                margin: 20px;
            }

            .content {
                padding: 20px;
            }

            .header h1 {
                font-size: 22px;
            }

            .code {
                font-size: 32px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- 头部标题 -->
        <div class="header">
            <h1>邮箱验证码</h1>
        </div>

        <!-- 正文内容 -->
        <div class="content">
            <p style="font-size: 18px; font-weight: 600;">尊敬的用户：</p>
            <p>请填写以下验证码完成邮箱验证（5分钟内有效）：</p>

            <!-- 验证码模块 -->
            <div class="code-box">
                <h2>您的验证码</h2>
                <div class="code">{{$code}}</div>
            </div>

            <p>如非本人操作，请忽略本邮件。</p>

            <!-- 按钮 -->
            <div style="text-align: center;">
                <a href="{{$url}}" class="button">前往 {{$name}}</a>
            </div>

            <p style="margin-top: 30px; font-size: 13px; color: #86868b;">
                （本邮件由系统自动发出，请勿直接回复）
            </p>
        </div>

        <!-- 页脚 -->
        <div class="footer">
            <p>&copy; {{$name}}. All Rights Reserved.</p>
            <p>
                <a href="{{$url}}/#/profile">我的订阅</a> |
                <a href="{{$url}}/#/docs">使用教程</a>
            </p>
        </div>
    </div>
</body>
</html>

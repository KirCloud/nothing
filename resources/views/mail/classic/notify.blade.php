<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>网站通知</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style type="text/css">
        /* ---------- 全局基础（邮件兼容友好） ---------- */
        /* 使用系统字体堆栈以贴近苹果风格 */
        body {
            margin: 0;
            padding: 0;
            background-color: #f5f5f7; /* 页面背景：柔和灰 */
            font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: #1d1d1f;
            -webkit-font-smoothing: antialiased;
            line-height: 1.6;
        }
        img { max-width: 100%; display: block; }

        /* 容器：白底、圆角、轻阴影 */
        .container {
            max-width: 600px;
            margin: 36px auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        /* 头部：简洁标题 */
        .header {
            padding: 28px 24px;
            text-align: center;
            background: linear-gradient(180deg,#fafafa,#f6f6f8);
            border-bottom: 1px solid #e8e8ea;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #0a0a0a;
        }

        /* 正文区 */
        .content {
            padding: 28px 30px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 8px;
            color: #0b0b0b;
        }
        .body-text {
            font-size: 15px;
            color: #505050;
            margin: 8px 0 18px;
            white-space: pre-wrap; /* 保持换行，保险起见 */
        }

        /* 操作按钮（圆角） */
        .btn {
            display: inline-block;
            background: #0071e3; /* Apple 蓝 */
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 26px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 15px;
        }
        .btn:hover { background: #005bb5; }

        /* 说明文字与页脚 */
        .note { font-size: 13px; color: #8b8b90; margin-top: 16px; }
        .footer {
            padding: 18px 24px;
            text-align: center;
            font-size: 13px;
            color: #9b9b9f;
            border-top: 1px solid #f0f0f2;
            background: #fafafa;
        }
        .footer a { color: inherit; text-decoration: none; margin: 0 6px; }

        /* 响应式：小屏时缩小内边距 */
        @media only screen and (max-width: 640px) {
            .container { margin: 18px; }
            .header { padding: 20px; }
            .content { padding: 20px; }
            .greeting { font-size: 16px; }
            .body-text { font-size: 14px; }
            .btn { padding: 10px 20px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <!-- 主容器：保留 aria 与 role 提升可访问性 -->
    <div class="container" role="article" aria-label="网站通知邮件">

        <!-- 头部：标题 -->
        <div class="header">
            <h1>网站通知</h1>
        </div>

        <!-- 正文：注意保留 Blade 的 nl2br 用法以保证换行 -->
        <div class="content">
            <!-- 问候语 -->
            <p class="greeting">尊敬的用户：</p>

            <!-- 主要内容：使用 {!! nl2br($content) !!} 以渲染服务器端换行 -->
            <!-- 若 content 已包含 HTML，请直接使用 {!! $content !!} -->
            <div class="body-text">
                {!! nl2br($content) !!}
            </div>

            <!-- 可选按钮：跳转到站点（可用于查看详情/处理） -->
            <div style="text-align:center; margin-top:14px;">
                <!-- Blade 变量 {{$url}} 与 {{$name}} 保留 -->
                <a href="{{$url}}" class="btn" target="_blank" rel="noopener noreferrer">前往 {{$name}}</a>
            </div>

            <!-- 额外说明 -->
            <p class="note">(本邮件由系统自动发出，请勿直接回复。如非本人操作，请及时联系客服或修改密码。)</p>
        </div>

        <!-- 页脚：版权与快速链接 -->
        <div class="footer" role="contentinfo">
            <div style="margin-bottom:6px;">&copy; {{$name}}. All Rights Reserved.</div>
            <div>
                <a href="{{$url}}/#/profile">我的订阅</a> |
                <a href="{{$url}}/#/docs">使用教程</a>
            </div>
        </div>
    </div>
</body>
</html>

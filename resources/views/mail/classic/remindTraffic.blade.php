<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>流量提示</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style type="text/css">
        /* ---------- 全局基础样式（兼容邮件客户端） ---------- */
        body {
            margin: 0;
            padding: 0;
            background-color: #f5f5f7; /* 柔和灰，用于页面背景 */
            font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: #1d1d1f;
            -webkit-font-smoothing: antialiased;
            line-height: 1.6;
        }

        img { max-width: 100%; }

        /* 容器：居中、圆角、轻阴影 */
        .container {
            max-width: 600px;
            margin: 36px auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        /* 头部：淡背景、较大标题 */
        .header {
            padding: 28px 24px;
            text-align: center;
            background: linear-gradient(180deg, #fafafa, #f6f6f8);
            border-bottom: 1px solid #e8e8ea;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #0a0a0a;
        }

        /* 正文区域 */
        .content {
            padding: 28px 30px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 8px 0;
            color: #0b0b0b;
        }
        .text {
            font-size: 15px;
            color: #505050;
            margin: 8px 0 18px 0;
        }

        /* 流量提示卡片 */
        .usage-card {
            background: #fbfbfd;
            border: 1px solid #e9e9ee;
            border-radius: 12px;
            padding: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin: 16px 0;
        }
        .usage-info {
            flex: 1 1 auto;
        }
        .usage-title {
            font-size: 13px;
            color: #6b6b70;
            margin-bottom: 6px;
        }
        .usage-percent {
            font-size: 28px;
            font-weight: 700;
            color: #1d1d1f;
            letter-spacing: 1px;
        }

        /* 可视化进度条（纯样式） */
        .progress {
            width: 160px;
            height: 10px;
            background: #eef1f6;
            border-radius: 999px;
            overflow: hidden;
            margin-top: 8px;
        }
        .progress > .bar {
            height: 100%;
            background: linear-gradient(90deg,#ffb74d,#ff8a00); /* 当接近耗尽时用暖色 */
            width: 0%; /* 在模板中用内联 style 覆盖 width */
        }

        /* 操作按钮 */
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

        /* 说明文字与页脚 */
        .note {
            font-size: 13px;
            color: #8b8b90;
            margin-top: 16px;
        }
        .footer {
            padding: 18px 24px;
            text-align: center;
            font-size: 13px;
            color: #9b9b9f;
            border-top: 1px solid #f0f0f2;
            background: #fafafa;
        }

        /* 响应式：小屏时缩小内边距与字体 */
        @media only screen and (max-width: 640px) {
            .container { margin: 18px; }
            .header { padding: 20px; }
            .content { padding: 20px; }
            .usage-percent { font-size: 24px; }
            .btn { padding: 10px 20px; font-size: 14px; }
        }
    </style>
</head>

<body>
    <!-- 注意：Blade 变量保留。请在调用模板时传入 $name, $url, $percent 等 -->
    <div class="container" role="article" aria-label="流量提示邮件">
        <!-- 头部 -->
        <div class="header">
            <h1>流量提示</h1>
        </div>

        <!-- 正文 -->
        <div class="content">
            <!-- 问候语 -->
            <p class="greeting">尊敬的用户，</p>

            <!-- 说明行 -->
            <p class="text">
                您本月的套餐流量使用情况如下，请合理安排使用以避免提前耗尽。
            </p>

            <!-- 使用卡片：左侧显示百分比、右侧为进度条 & 操作按钮 -->
            <div class="usage-card" role="region" aria-label="流量使用情况">
                <div class="usage-info">
                    <!-- usage-title：简短说明 -->
                    <div class="usage-title">当前已使用</div>

                    <!-- usage-percent：主要百分比（使用 Blade 变量替换） -->
                    <!-- 这里使用 $percent，例如 "95%"，请在生成邮件时传入带单位的字符串或在模板里追加 '%' -->
                    <div class="usage-percent">95%</div>

                    <!-- 进度条：为兼容大多数邮件客户端，我们在 style 里直接设置宽度 -->
                    <!-- 如果 $percent 是数字（如 95），可在渲染时生成内联宽度 style -->
                    <div class="progress" aria-hidden="true">
                        <!-- 以下 inline style 示例： width: 95%; 你可以在后端根据 $percent 动态替换 -->
                        <div class="bar" style="width: 95%;"></div>
                    </div>
                </div>

                <!-- 操作：登录按钮（链接到面板或续费页面） -->
                <div style="white-space: nowrap;">
                    <a href="{{$url}}" class="btn">前往 {{$name}}</a>
                </div>
            </div>

            <!-- 额外提示 -->
            <p class="note">(本邮件由系统自动发出，请勿直接回复。如非本人操作，请及时联系客服或修改密码。)</p>
        </div>

        <!-- 页脚 -->
        <div class="footer">
            <div style="margin-bottom:6px;">&copy; {{$name}}. All Rights Reserved.</div>
            <div>
                <a href="{{$url}}/#/profile" style="color:inherit; text-decoration:none; margin: 0 8px;">我的订阅</a> |
                <a href="{{$url}}/#/docs" style="color:inherit; text-decoration:none; margin: 0 8px;">使用教程</a>
            </div>
        </div>
    </div>
</body>
</html>

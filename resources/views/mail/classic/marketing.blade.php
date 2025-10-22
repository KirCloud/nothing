<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $name }} 欢迎您回归</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif;
            background-color: #f5f5f7;
            color: #1d1d1f;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }

        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #f0f0f0, #fafafa);
            text-align: center;
            padding: 40px 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .header h1 {
            margin: 0;
            font-size: 28px;
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

        .coupon-box {
            text-align: center;
            background: #f9f9fb;
            border: 1px solid #e5e5e7;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
        }

        .coupon-box h2 {
            margin: 0;
            font-size: 20px;
            color: #0071e3; /* Apple 蓝 */
            font-weight: 600;
        }

        .coupon-code {
            font-size: 26px;
            font-weight: 700;
            color: #1d1d1f;
            margin: 10px 0;
            letter-spacing: 1px;
        }

        .button {
            display: inline-block;
            background: #0071e3;
            color: white !important;
            padding: 14px 32px;
            text-decoration: none;
            border-radius: 9999px;
            font-weight: 500;
            font-size: 16px;
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

        strong {
            color: #000;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $name }} 欢迎您回归 🌟</h1>
        </div>
        <div class="content">
            <p>亲爱的 {{ $user_name }}，</p>

            <p>感谢您一直以来对 <strong>{{ $name }}</strong> 的支持与信任！</p>

            <p>我们注意到您已经有一段时间未登录。为了感谢您的陪伴，我们特别为您准备了一份回归礼：</p>

            <div class="coupon-box">
                <h2>专属 8 折优惠码</h2>
                <div class="coupon-code">{{ $coupon_code }}</div>
                <p>有效期：30 天</p>
            </div>

            <p>使用此优惠码即可享受 8 折续费优惠，重拾您熟悉的体验。</p>

            <div style="text-align: center; margin-top: 30px;">
                <a href="{{ $url }}" class="button">立即续费</a>
            </div>

            <p>如需帮助，您可随时联系 <strong>{{ $name }}</strong> 客服团队。</p>

            <p>感谢您的支持，期待再次为您服务。</p>

            <p style="margin-top: 30px;">— {{ $name }} 团队</p>
        </div>
        <div class="footer">
            <p>此邮件由系统自动发送，请勿直接回复。</p>
            <p>若您不希望再接收类似邮件，请联系客服取消订阅。</p>
        </div>
    </div>
</body>
</html>

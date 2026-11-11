@php
    $font = "-apple-system, BlinkMacSystemFont, 'SF Pro Text', 'PingFang SC', 'Helvetica Neue', Helvetica, Arial, sans-serif";
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>@yield('title')</title>
    <style type="text/css">
        body { margin: 0; padding: 0; background-color: #f5f5f7; -webkit-font-smoothing: antialiased; -webkit-text-size-adjust: 100%; }
        table { border-collapse: collapse; }
        .mail-body p { margin: 0 0 16px; font-size: 17px; line-height: 1.47; color: #1d1d1f; }
        .mail-body p.mail-secondary { font-size: 14px; line-height: 1.43; color: #6e6e73; }
        .mail-body a.mail-link { color: #0066cc; text-decoration: none; }
        @media only screen and (max-width: 600px) {
            .mail-page { padding: 0 !important; }
            .mail-card { border-radius: 0 !important; padding: 36px 24px !important; }
            .mail-headline { font-size: 28px !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .mail-page { background-color: #000000 !important; }
            .mail-card { background-color: #1d1d1f !important; }
            .mail-brand, .mail-headline, .mail-body p, .mail-strong { color: #f5f5f7 !important; }
            .mail-body p.mail-secondary, .mail-label { color: #a1a1a6 !important; }
            .mail-body a.mail-link { color: #2997ff !important; }
            .mail-well { background-color: #2c2c2e !important; }
            .mail-track { background-color: #424245 !important; }
            .mail-rule { border-color: #424245 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f5f5f7;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent;">@yield('preheader')</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f5f5f7" style="background-color: #f5f5f7;">
        <tr>
            <td class="mail-page" align="center" style="padding: 40px 16px; font-family: {!! $font !!};">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 560px;">
                    <tr>
                        <td class="mail-card" bgcolor="#ffffff" style="background-color: #ffffff; border-radius: 18px; padding: 44px 44px 36px; text-align: left; font-family: {!! $font !!};">
                            <div class="mail-brand" style="font-size: 17px; line-height: 1.24; font-weight: 600; color: #1d1d1f;">{{$name}}</div>
                            <h1 class="mail-headline" style="margin: 28px 0 14px; font-size: 32px; line-height: 1.125; font-weight: 600; letter-spacing: -0.003em; color: #1d1d1f;">@yield('title')</h1>
                            <div class="mail-body">
                                @yield('content')
                            </div>
                            <div class="mail-rule" style="margin-top: 28px; padding-top: 16px; border-top: 1px solid #d2d2d7; font-size: 12px; line-height: 1.34; color: #86868b;">
                                此邮件由系统自动发出，请勿直接回复。
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 20px 16px 0; font-family: {!! $font !!}; font-size: 12px; line-height: 1.6; color: #86868b;">
                            <a href="{{$url}}/#/profile" style="color: #6e6e73; text-decoration: none;">我的订阅</a>
                            <span style="color: #d2d2d7;">&nbsp;|&nbsp;</span>
                            <a href="{{$url}}/#/docs" style="color: #6e6e73; text-decoration: none;">使用教程</a>
                            <br>
                            Copyright &copy; {{ date('Y') }} {{$name}}. 保留所有权利。
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

@extends('mail.classic.layout')

@section('title')邮箱验证码@endsection
@section('preheader')您的验证码是 {{$code}}，5 分钟内有效。@endsection

@section('content')
<p>请使用以下验证码完成邮箱验证。验证码 5 分钟内有效。</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0;">
    <tr>
        <td class="mail-well" align="center" bgcolor="#f5f5f7" style="background-color: #f5f5f7; border-radius: 12px; padding: 24px 16px;">
            <div class="mail-label" style="font-size: 12px; line-height: 1.34; color: #6e6e73;">验证码</div>
            <div class="mail-strong" style="margin-top: 6px; font-size: 36px; line-height: 1.1; font-weight: 600; letter-spacing: 8px; color: #1d1d1f;">{{$code}}</div>
        </td>
    </tr>
</table>
<p class="mail-secondary">如果这不是您本人的操作，请忽略这封邮件，您的账号不会受到影响。</p>
<p><a class="mail-link" href="{{$url}}" target="_blank" rel="noopener noreferrer">前往 {{$name}} &rsaquo;</a></p>
@endsection

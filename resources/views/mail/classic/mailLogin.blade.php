@extends('mail.classic.layout')

@section('title')登录 {{$name}}@endsection
@section('preheader')点击邮件中的按钮即可登录，链接 5 分钟内有效。@endsection

@section('content')
<p>您正在请求登录 {{$name}}。点击下方按钮即可完成登录，无需输入密码。</p>
@include('mail.classic.partials.button', ['href' => $link, 'label' => '登录'])
<p class="mail-secondary">链接 5 分钟内有效，且只能使用一次。请在发起登录的设备上打开。</p>
<p class="mail-secondary">按钮无法点击？请将以下链接复制到浏览器中打开：<br><a class="mail-link" href="{{$link}}" style="word-break: break-all;">{{$link}}</a></p>
<p class="mail-secondary">如果这不是您本人的操作，请忽略这封邮件，不会有人因此登录您的账号。</p>
@endsection

@extends('mail.classic.layout')

@section('title')订阅即将到期@endsection
@section('preheader')您的订阅将在 24 小时内到期，请及时续费。@endsection

@section('content')
<p>您的订阅套餐将在 <strong class="mail-strong" style="font-weight: 600; color: #1d1d1f;">24 小时内</strong>到期。为避免服务中断，请及时续费。</p>
@include('mail.classic.partials.button', ['href' => $url, 'label' => '前往续费'])
<p class="mail-secondary">如果您已完成续费，请忽略这封邮件。</p>
@endsection

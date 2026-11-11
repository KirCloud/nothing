@extends('mail.classic.layout')

@section('title')网站通知@endsection
@section('preheader'){{ \Illuminate\Support\Str::limit(strip_tags($content), 80) }}@endsection

@section('content')
<p>{!! nl2br($content) !!}</p>
<p><a class="mail-link" href="{{$url}}" target="_blank" rel="noopener noreferrer">前往 {{$name}} &rsaquo;</a></p>
<p class="mail-secondary">如果这不是您本人的操作，请及时修改密码或联系客服。</p>
@endsection

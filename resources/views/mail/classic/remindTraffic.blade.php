@extends('mail.classic.layout')

@section('title')流量即将用尽@endsection
@section('preheader')您的套餐流量已使用 95%。@endsection

@section('content')
<p>您当前套餐的流量已使用 95%，请合理安排使用，以免提前耗尽。</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0;">
    <tr>
        <td class="mail-well" bgcolor="#f5f5f7" style="background-color: #f5f5f7; border-radius: 12px; padding: 20px 24px 24px;">
            <div class="mail-label" style="font-size: 12px; line-height: 1.34; color: #6e6e73;">已使用</div>
            <div class="mail-strong" style="margin: 4px 0 14px; font-size: 40px; line-height: 1.1; font-weight: 600; color: #1d1d1f;">95%</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td width="95%" height="6" bgcolor="#ff9500" style="background-color: #ff9500; border-radius: 3px 0 0 3px; font-size: 0; line-height: 0;">&nbsp;</td>
                    <td class="mail-track" width="5%" height="6" bgcolor="#d2d2d7" style="background-color: #d2d2d7; border-radius: 0 3px 3px 0; font-size: 0; line-height: 0;">&nbsp;</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
@include('mail.classic.partials.button', ['href' => $url, 'label' => '查看套餐'])
<p class="mail-secondary">流量用尽后服务将暂停，直至流量重置或购买新的套餐。</p>
@endsection

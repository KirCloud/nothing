<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

use App\Jobs\SendMarketingEmailJob;
use App\Models\MailLog;
class MailService
{
    public function remindTraffic (User $user)
    {
        if (!$user->remind_traffic) return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag)) return;
        if (!Cache::put($flag, 1, 24 * 3600)) return;
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached 95%', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url')
            ]
        ]);
    }

    public function remindExpire(User $user)
    {
        if (!($user->expired_at !== NULL && ($user->expired_at - 86400) < time() && $user->expired_at > time())) return;
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The service in :app_name is about to expire', [
               'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindExpire',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url')
            ]
        ]);
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud) return false;
        if (!$transfer_enable) return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 95) return false;
        if ($percentage >= 100) return false;
        return true;
    }

    /**
 * 发送营销邮件给单个用户
 * @param User $user
 * @return bool
 */
public function sendMarketingEmail(User $user)
{
    // 检查用户是否过期
    if ($user->expired_at && $user->expired_at > time()) {
        return false; // 用户未过期，不发送
    }

    // 检查发送限制
    $userLimit = config('v2board.marketing_email_user_limit', 2);
    $timeLimit = config('v2board.marketing_email_time_limit', 30);

    $recentMarketingEmails = MailLog::where('email', $user->email)
        ->where('template_name', 'like', '%marketing%')
        ->where('created_at', '>', now()->subDays($timeLimit))
        ->count();

    if ($recentMarketingEmails >= $userLimit) {
        return false; // 已达到发送限制
    }

    try {
        $subject = config('v2board.marketing_email_subject', 'KirSSR欢迎您回归，特奉上8折优惠码');
        $brandName = config('v2board.marketing_email_brand_name', 'KirSSR');
        $couponCode = config('v2board.marketing_email_coupon_code', 'WelcomeBack');

        SendMarketingEmailJob::dispatch([
            'email' => $user->email,
            'subject' => $subject,
            'template_name' => 'marketing',
            'template_value' => [
                'name' => $brandName,
                'url' => config('v2board.app_url'),
                'user_name' => $user->email,
                'coupon_code' => $couponCode,
            ]
        ], 'send_email_marketing');

        return true;
    } catch (\Exception $e) {
        Log::error('Marketing email send failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * 获取待发送营销邮件的用户数量
 * @return int
 */
public function getPendingMarketingUsersCount()
{
    try {
        $timeLimit = config('v2board.marketing_email_time_limit', 30);
        $userLimit = config('v2board.marketing_email_user_limit', 2);

        // 当前时间戳
        $now = time();

        // 限制条件：过期超过3天的用户
        $expiredThreshold = $now - (3 * 24 * 60 * 60); // 当前时间 - 3天（单位秒）

        // 获取所有过期超过3天、且未封禁的用户
        $expiredUsers = User::where('expired_at', '<', $expiredThreshold)
            ->where('banned', 0)
            ->get();

        $count = 0;
        foreach ($expiredUsers as $user) {
            $recentMarketingEmails = MailLog::where('email', $user->email)
                ->where('template_name', 'like', '%marketing%')
                ->where('created_at', '>', now()->subDays($timeLimit))
                ->count();

            if ($recentMarketingEmails < $userLimit) {
                $count++;
            }
        }

        return $count;
    } catch (\Exception $e) {
        Log::error('Get pending marketing users count failed: ' . $e->getMessage());
        return 0;
    }
}
}

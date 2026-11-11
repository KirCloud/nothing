<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\SocialLoginService;

class Login extends Telegram
{
    public $command = '/login';
    public $description = '确认网页登录';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) return;
        abort_unless(isset($message->args[0]) && is_string($message->args[0]), 422, '请发送网页上显示的完整登录命令');
        app(SocialLoginService::class)->approveTelegram($message->args[0], (int)$message->chat_id);
        $this->telegramService->sendMessage($message->chat_id, '登录已确认，请返回发起请求的网页。');
    }
}

<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Models\MailLog;

class SendMarketingEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;
    
    public function __construct($params, $queue = 'send_email_marketing')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    public function handle()
    {
        // 使用营销邮件专用配置
        Config::set('mail.host', config('v2board.marketing_email_host', 'smtp.fastmail.com'));
        Config::set('mail.port', config('v2board.marketing_email_port', 465));
        Config::set('mail.encryption', config('v2board.marketing_email_encryption', 'ssl'));
        Config::set('mail.username', config('v2board.marketing_email_username', '邮箱'));
        Config::set('mail.password', config('v2board.marketing_email_password', '密码'));
        Config::set('mail.from.address', config('v2board.marketing_email_from_address', '发件邮箱'));
        Config::set('mail.from.name', config('v2board.marketing_email_from_name', '大机场团队'));
        
        $params = $this->params;
        $email = $params['email'];
        $subject = $params['subject'];
        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];
        
        try {
            sleep(2); 
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => isset($error) ? $error : NULL
        ];

        MailLog::create($log);
        $log['config'] = config('mail');
        return $log;
    }
}
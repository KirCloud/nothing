<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\MailService;

class SendMarketingEmail extends Command
{
    protected $signature = "send:marketingEmail {--count=30}";
    protected $description = "发送营销邮件给过期用户";

    public function handle()
    {
        $this->info("Starting marketing email sending process...");
        $maxCount = $this->option("count") ?: config("v2board.marketing_email_send_count", 30);
        $this->info("Max emails to send: {$maxCount}");
        $this->info("Current time: " . date("Y-m-d H:i:s"));

        $mailService = new MailService();
        $pendingCount = $mailService->getPendingMarketingUsersCount();
        $this->info("Pending users: {$pendingCount}");

        if ($pendingCount == 0) {
            $this->info("No pending users to send marketing emails to.");
            return;
        }

        $users = User::where("expired_at", "<", time())
            ->where("banned", 0)
            ->get();

        $sentCount = 0;
        foreach ($users as $user) {
            if ($sentCount >= $maxCount) {
                break;
            }

            if ($mailService->sendMarketingEmail($user)) {
                $sentCount++;
                $this->info("Sent marketing email to: {$user->email}");
            }
        }

        $this->info("Successfully queued {$sentCount} marketing emails.");
    }
}
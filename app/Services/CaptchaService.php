<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use ReCaptcha\ReCaptcha;

class CaptchaService
{
    public static function type(): string
    {
        return config('v2board.recaptcha_type', config('services.captcha.type', 'google'));
    }

    public static function siteKey()
    {
        return config('services.captcha.site_key') ?: config('v2board.recaptcha_site_key');
    }

    public function verify($token): void
    {
        if (!(int)config('v2board.recaptcha_enable', 0)) return;

        abort_unless(is_string($token) && $token !== '' && strlen($token) <= 2048, 422, __('Invalid code is incorrect'));
        $secret = config('services.captcha.secret_key') ?: config('v2board.recaptcha_key');
        $success = false;

        if (self::type() === 'cloudflare') {
            try {
                $response = Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                ]);
                $success = $response->successful() && $response->json('success') === true;
            } catch (\Exception $error) {
                $success = false;
            }
        } elseif (self::type() === 'google') {
            $success = (new ReCaptcha($secret))->verify($token)->isSuccess();
        }

        abort_unless($success, 422, __('Invalid code is incorrect'));
    }
}

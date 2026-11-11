<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SocialLoginService
{
    public static function providers(): array
    {
        $frontend = config('services.login.frontend_url') ?: config('v2board.app_url');
        return [
            'google' => (bool)($frontend && config('services.google.client_id') && config('services.google.client_secret')),
            'telegram' => (bool)(config('services.login.telegram_enabled') && config('v2board.telegram_bot_enable')
                && config('v2board.telegram_bot_token') && config('services.login.telegram_bot_name')),
            'email_link' => (bool)($frontend && config('services.login.mail_link_enabled')),
        ];
    }

    public function frontendCallback($requested = null): string
    {
        $frontend = config('services.login.frontend_url') ?: config('v2board.app_url');
        abort_unless(is_string($frontend) && filter_var($frontend, FILTER_VALIDATE_URL)
            && in_array(parse_url($frontend, PHP_URL_SCHEME), ['https', 'http'], true)
            && !parse_url($frontend, PHP_URL_QUERY) && !parse_url($frontend, PHP_URL_FRAGMENT)
            && !parse_url($frontend, PHP_URL_USER), 503, 'Login frontend URL is not configured');
        $callback = rtrim($frontend, '/') . '/#/oauth/callback';
        abort_if($requested !== null && $requested !== $callback, 422, 'Invalid login callback URL');
        return $callback;
    }

    public function createChallenge(string $kind, array $data, int $seconds = 300): string
    {
        $token = bin2hex(random_bytes(32));
        $data['expires_at'] = time() + $seconds;
        Cache::put('social-login:' . $kind . ':' . $token, $data, $seconds);
        return $token;
    }

    public function useChallenge(string $kind, string $token, callable $callback)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 422, 'Invalid or expired login request');
        $key = 'social-login:' . $kind . ':' . $token;
        return Cache::lock($key . ':lock', 60)->block(5, function () use ($key, $callback) {
            $data = Cache::get($key);
            abort_unless(is_array($data) && $data['expires_at'] > time(), 422, 'Invalid or expired login request');
            $result = $callback($data);
            Cache::forget($key);
            return $result;
        });
    }

    public function issueTicket(User $user, string $clientState): string
    {
        return $this->createChallenge('ticket', ['user_id' => $user->id, 'client_state' => $clientState]);
    }

    public function authenticate(int $userId, Request $request): array
    {
        $user = User::find($userId);
        abort_unless($user && !$user->banned, 403, __('Your account has been suspended'));
        $user->last_login_at = time();
        $user->save();
        return (new AuthService($user))->generateAuthData($request);
    }

    public function googleUser(string $code, string $verifier): array
    {
        $token = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->googleCallback(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
        ])->throw()->json('access_token');
        abort_unless(is_string($token) && $token !== '', 403, 'Google login failed');
        $profile = Http::withToken($token)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();
        abort_unless(is_array($profile) && !empty($profile['sub']) && ($profile['email_verified'] ?? false) === true,
            403, 'Google email is not verified');
        validator($profile, [
            'sub' => 'required|string|max:255',
            'email' => 'required|string|email:strict|max:64|regex:/^[\x21-\x7E]+$/',
        ])->validate();
        return $profile;
    }

    public function googleCallback(): string
    {
        return config('services.google.redirect') ?: url('/api/v1/passport/oauth/google/callback');
    }

    public function approveTelegram(string $code, int $telegramId): void
    {
        abort_unless(self::providers()['telegram'], 404);
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $code), 422, 'Invalid or expired login request');
        $key = 'social-login:telegram:' . $code;
        Cache::lock($key . ':lock', 15)->block(5, function () use ($key, $telegramId) {
            $challenge = Cache::get($key);
            abort_unless(is_array($challenge) && $challenge['expires_at'] > time(), 422, 'Invalid or expired login request');
            abort_if(isset($challenge['user_id']), 422, 'Login request already confirmed');
            $user = User::where('telegram_id', $telegramId)->first();
            abort_unless($user && !$user->banned, 403, '请先在网站个人中心绑定此 Telegram 账号');
            $challenge['user_id'] = $user->id;
            Cache::put($key, $challenge, $challenge['expires_at'] - time());
        });
    }
}

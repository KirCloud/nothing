<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Models\OAuthIdentity;
use App\Services\RegistrationService;
use App\Services\SocialLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OAuthController extends Controller
{
    public function auth(Request $request, SocialLoginService $login)
    {
        $data = $request->validate([
            'type' => 'required|in:google',
            'state' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'redirect' => 'nullable|string|max:2048',
            'code' => 'nullable|string|max:64',
        ]);
        abort_unless(SocialLoginService::providers()['google'], 404);
        $frontend = $login->frontendCallback($data['redirect'] ?? null);
        $verifier = bin2hex(random_bytes(32));
        $state = $login->createChallenge('google', [
            'client_state' => $data['state'], 'frontend' => $frontend,
            'invite_code' => $data['code'] ?? '', 'verifier' => $verifier,
        ]);
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => SocialLoginService::googleClientId(),
            'redirect_uri' => $login->googleCallback(),
            'response_type' => 'code', 'scope' => 'openid email profile',
            'state' => $state, 'prompt' => 'select_account',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
        return response(['data' => ['url' => $url]]);
    }

    public function googleCallback(Request $request, SocialLoginService $login)
    {
        abort_unless(SocialLoginService::providers()['google'], 404);
        $request->validate(['state' => 'required|string|max:64', 'code' => 'nullable|string|max:4096']);
        return $login->useChallenge('google', $request->input('state'), function ($challenge) use ($request, $login) {
            $query = ['state' => $challenge['client_state']];
            try {
                abort_unless($request->filled('code') && !$request->filled('error'), 403);
                $profile = $login->googleUser($request->input('code'), $challenge['verifier']);
                $user = Cache::lock('social-login:email:' . hash('sha256', strtolower($profile['email'])), 15)->block(5, function () use ($profile, $challenge, $request) {
                    return DB::transaction(function () use ($profile, $challenge, $request) {
                        $identity = OAuthIdentity::where('provider', 'google')->where('provider_id', $profile['sub'])->first();
                        if ($identity) {
                            $user = User::find($identity->user_id);
                            abort_unless($user && !$user->banned, 403);
                            return $user;
                        }
                        $user = User::where('email', $profile['email'])->first();
                        if ($user) {
                            // Auto-link only addresses Google controls, then use its stable subject ID.
                            abort_unless(preg_match('/@gmail\.com$/i', $profile['email']) || !empty($profile['hd']), 403,
                                'Please use email login for this account');
                            abort_if($user->banned, 403);
                        } else {
                            $registration = clone $request;
                            $registration->replace([
                                'email' => $profile['email'], 'password' => bin2hex(random_bytes(32)),
                                'invite_code' => $challenge['invite_code'],
                            ]);
                            $user = app(RegistrationService::class)->register($registration, true);
                        }
                        OAuthIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => $profile['sub']]);
                        return $user;
                    });
                });
                $query['ticket'] = $login->issueTicket($user, $challenge['client_state']);
            } catch (\Exception $error) {
                $query['error'] = 'login_failed';
            }
            return redirect()->away($challenge['frontend'] . '?' . http_build_query($query))
                ->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        });
    }

    public function exchange(Request $request, SocialLoginService $login)
    {
        $data = $request->validate([
            'ticket' => 'required|string|max:64', 'state' => 'required|string|size:64',
        ]);
        $auth = $login->useChallenge('ticket', $data['ticket'], function ($challenge) use ($data, $request, $login) {
            abort_unless(hash_equals($challenge['client_state'], $data['state']), 403, 'Invalid login state');
            return $login->authenticate($challenge['user_id'], $request);
        });
        return response(['data' => $auth])->header('Cache-Control', 'no-store');
    }

    public function telegram(Request $request, SocialLoginService $login)
    {
        abort_unless(SocialLoginService::providers()['telegram'], 404);
        $request->validate(['state' => 'required|string|regex:/^[a-f0-9]{64}$/D']);
        $code = $login->createChallenge('telegram', ['client_state' => $request->input('state')], 120);
        return response(['data' => ['hash' => $code, 'expires_in' => 120,
            'bot_name' => config('services.login.telegram_bot_name')]])->header('Cache-Control', 'no-store');
    }

    public function checkTelegram(Request $request, SocialLoginService $login)
    {
        abort_unless(SocialLoginService::providers()['telegram'], 404);
        $data = $request->validate(['code' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'state' => 'required|string|size:64']);
        $challenge = Cache::get('social-login:telegram:' . $data['code']);
        abort_unless(is_array($challenge) && hash_equals($challenge['client_state'], $data['state']), 422, 'Invalid or expired login request');
        if (!isset($challenge['user_id'])) return response(['data' => ['status' => 'pending']]);
        $auth = $login->useChallenge('telegram', $data['code'], function ($challenge) use ($data, $request, $login) {
            abort_unless(hash_equals($challenge['client_state'], $data['state']), 403);
            return $login->authenticate($challenge['user_id'], $request);
        });
        return response(['data' => array_merge(['status' => 'success'], $auth)])->header('Cache-Control', 'no-store');
    }

    public function mailLink(Request $request, SocialLoginService $login)
    {
        abort_unless(SocialLoginService::providers()['email_link'], 404);
        $data = $request->validate([
            'email' => 'required|string|email:strict|max:64|regex:/^[\x21-\x7E]+$/',
            'state' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'redirect' => 'nullable|string|max:2048',
        ]);
        $frontend = $login->frontendCallback($data['redirect'] ?? null);
        $key = 'social-login:mail-cooldown:' . hash('sha256', strtolower($data['email']));
        abort_unless(Cache::add($key, true, 60), 429, 'Please wait before requesting another login link');
        $user = User::where('email', $data['email'])->first();
        if ($user && !$user->banned) {
            $ticket = $login->issueTicket($user, $data['state']);
            SendEmailJob::dispatch([
                'email' => $user->email, 'subject' => config('v2board.app_name', 'V2Board') . ' 登录链接',
                'template_name' => 'mailLogin',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'), 'url' => config('v2board.app_url'),
                    'link' => $frontend . '?' . http_build_query(['ticket' => $ticket, 'state' => $data['state']]),
                ],
            ]);
        }
        return response(['data' => true]);
    }
}

<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            $router->post('/auth/register', 'V1\\Passport\\AuthController@register');
            $router->post('/auth/login', 'V1\\Passport\\AuthController@login');
            $router->get ('/auth/token2Login', 'V1\\Passport\\AuthController@token2Login');
            $router->post('/auth/forget', 'V1\\Passport\\AuthController@forget');
            $router->post('/auth/getQuickLoginUrl', 'V1\\Passport\\AuthController@getQuickLoginUrl');
            $router->post('/oauth/auth', 'V1\\Passport\\OAuthController@auth')->middleware('throttle:10,1');
            $router->get('/oauth/google/callback', 'V1\\Passport\\OAuthController@googleCallback')->middleware('throttle:20,1');
            $router->post('/oauth/exchange', 'V1\\Passport\\OAuthController@exchange')->middleware('throttle:20,1');
            $router->post('/oauth/telegram', 'V1\\Passport\\OAuthController@telegram')->middleware('throttle:10,1');
            $router->post('/oauth/telegram/check', 'V1\\Passport\\OAuthController@checkTelegram')->middleware('throttle:60,1');
            $router->post('/auth/loginWithMailLink', 'V1\\Passport\\OAuthController@mailLink')->middleware('throttle:5,1');
            // Comm
            $router->post('/comm/sendEmailVerify', 'V1\\Passport\\CommController@sendEmailVerify');
            $router->post('/comm/pv', 'V1\\Passport\\CommController@pv');
        });
    }
}

<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            // Client
            if (empty(config('v2board.subscribe_path'))) {
                // 修改为可带 token 的路由，例如 /client/subscribe/abcd1234
                $router->get('/subscribe/{token?}', 'V1\\Client\\ClientController@subscribe');
            }

            // App
            $router->get('/app/getConfig', 'V1\\Client\\AppController@getConfig');
            $router->get('/app/getVersion', 'V1\\Client\\AppController@getVersion');
        });
    }
}

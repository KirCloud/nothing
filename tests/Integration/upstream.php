<?php
// Run only against the disposable local database and HTTP server described in docs/upstream-merge.md.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local') || config('database.connections.mysql.database') !== 'v2board_integration'
    || config('database.connections.mysql.port') != 13316 || config('database.connections.mysql.host') !== '127.0.0.1') {
    throw new RuntimeException('Refusing to run outside the disposable local test database');
}

use App\Models\User;
use App\Models\Plan;
use App\Models\Payment;
use App\Models\Order;
use App\Models\ServerAnytls;
use App\Utils\Helper;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

$checks = [];
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks[] = $message;
}
$http = new GuzzleHttp\Client(['base_uri' => 'http://127.0.0.1:18080/api/v1/', 'http_errors' => false, 'timeout' => 30]);
function api($method, $path, $data = [], $token = '') {
    global $http;
    $options = ['headers' => ['Accept' => 'application/json', 'Origin' => 'http://127.0.0.1:18081']];
    if ($token) $options['headers']['Authorization'] = $token;
    $options[$method === 'GET' ? 'query' : 'form_params'] = $data;
    $r = $http->request($method, $path, $options);
    return [$r->getStatusCode(), json_decode((string)$r->getBody(), true), (string)$r->getBody()];
}

$suffix = bin2hex(random_bytes(4));
$email = "local-$suffix@example.com";
$password = 'SyntheticPassword123';
Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), '012345', 600);
[$status, $body] = api('POST', 'passport/auth/register', ['email' => $email, 'password' => $password, 'email_code' => '012345']);
check($status === 200 && isset($body['data']['auth_data']), 'Registration accepts a string verification code with a leading zero');
check(!Cache::has(CacheKey::get('EMAIL_VERIFY_CODE', $email)), 'Registration consumes the verification code');
$user = User::where('email', $email)->firstOrFail();
// Preserve the sspanel migration behavior through the merge.
$user->password = md5($password); $user->password_algo = 'md5'; $user->save();
[$status, $body] = api('POST', 'passport/auth/login', ['email' => $email, 'password' => $password]);
check($status === 200 && isset($body['data']['token'], $body['data']['auth_data']), 'Login response remains compatible with ez-theme');
check(password_verify($password, $user->fresh()->password), 'Legacy password upgrades on successful login');
$token = $body['data']['auth_data'];
check(App\Services\AuthService::decryptAuthData(['bad']) === false, 'Malformed JWT input is rejected without a TypeError');

$plan = Plan::create(['name' => 'Local synthetic plan', 'group_id' => 1, 'transfer_enable' => 100, 'month_price' => 1000, 'show' => 1, 'renew' => 1]);
$user->refresh(); $user->plan_id = $plan->id; $user->group_id = 1;
$user->expired_at = time() + 86400; $user->transfer_enable = 100 * 1073741824; $user->save();
ServerAnytls::create(['group_id' => [1], 'name' => 'Local AnyTLS', 'host' => 'node.example.com', 'port' => '443', 'server_port' => 443, 'rate' => '1', 'show' => 1]);
$payment = Payment::create(['name' => 'Local EPay', 'payment' => 'EPay', 'uuid' => Helper::guid(), 'enable' => 1,
    'config' => ['pid' => 'local', 'key' => 'synthetic-key', 'type' => 'alipay', 'url' => 'http://127.0.0.1:18082']]);
foreach (['user/info', 'user/getSubscribe', 'user/server/fetch', 'user/order/fetch', 'user/ticket/fetch', 'user/plan/fetch'] as $path) {
    [$status] = api('GET', $path, [], $token);
    check($status === 200, "$path remains accessible with ez-theme authorization");
}
[$status, $body] = api('POST', 'user/ticket/save', ['subject' => 'Local integration', 'level' => 1, 'message' => 'Synthetic ticket'], $token);
check($status === 200, 'Ticket creation works on the local database');
[$status, $body] = api('GET', 'user/ticket/fetch', [], $token);
check($status === 200 && count($body['data']) === 1, 'Created ticket appears in the list');

[$status, $body] = api('POST', 'user/order/save', ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 1000], $token);
check($status === 200 && is_string($body['data']), 'Deposit order creation works');
$trade = $body['data'];
[$status, $body] = api('POST', 'user/order/checkout', ['trade_no' => $trade, 'method' => $payment->id], $token);
check($status === 200 && $body['type'] === 1, 'EPay checkout returns its URL to ez-theme');
parse_str(parse_url($body['data'], PHP_URL_QUERY), $checkout);
check($checkout['return_url'] === "http://127.0.0.1:18081/#/order/$trade", 'Checkout uses the upstream frontend return route and Origin');
$notify = ['out_trade_no' => $trade, 'trade_no' => "local-$suffix", 'trade_status' => 'WAIT_BUYER_PAY', 'money' => '10.00', 'pid' => 'local'];
function signNotify($p) { ksort($p); $p['sign'] = md5(stripslashes(urldecode(http_build_query($p))) . 'synthetic-key'); $p['sign_type'] = 'MD5'; return $p; }
$notifyPath = 'guest/payment/notify/EPay/' . $payment->uuid;
[$status, , $raw] = api('GET', $notifyPath, signNotify($notify));
check($status === 500 && strpos($raw, 'TypeError') === false && Order::where('trade_no', $trade)->first()->status === 0,
    'Signed non-success EPay notification is refused and leaves the order unpaid');
$notify['trade_status'] = 'TRADE_SUCCESS';
$bad = signNotify($notify); $bad['sign'] = str_repeat('0', 32);
[$status] = api('GET', $notifyPath, $bad);
check($status === 500 && Order::where('trade_no', $trade)->first()->status === 0, 'Invalid EPay signature cannot pay an order');
[$status, , $raw] = api('GET', $notifyPath, signNotify($notify));
check($status === 200 && $raw === 'success', 'Successful synthetic EPay callback is acknowledged');
check(Order::where('trade_no', $trade)->first()->status === 3 && $user->fresh()->balance === 1000, 'Paid order opens and credits the synthetic wallet once');
[$status] = api('GET', $notifyPath, signNotify($notify));
check($status === 200 && $user->fresh()->balance === 1000, 'Repeated callback does not credit the wallet twice');
[$status, $body] = api('GET', 'user/order/check', ['trade_no' => $trade], $token);
check($status === 200 && $body['data'] === 3, 'Payment status polling sees the completed order');
[$status, $body] = api('POST', 'user/order/save', ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 500], $token);
$pendingTrade = $body['data'];

$r = $http->get('client/subscribe/' . $user->token, ['query' => ['flag' => 'surge']]);
check($r->getStatusCode() === 200 && strpos((string)$r->getBody(), '=anytls,') !== false, 'Surge subscription includes AnyTLS through the token path');
$vmess = Helper::buildVmessUri($user->uuid, ['name' => 'VMess', 'host' => 'node.example.com', 'port' => 443, 'tls' => 0, 'network' => 'ws', 'network_settings' => ['path' => '/synthetic-ws', 'headers' => ['Host' => 'ws.example.com']]]);
$vmess = json_decode(base64_decode(substr(explode('#', $vmess)[0], 8)), true);
check($vmess['path'] === '/synthetic-ws' && $vmess['host'] === 'ws.example.com', 'VMess preserves snake_case WebSocket settings');

Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), '012345', 600);
[$status] = api('POST', 'passport/auth/forget', ['email' => $email, 'password' => str_repeat('x', 65), 'email_code' => '012345']);
check($status !== 200 && Cache::has(CacheKey::get('EMAIL_VERIFY_CODE', $email)), '65-character reset password is refused without consuming the code');
[$status] = api('POST', 'passport/auth/forget', ['email' => $email, 'password' => $password, 'email_code' => '012345']);
check($status === 200 && !Cache::has(CacheKey::get('EMAIL_VERIFY_CODE', $email)), 'Reset accepts the six-digit string code and consumes it');
$fixture = ['email' => $email, 'password' => $password, 'pending_trade' => $pendingTrade, 'paid_trade' => $trade];
if (isset($argv[1])) file_put_contents($argv[1], json_encode($fixture));
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

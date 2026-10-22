<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClientController extends Controller
{
    /**
     * 可信任的代理IP配置
     * 根据实际部署环境调整
     */
    private $trustedProxies = [
        // Cloudflare IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',

        // Cloudflare IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',

        // AWS ELB/ALB 常见IP段
        '52.93.0.0/16', '54.239.0.0/16',

        // 阿里云SLB
        '100.64.0.0/10',

        // 腾讯云CLB
        '9.0.0.0/8',

        // 私有网络
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8',
        // IPv6私有地址
        'fc00::/7', '::1/128',
    ];

    /**
     * IP头部优先级配置
     */
    private $ipHeaderPriority = [
        'CF-Connecting-IP',     // Cloudflare - 最高优先级
        'True-Client-IP',       // Akamai
        'X-Real-IP',           // Nginx
        'X-Forwarded-For',     // 标准代理头
        'X-Client-IP',         // Apache mod_proxy
        'X-Cluster-Client-IP', // 集群负载均衡
        'Client-IP',           // 其他代理
        'X-Forwarded',         // 非标准但常见
        'Forwarded-For',       // 旧标准
        'Forwarded',           // RFC 7239
    ];

    public function subscribe(Request $request,$token = null)
    {
        $token = $token ?? $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'token is null'], 400);
        }

        // 用户标识（客户端类型判断）
        $flag = $request->input('flag') ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        // 尝试通过 token 获取用户
        $user = \App\Models\User::where('token', $token)->first();
        if (!$user) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        // 使用优化后的IP获取方法
        $subscriberIP = $this->getSubscriberIP($request);

        Log::info("Subscriber request", [
            'user_id' => $user['id'],
            'ip' => $subscriberIP,
            'user_agent' => $flag
        ]);

        $userService = new UserService();
        $serverService = new ServerService();

        // account not expired and is not banned.
        if ($userService->isAvailable($user)) {
            $servers = $serverService->getAvailableServers($user);

            // 检查用户流量是否用完
            $useTraffic = $user['u'] + $user['d'];
            $totalTraffic = $user['transfer_enable'];
            $remainingTraffic = $totalTraffic - $useTraffic;

            if ($remainingTraffic <= 0) {
                return $this->handleNoTrafficSubscription($user, $servers);
            }

            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            $this->notifyTelegram($user, $subscriberIP, $flag);
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    $this->notifyTelegram($user, $subscriberIP, $flag);
                    return $class->handle();
                }
            }
            $this->setSubscribeInfoToServers($servers, $user);
            $this->notifyTelegram($user, $subscriberIP, $flag);
            $class = new General($user, $servers);
            return $class->handle();
        } else {
            return $this->handleExpiredSubscription($user, $serverService);
        }
    }

    /**
     * 获取客户端真实IP地址
     * 优化版本，支持多种代理场景和IPv6
     *
     * @param Request $request
     * @return string
     */
    private function getSubscriberIP(Request $request): string
    {
        $remoteAddr = $request->server('REMOTE_ADDR', '0.0.0.0');

        // 收集所有可能的IP来源
        $allIpSources = $this->getAllIpSources($request);

        // 记录详细调试信息
        Log::info('IP detection sources', [
            'remote_addr' => $remoteAddr,
            'all_sources' => $allIpSources,
            'is_trusted_proxy' => $this->isFromTrustedProxy($remoteAddr)
        ]);

        // 策略1：优先从HTTP头中查找有效的公网IP（包括IPv6）
        $publicIp = $this->findFirstPublicIp($allIpSources);
        if ($publicIp) {
            Log::info("Found public IP: {$publicIp}");
            return $publicIp;
        }

        // 策略2：如果来自可信代理，尝试从代理头获取任何有效IP
        if ($this->isFromTrustedProxy($remoteAddr)) {
            $proxyIp = $this->findFirstValidIpFromProxy($allIpSources);
            if ($proxyIp && !$this->isLoopbackIP($proxyIp)) {
                Log::info("Found proxy IP: {$proxyIp}");
                return $proxyIp;
            }
        }

        // 策略3：如果REMOTE_ADDR是有效公网IP，直接使用
        if ($this->isValidPublicIP($remoteAddr)) {
            Log::info("Using REMOTE_ADDR as public IP: {$remoteAddr}");
            return $remoteAddr;
        }

        // 策略4：使用Laravel默认方法
        $laravelIp = $request->ip();
        if ($laravelIp && !$this->isLoopbackIP($laravelIp) && $this->isValidIp($laravelIp)) {
            Log::info("Using Laravel IP: {$laravelIp}");
            return $laravelIp;
        }

        // 策略5：从所有来源中找第一个不是回环地址的有效IP
        foreach ($allIpSources as $source => $ip) {
            if ($ip && !$this->isLoopbackIP($ip) && $this->isValidIp($ip)) {
                Log::warning("Using fallback IP from {$source}: {$ip}");
                return $ip;
            }
        }

        // 最后的兜底方案：返回一个标识性的IP而不是127.0.0.1
        Log::error("No valid IP found, using unknown IP marker", [
            'remote_addr' => $remoteAddr,
            'all_sources' => $allIpSources
        ]);
        return '0.0.0.1'; // 使用特殊IP标识未知来源
    }

    /**
     * 从HTTP头中提取IP
     *
     * @param Request $request
     * @param string $header
     * @return string|null
     */
    private function extractIpFromHeader(Request $request, string $header): ?string
    {
        $value = $request->header($header);
        if (!$value) {
            return null;
        }

        // 特殊处理不同类型的头部
        switch ($header) {
            case 'X-Forwarded-For':
            case 'X-Client-IP':
            case 'X-Cluster-Client-IP':
                return $this->extractFirstValidIp($value);

            case 'Forwarded':
                return $this->parseForwardedHeader($value);

            default:
                return $this->cleanIpString($value);
        }
    }

    /**
     * 从逗号分隔的IP列表中提取第一个有效公网IP
     *
     * @param string $value
     * @return string|null
     */
    private function extractFirstValidIp(string $value): ?string
    {
        $ips = array_map('trim', explode(',', $value));

        // 优先返回第一个公网IP
        foreach ($ips as $ip) {
            $cleanIp = $this->cleanIpString($ip);
            if ($cleanIp && $this->isValidPublicIP($cleanIp)) {
                return $cleanIp;
            }
        }

        // 如果没有公网IP，返回第一个有效的私网IP
        foreach ($ips as $ip) {
            $cleanIp = $this->cleanIpString($ip);
            if ($cleanIp && $this->isValidIp($cleanIp)) {
                return $cleanIp;
            }
        }

        return null;
    }

    /**
     * 解析RFC 7239 Forwarded头
     *
     * @param string $value
     * @return string|null
     */
    private function parseForwardedHeader(string $value): ?string
    {
        // 匹配 for=IP 格式
        if (preg_match('/for=(["\[]?)([^"\],;\s]+)\1/i', $value, $matches)) {
            return $this->cleanIpString($matches[2]);
        }
        return null;
    }

    /**
     * 清理IP字符串（支持IPv6）
     *
     * @param string $ip
     * @return string
     */
    private function cleanIpString(string $ip): string
    {
        $ip = trim($ip, ' "\'');

        // 处理IPv6地址的方括号
        if (strpos($ip, '[') === 0 && strrpos($ip, ']') === strlen($ip) - 1) {
            $ip = substr($ip, 1, -1);
        }

        // 移除端口号（IPv4情况）
        if (strpos($ip, ':') !== false && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            $ip = $parts[0];
        }

        // 处理IPv6地址中的端口号 [ip]:port 格式已经在上面处理了

        return trim($ip);
    }

    /**
     * 从$_SERVER数组获取IP
     *
     * @return string|null
     */
    private function getIpFromServerArray(): ?string
    {
        $serverKeys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED'
        ];

        foreach ($serverKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $this->extractFirstValidIp($_SERVER[$key]);
                if ($ip) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * 收集所有可能的IP来源
     *
     * @param Request $request
     * @return array
     */
    private function getAllIpSources(Request $request): array
    {
        $sources = [];

        // HTTP头部来源 - 使用 extractIpFromHeader 正确处理逗号分隔的IP列表
        foreach ($this->ipHeaderPriority as $header) {
            $value = $request->header($header);
            if ($value) {
                // 对于特殊头部（如 X-Forwarded-For），提取第一个有效IP
                $extractedIp = $this->extractIpFromHeader($request, $header);
                if ($extractedIp) {
                    $sources["header_{$header}"] = $extractedIp;
                } else {
                    // 如果提取失败，使用原始值（可能已经是单个IP）
                    $sources["header_{$header}"] = $this->cleanIpString($value);
                }
            }
        }

        // $_SERVER数组来源 - 对于包含逗号的头部，提取第一个有效IP
        $serverKeys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($serverKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $value = $_SERVER[$key];
                // 对于可能包含多个IP的头部，提取第一个有效IP
                if (in_array($key, ['HTTP_X_FORWARDED_FOR', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_CLIENT_IP'])) {
                    $extractedIp = $this->extractFirstValidIp($value);
                    if ($extractedIp) {
                        $sources["server_{$key}"] = $extractedIp;
                    } else {
                        $sources["server_{$key}"] = $this->cleanIpString($value);
                    }
                } elseif ($key === 'HTTP_FORWARDED') {
                    $extractedIp = $this->parseForwardedHeader($value);
                    if ($extractedIp) {
                        $sources["server_{$key}"] = $extractedIp;
                    } else {
                        $sources["server_{$key}"] = $this->cleanIpString($value);
                    }
                } else {
                    $sources["server_{$key}"] = $this->cleanIpString($value);
                }
            }
        }

        // Laravel方法
        $sources['laravel_ip'] = $request->ip();
        // 检查方法是否存在，避免调用不存在的方法
        if (method_exists($request, 'getClientIp')) {
            $sources['laravel_getClientIp'] = $request->getClientIp();
        }

        return array_filter($sources); // 移除空值
    }

    /**
     * 从所有来源中找到第一个公网IP（支持IPv6）
     * 按照 ipHeaderPriority 的优先级顺序查找
     *
     * @param array $sources
     * @return string|null
     */
    private function findFirstPublicIp(array $sources): ?string
    {
        // 按照优先级顺序查找
        $priorityOrder = [];

        // 首先按 HTTP 头部优先级
        foreach ($this->ipHeaderPriority as $header) {
            $key = "header_{$header}";
            if (isset($sources[$key])) {
                $priorityOrder[] = $key;
            }
        }

        // 然后按 $_SERVER 数组优先级
        $serverPriority = [
            'server_HTTP_CF_CONNECTING_IP',
            'server_HTTP_X_REAL_IP',
            'server_HTTP_X_FORWARDED_FOR',
            'server_HTTP_X_CLUSTER_CLIENT_IP',
            'server_HTTP_CLIENT_IP',
            'server_HTTP_X_FORWARDED',
            'server_HTTP_FORWARDED_FOR',
            'server_HTTP_FORWARDED',
            'server_REMOTE_ADDR'
        ];

        foreach ($serverPriority as $key) {
            if (isset($sources[$key])) {
                $priorityOrder[] = $key;
            }
        }

        // 最后是 Laravel 方法
        if (isset($sources['laravel_ip'])) {
            $priorityOrder[] = 'laravel_ip';
        }
        if (isset($sources['laravel_getClientIp'])) {
            $priorityOrder[] = 'laravel_getClientIp';
        }

        // 按优先级顺序查找第一个公网IP
        foreach ($priorityOrder as $key) {
            $ip = $sources[$key];
            // 由于 getAllIpSources 已经处理了逗号分隔的情况，这里直接验证
            $cleanIp = $this->cleanIpString($ip);
            if ($this->isValidPublicIP($cleanIp)) {
                return $cleanIp;
            }
        }

        // 如果按优先级没找到，遍历所有来源（兜底）
        foreach ($sources as $source => $ip) {
            $cleanIp = $this->cleanIpString($ip);
            if ($this->isValidPublicIP($cleanIp)) {
                return $cleanIp;
            }
        }

        return null;
    }

    /**
     * 从代理头中找到第一个有效IP
     *
     * @param array $sources
     * @return string|null
     */
    private function findFirstValidIpFromProxy(array $sources): ?string
    {
        // 优先级顺序
        $priorityKeys = [
            'header_CF-Connecting-IP',
            'server_HTTP_CF_CONNECTING_IP',
            'header_X-Real-IP',
            'server_HTTP_X_REAL_IP',
            'header_X-Forwarded-For',
            'server_HTTP_X_FORWARDED_FOR',
            'header_True-Client-IP',
            'header_X-Client-IP',
            'server_HTTP_CLIENT_IP'
        ];

        foreach ($priorityKeys as $key) {
            if (isset($sources[$key])) {
                $ip = $sources[$key];

                // 处理逗号分隔的IP列表，取第一个有效的
                if (strpos($ip, ',') !== false) {
                    $ips = array_map('trim', explode(',', $ip));
                    foreach ($ips as $singleIp) {
                        $cleanIp = $this->cleanIpString($singleIp);
                        if ($this->isValidIp($cleanIp) && !$this->isLoopbackIP($cleanIp)) {
                            return $cleanIp;
                        }
                    }
                } else {
                    $cleanIp = $this->cleanIpString($ip);
                    if ($this->isValidIp($cleanIp) && !$this->isLoopbackIP($cleanIp)) {
                        return $cleanIp;
                    }
                }
            }
        }

        return null;
    }

    /**
     * 验证并清理IP地址
     *
     * @param string $ip
     * @return string
     */
    private function validateAndCleanIp(string $ip): string
    {
        $cleaned = $this->cleanIpString($ip);
        return $this->isValidIp($cleaned) ? $cleaned : '0.0.0.0';
    }

    /**
     * 检查IP是否有效（支持IPv4和IPv6）
     *
     * @param string $ip
     * @return bool
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * 检查是否为有效的公网IP（支持IPv4和IPv6）
     *
     * @param string $ip
     * @return bool
     */
    private function isValidPublicIP(string $ip): bool
    {
        // 基本IP格式验证
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // 使用PHP内置过滤器检查是否为公网IP（支持IPv4和IPv6）
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * 检查是否为回环地址（支持IPv4和IPv6）
     *
     * @param string $ip
     * @return bool
     */
    private function isLoopbackIP(string $ip): bool
    {
        if (!$this->isValidIp($ip)) {
            return false;
        }

        // IPv4回环地址
        if ($ip === '127.0.0.1' || $ip === '0.0.0.0') {
            return true;
        }

        // IPv6回环地址
        if ($ip === '::1' || $ip === '::') {
            return true;
        }

        return false;
    }

    /**
     * 检查是否来自可信代理（支持IPv6）
     *
     * @param string $remoteAddr
     * @return bool
     */
    private function isFromTrustedProxy(string $remoteAddr): bool
    {
        foreach ($this->trustedProxies as $proxy) {
            if ($this->ipInRange($remoteAddr, $proxy)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查IP是否在指定范围内（支持IPv4和IPv6）
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    private function ipInRange(string $ip, string $cidr): bool
    {
        if (!$this->isValidIp($ip)) {
            return false;
        }

        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr);

        // IPv4处理
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $maskLong = -1 << (32 - (int)$mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        // IPv6处理
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->ipv6InRange($ip, $subnet, (int)$mask);
        }

        return false;
    }

    /**
     * 检查IPv6地址是否在指定范围内
     *
     * @param string $ip
     * @param string $subnet
     * @param int $mask
     * @return bool
     */
    private function ipv6InRange(string $ip, string $subnet, int $mask): bool
    {
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        $bytesToCheck = intval($mask / 8);
        $bitsToCheck = $mask % 8;

        // 检查完整字节
        for ($i = 0; $i < $bytesToCheck; $i++) {
            if ($ipBinary[$i] !== $subnetBinary[$i]) {
                return false;
            }
        }

        // 检查剩余位
        if ($bitsToCheck > 0 && $bytesToCheck < 16) {
            $mask = 0xFF << (8 - $bitsToCheck);
            if ((ord($ipBinary[$bytesToCheck]) & $mask) !== (ord($subnetBinary[$bytesToCheck]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    private function handleExpiredSubscription($user, $serverService)
    {
        // 获取服务器列表（显示真实地址，稍后我们会改成假的）
        // 使用 ignoreGroupLimit=true 确保获取所有节点用于展示
        $servers = $serverService->getAvailableServers($user, true, true);

        // 如果服务器列表为空，创建一个默认的假节点
        if (empty($servers)) {
            $servers = [$this->createDefaultFakeServer()];
        }

        // 将服务器地址改成假的（无法连接）
        $servers = $this->fakeServerAddresses($servers);

        // 添加过期信息节点
        $infoServers = $this->getExpiredSubscriptionMessage($servers, $user);

        // 合并信息节点和假地址节点
        $servers = array_merge($infoServers, $servers);

        return $this->getResponseWithServers($infoServers);
    }

    private function handleNoTrafficSubscription($user, $servers)
    {
        // 如果服务器列表为空，创建一个默认的假节点
        if (empty($servers)) {
            $servers = [$this->createDefaultFakeServer()];
        }

        // 将服务器地址改成假的（无法连接）
        $servers = $this->fakeServerAddresses($servers);

        // 添加流量用完信息节点
        $infoServers = $this->getNoTrafficMessage($servers, $user);

        // 合并信息节点和假地址节点
        $servers = array_merge($infoServers, $servers);

        return $this->getResponseWithServers($infoServers);
    }

    /**
     * 创建一个默认的假服务器节点（当服务器列表为空时使用）
     *
     * @return array
     */
    private function createDefaultFakeServer(): array
    {
        return [
            'name' => 'Default Server',
            'type' => 'shadowsocks',
            'host' => '127.0.0.1',
            'port' => 80,
            'cipher' => 'aes-256-gcm',
            'password' => 'default-fake-password',
            'server' => '127.0.0.1',
            'server_port' => 80,
            'network' => 'tcp',
            'tls' => false,
            'udp' => true
        ];
    }

    /**
     * 将服务器地址改成假的（无法连接）
     * 使用 127.0.0.1:80 作为假地址
     *
     * @param array $servers
     * @return array
     */
    private function fakeServerAddresses(array $servers): array
    {
        $fakeHost = '127.0.0.1'; // 使用本地回环地址
        $fakePort = 80; // 使用HTTP端口

        foreach ($servers as &$server) {
            // 确保有必要的字段
            if (!isset($server['type'])) {
                $server['type'] = 'shadowsocks';
            }
            if (!isset($server['host']) && !isset($server['server'])) {
                $server['host'] = $fakeHost;
            }
            if (!isset($server['port'])) {
                $server['port'] = $fakePort;
            }

            // 替换所有可能的地址字段
            if (isset($server['host'])) {
                $server['host'] = $fakeHost;
            }
            if (isset($server['server'])) {
                $server['server'] = $fakeHost;
            }
            if (isset($server['address'])) {
                $server['address'] = $fakeHost;
            }

            // 替换端口（使用有效端口，但确保无法连接）
            if (isset($server['port'])) {
                $server['port'] = (int)$fakePort; // 确保是整数
            }
            if (isset($server['server_port'])) {
                $server['server_port'] = (int)$fakePort;
            }

            // 对于 Shadowsocks，确保有 cipher 和 password
            if ($server['type'] === 'shadowsocks') {
                if (!isset($server['cipher'])) {
                    $server['cipher'] = 'aes-256-gcm';
                }
                if (!isset($server['password'])) {
                    $server['password'] = 'fake-password';
                }
            }

            // 对于 VMess，确保有必要的字段
            if ($server['type'] === 'vmess') {
                if (!isset($server['network'])) {
                    $server['network'] = 'tcp';
                }
                if (!isset($server['tls'])) {
                    $server['tls'] = false;
                }
            }

            // 对于 Trojan，确保有必要的字段
            if ($server['type'] === 'trojan') {
                if (!isset($server['network'])) {
                    $server['network'] = 'tcp';
                }
                if (!isset($server['server_name'])) {
                    $server['server_name'] = '';
                }
            }

            // 在节点名称前添加过期标识
            if (isset($server['name'])) {
                $server['name'] = "❌ " . $server['name'];
            }
        }

        return $servers;
    }

    private function getExpiredSubscriptionMessage($servers, $user)
    {
        // 创建信息节点（使用 127.0.0.1:80，确保格式正确）
        // 使用 Shadowsocks 协议，因为它最简单且兼容性最好
        $baseServer = [
            'name' => 'Expired Info',
            'type' => 'shadowsocks',
            'host' => '127.0.0.1', // 使用本地回环地址
            'port' => 80, // 使用HTTP端口
            'cipher' => 'aes-256-gcm',
            'password' => 'expired-subscription-' . md5($user['id'] . time()), // 使用唯一密码
            'server' => '127.0.0.1',
            'server_port' => 80,
            'network' => 'tcp', // 默认网络类型
            'tls' => false,
            'udp' => true
        ];

        $websiteHost = $this->getHostFromUrl(config('v2board.app_url', request()->getSchemeAndHttpHost()));

        return [
            array_merge($baseServer, ['name' => "‼️【您的订阅已过期】‼️"]),
            array_merge($baseServer, ['name' => "‼️‼️【您的订阅已过期】‼️‼️"]),
            array_merge($baseServer, ['name' => "‼️‼️‼️【您的订阅已过期】‼️‼️‼️"]),
            array_merge($baseServer, ['name' => "请到「" . $websiteHost . '」重置流量或续费']),
            array_merge($baseServer, ['name' => "用户邮箱：{$user['email']}"]),
        ];
    }

    private function getNoTrafficMessage($servers, $user)
    {
        // 创建信息节点（使用 127.0.0.1:80，确保格式正确）
        // 使用 Shadowsocks 协议，因为它最简单且兼容性最好
        $baseServer = [
            'name' => 'No Traffic Info',
            'type' => 'shadowsocks',
            'host' => '127.0.0.1', // 使用本地回环地址
            'port' => 80, // 使用HTTP端口
            'cipher' => 'aes-256-gcm',
            'password' => 'no-traffic-' . md5($user['id'] . time()), // 使用唯一密码
            'server' => '127.0.0.1',
            'server_port' => 80,
            'network' => 'tcp', // 默认网络类型
            'tls' => false,
            'udp' => true
        ];

        $websiteHost = $this->getHostFromUrl(config('v2board.app_url', request()->getSchemeAndHttpHost()));

        return [
            array_merge($baseServer, ['name' => "‼️【您的流量用完了】‼️"]),
            array_merge($baseServer, ['name' => "‼️‼️【您的流量用完了】‼️‼️"]),
            array_merge($baseServer, ['name' => "‼️‼️‼️【您的流量用完了】‼️‼️‼️"]),
            array_merge($baseServer, ['name' => "请到「" . $websiteHost . '」重置流量或续费']),
            array_merge($baseServer, ['name' => "用户邮箱：{$user['email']}"]),
        ];
    }

    private function getHostFromUrl($url)
    {
        $parsedUrl = parse_url($url);
        return $parsedUrl['host'] ?? $url;
    }

    /**
     * 获取官网地址（域名或IP）
     * 优先返回IP地址，如果无法解析则返回域名
     *
     * @return string
     */
    private function getWebsiteHost(): string
    {
        $appUrl = config('v2board.app_url');
        if (empty($appUrl)) {
            // 如果配置中没有URL，尝试从请求中获取
            $appUrl = request()->getSchemeAndHttpHost();
        }

        $host = $this->getHostFromUrl($appUrl);

        // 如果已经是IP地址，直接返回
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        // 尝试解析域名获取IP地址
        $ip = gethostbyname($host);
        if ($ip && $ip !== $host) {
            // 如果解析成功且不是原域名，返回IP
            return $ip;
        }

        // 如果解析失败，返回域名（客户端会自己解析）
        return $host;
    }

    private function getResponseWithServers($servers)
    {
        // 确保服务器列表不为空
        if (empty($servers)) {
            $servers = [$this->createDefaultFakeServer()];
        }

        $flag = request()->input('flag') ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = request()->user;

        if ($flag) {
            foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                $file = 'App\\Protocols\\' . basename($file, '.php');
                $class = new $file($user, $servers);
                if (strpos($flag, $class->flag) !== false) {
                    return $class->handle();
                }
            }
        }

        $class = new General($user, $servers);
        return $class->handle();
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;

        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);

        // 添加服务器信息
	    $userMail = $user['email'];
        array_unshift($servers, array_merge($servers[0], [
            'name' => "邮箱：{$userMail}",
        ]));
        $this->addServerInfo($servers, [
            //"🔧连不上请更新订阅",
            "⚡️套餐到期：{$expiredDate}",
            "⚡️剩余流量：{$remainingTraffic}",
            "官网「" . $this->getHostFromUrl(config('v2board.app_url')) . '」',
        ]);

        //if ($resetDay) {
        //    array_unshift($servers, array_merge($servers[0], [
        //        'name' => "距离下次流量重置剩余：{$resetDay} 天",
        //    ]));
        //}
    }

    private function addServerInfo(&$servers, $info)
    {
        foreach (array_reverse($info) as $message) {
            array_unshift($servers, array_merge($servers[0], ['name' => $message]));
        }
    }

    /**
     * 发送Telegram通知
     *
     * @param array $user
     * @param string $subscriberIP
     */
    private function notifyTelegram($user, $subscriberIP, $flag)
    {
        try {
            $ipInfo = $this->getIpInfo($subscriberIP);

            Log::info("Telegram notification", [
                'user_id' => $user['id'],
                'ip' => $subscriberIP,
                'location' => $ipInfo
            ]);

            $telegramBotToken = config('v2board.telegram_bot_token', env('TELEGRAM_BOT_TOKEN', ''));
            $telegramChatId = config('v2board.telegram_chat_id', env('TELEGRAM_CHAT_ID', ''));

            // 如果未配置Telegram，跳过通知
            if (empty($telegramBotToken) || empty($telegramChatId)) {
                Log::debug('Telegram notification skipped - not configured');
                return;
            }

            $messageText = "新订阅：ID - {$user['id']}, 邮箱 - {$user['email']}, IP - {$subscriberIP}, 归属地 - {$ipInfo}, 客户端 - {$flag}";

            $this->sendTelegramMessage($telegramBotToken, $telegramChatId, $messageText);
        } catch (\Exception $e) {
            Log::error('Telegram notification failed', [
                'error' => $e->getMessage(),
                'user_id' => $user['id'] ?? 'unknown'
            ]);
        }
    }

    /**
     * 发送Telegram消息
     *
     * @param string $botToken
     * @param string $chatId
     * @param string $message
     * @return bool
     */
    private function sendTelegramMessage(string $botToken, string $chatId, string $message): bool
    {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $postData = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'V2Board/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            Log::error('Telegram cURL error', [
                'error' => curl_error($ch),
                'code' => curl_errno($ch)
            ]);
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        if ($httpCode === 200) {
            Log::info("Telegram message sent successfully", ['response' => $response]);
            return true;
        } else {
            Log::error("Telegram API error", [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }
    }

    /**
     * ===========================================
     *           IP地理位置查询API配置区域
     * ===========================================
     * 可以在这里集中管理所有IP查询相关的API
     */

    /**
     * IP地理位置查询API配置
     * 可以根据需要调整API的优先级和参数
     */
    private function getIpLocationApiConfig(): array
    {
        return [
            // 主API: ip-api.com (推荐 - 免费、准确、支持中文、支持IPv6)
            'primary' => [
                'name' => 'ip-api.com',
                'url_template' => 'http://ip-api.com/json/{ip}?fields=status,country,regionName,city&lang=zh-CN',
                'timeout' => 3,
                'method' => 'tryIpApiCom'
            ],

            // 备用API: ipapi.co (稳定的备选方案，支持IPv6)
            'backup' => [
                'name' => 'ipapi.co',
                'url_template' => 'https://ipapi.co/{ip}/json/',
                'timeout' => 4,
                'method' => 'tryIpApiCo'
            ],

            // 可选的第三备用API（如需启用，取消注释）
            'backup2' => [
                'name' => 'ipinfo.io',
                'url_template' => 'https://ipinfo.io/{ip}/json',
                'timeout' => 3,
                'method' => 'tryIpInfoIo'
            ],

            /*
            'backup3' => [
                'name' => 'taobao', // 国内用户友好，但不支持IPv6
                'url_template' => 'https://ip.taobao.com/outGetIpInfo?ip={ip}&accessKey=alibaba-inc',
                'timeout' => 4,
                'method' => 'tryTaobaoApi'
            ]
            */
        ];
    }

    /**
     * 获取IP地理位置信息（支持IPv4和IPv6）
     * 主API: ip-api.com，备用API: ipapi.co, ipinfo.io
     * 处理特殊IP地址
     *
     * @param string $ip
     * @return string
     */
    private function getIpInfo(string $ip): string
    {
        // 处理特殊IP地址
        if ($ip === '0.0.0.1') {
            return "IP获取失败";
        }

        if ($this->isLoopbackIP($ip)) {
            return "本地环回";
        }

        // 检查缓存
        $cacheKey = "ip_location:" . md5($ip);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        // 检查是否为私有IP
        if (!$this->isValidPublicIP($ip)) {
            $result = "内网IP({$ip})";
            Cache::put($cacheKey, $result, 3600);
            return $result;
        }

        // 获取API配置
        $apiConfig = $this->getIpLocationApiConfig();

        // 尝试主API
        if (isset($apiConfig['primary'])) {
            $location = $this->{$apiConfig['primary']['method']}($ip);
            if ($location) {
                Cache::put($cacheKey, $location, 3600);
                return $location;
            }
        }

        // 尝试备用API
        if (isset($apiConfig['backup'])) {
            $location = $this->{$apiConfig['backup']['method']}($ip);
            if ($location) {
                Cache::put($cacheKey, $location, 3600);
                return $location;
            }
        }

        // 如果有更多备用API，可以继续尝试
        foreach (['backup2', 'backup3'] as $backupKey) {
            if (isset($apiConfig[$backupKey])) {
                $location = $this->{$apiConfig[$backupKey]['method']}($ip);
                if ($location) {
                    Cache::put($cacheKey, $location, 3600);
                    return $location;
                }
            }
        }

        // 所有API都失败，但IP是有效的公网IP
        $fallback = "未知地区({$ip})";
        Cache::put($cacheKey, $fallback, 600);
        Log::warning("All IP location APIs failed for IP: {$ip}");
        return $fallback;
    }

    /**
     * ===========================================
     *              API实现方法区域
     * ===========================================
     * 所有具体的API调用实现都在这里
     */

    /**
     * 尝试使用ip-api.com获取IP位置（支持IPv6）
     * API文档: http://ip-api.com/docs/
     * 限制: 免费版每分钟45次请求
     *
     * @param string $ip
     * @return string|null
     */
    private function tryIpApiCom(string $ip): ?string
    {
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=status,country,regionName,city&lang=zh-CN";

            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0 (compatible; V2Board/1.0)',
                        'Accept: application/json'
                    ]
                ]
            ]);

            $startTime = microtime(true);
            $response = @file_get_contents($url, false, $context);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($response !== false) {
                $data = json_decode($response, true);
                if ($data && $data['status'] === 'success') {
                    // 优先使用regionName（省份），其次country（国家）
                    $location = trim($data['regionName'] ?? $data['country'] ?? '');

                    if (!empty($location)) {
                        Log::info("IP location found via ip-api.com", [
                            'ip' => $ip,
                            'location' => $location,
                            'response_time' => $responseTime . 'ms',
                            'ip_type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : 'IPv4'
                        ]);
                        return $location;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("ip-api.com failed", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * 尝试使用ipapi.co获取IP位置（支持IPv6）
     * API文档: https://ipapi.co/api/
     * 限制: 免费版每月1000次请求
     *
     * @param string $ip
     * @return string|null
     */
    private function tryIpApiCo(string $ip): ?string
    {
        try {
            $url = "https://ipapi.co/{$ip}/json/";

            $context = stream_context_create([
                'http' => [
                    'timeout' => 4,
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0 (compatible; V2Board/1.0)',
                        'Accept: application/json'
                    ]
                ]
            ]);

            $startTime = microtime(true);
            $response = @file_get_contents($url, false, $context);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($response !== false) {
                $data = json_decode($response, true);
                if ($data && !isset($data['error'])) {
                    // 优先使用region（省份），其次country_name（国家）
                    $location = trim($data['region'] ?? $data['country_name'] ?? '');

                    if (!empty($location)) {
                        Log::info("IP location found via ipapi.co", [
                            'ip' => $ip,
                            'location' => $location,
                            'response_time' => $responseTime . 'ms',
                            'ip_type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : 'IPv4'
                        ]);
                        return $location;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("ipapi.co failed", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * 尝试使用ipinfo.io获取IP位置（支持IPv6）
     * API文档: https://ipinfo.io/developers
     * 限制: 免费版每月50000次请求
     *
     * @param string $ip
     * @return string|null
     */
    private function tryIpInfoIo(string $ip): ?string
    {
        try {
            $url = "https://ipinfo.io/{$ip}/json";

            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0 (compatible; V2Board/1.0)',
                        'Accept: application/json'
                    ]
                ]
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response !== false) {
                $data = json_decode($response, true);
                if ($data && isset($data['region'])) {
                    $location = trim($data['region'] ?? $data['country'] ?? '');

                    if (!empty($location)) {
                        Log::info("IP location found via ipinfo.io", [
                            'ip' => $ip,
                            'location' => $location,
                            'ip_type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : 'IPv4'
                        ]);
                        return $location;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("ipinfo.io failed", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * 尝试使用淘宝IP库获取位置
     * 适合国内用户，对中国IP识别准确度高
     * 注意：淘宝API不支持IPv6
     *
     * @param string $ip
     * @return string|null
     */
    private function tryTaobaoApi(string $ip): ?string
    {
        // 淘宝API不支持IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            Log::debug("Taobao API skipped - IPv6 not supported", ['ip' => $ip]);
            return null;
        }

        try {
            $url = "https://ip.taobao.com/outGetIpInfo?ip={$ip}&accessKey=alibaba-inc";

            $context = stream_context_create([
                'http' => [
                    'timeout' => 4,
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0 (compatible; V2Board/1.0)',
                        'Accept: application/json'
                    ]
                ]
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response !== false) {
                $data = json_decode($response, true);
                if ($data && $data['code'] == 0 && isset($data['data'])) {
                    $locationData = $data['data'];
                    $parts = array_filter([
                        $locationData['region'] ?? '',
                        $locationData['city'] ?? ''
                    ]);
                    $location = implode(' ', $parts);

                    if (!empty($location)) {
                        Log::info("IP location found via Taobao API", [
                            'ip' => $ip,
                            'location' => $location
                        ]);
                        return $location;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Taobao API failed", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }
}

<?php

namespace App\Protocols;

use App\Utils\Helper;

class Shadowrocket
{
    public $flag = 'shadowrocket';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $user = $this->user;

        $uri = '';
        //display remaining traffic and expire date
        $upload = round($user['u'] / (1024*1024*1024), 2);
        $download = round($user['d'] / (1024*1024*1024), 2);
        $totalTraffic = round($user['transfer_enable'] / (1024*1024*1024), 2);
        $expiredDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d', $user['expired_at']);
        $uri .= "STATUS=🚀↑:{$upload}GB,↓:{$download}GB,TOT:{$totalTraffic}GB💡Expires:{$expiredDate}\r\n";

        foreach ($this->servers as $server) {
            // 过滤掉无效的节点（缺少必要字段的信息节点）
            if (!isset($server['type']) || !isset($server['host']) || !isset($server['port'])) {
                continue;
            }

            // 确保 host 和 port 是有效的（不能是空字符串或0）
            if (empty($server['host']) || empty($server['port']) || $server['port'] == 0) {
                continue;
            }

            try {
                if ($server['type'] === 'vmess' || ($server['type'] === 'v2node' && isset($server['protocol']) && $server['protocol'] === 'vmess')) {
                    $vmessUri = self::buildVmess($user['uuid'], $server);
                    if (!empty($vmessUri)) {
                        $uri .= $vmessUri;
                    }
                } else {
                    $builtUri = Helper::buildUri($this->user['uuid'], $server);
                    if (!empty($builtUri)) {
                        $uri .= $builtUri;
                    }
                }
            } catch (\Exception $e) {
                // 如果构建 URI 时出错，跳过该节点
                continue;
            }
        }

        // 确保至少有一些内容（即使只有 STATUS 行）
        if (empty($uri) || strlen(trim($uri)) <= strlen("STATUS=🚀↑:0GB,↓:0GB,TOT:0GB💡Expires:长期有效\r\n")) {
            // 如果没有任何节点，至少返回 STATUS 信息
            $uri = "STATUS=🚀↑:{$upload}GB,↓:{$download}GB,TOT:{$totalTraffic}GB💡Expires:{$expiredDate}\r\n";
        }

        return base64_encode($uri);
    }

    public static function buildVmess($uuid, $server)
    {
        // 确保必要字段存在
        if (!isset($server['host']) || !isset($server['port']) || !isset($server['name'])) {
            return '';
        }

        $userinfo = base64_encode('auto:' . $uuid . '@' . $server['host'] . ':' . $server['port']);
        $config = [
            'tfo' => 1,
            'remark' => $server['name'],
            'alterId' => 0
        ];

        // 安全地检查 tls 字段
        $tls = $server['tls'] ?? false;
        if ($tls) {
            $config['tls'] = 1;
            $tlsSettings = $server['tls_settings'] ?? ($server['tlsSettings'] ?? []);
            $config['allowInsecure'] = (int)($tlsSettings['allow_insecure'] ?? $tlsSettings['allowInsecure'] ?? 0);
            $config['peer'] = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
        }

        // 安全地检查 network 字段
        $network = $server['network'] ?? 'tcp';
        if ($network === 'tcp') {
            $tcpSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (isset($tcpSettings['header']['type']) && !empty($tcpSettings['header']['type']))
                $config['obfs'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['path'][0]) && !empty($tcpSettings['header']['request']['path'][0]))
                $config['path'] = $tcpSettings['header']['request']['path'][0];
            if (isset($tcpSettings['header']['request']['headers']['Host'][0]))
                $config['obfsParam'] = $tcpSettings['header']['request']['headers']['Host'][0];
        }
        if ($network === 'ws') {
            $config['obfs'] = "websocket";
            $wsSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                $config['path'] = $wsSettings['path'];
            if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                $config['obfsParam'] = $wsSettings['headers']['Host'];
            if (isset($wsSettings['security']))
                $config['method'] = $wsSettings['security'];
        }
        if ($network === 'grpc') {
            $config['obfs'] = "grpc";
            $grpcSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (isset($grpcSettings['serviceName']) && !empty($grpcSettings['serviceName']))
                $config['path'] = $grpcSettings['serviceName'];
            if (isset($tlsSettings)) {
                $config['host'] = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
            } else {
                $config['host'] = $server['host'];
            }
        }
        $query = http_build_query($config, '', '&', PHP_QUERY_RFC3986);
        $uri = "vmess://{$userinfo}?{$query}";
        $uri .= "\r\n";
        return $uri;
    }

}

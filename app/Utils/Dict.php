<?php

namespace App\Utils;

class Dict
{
    CONST EMAIL_WHITELIST_SUFFIX_DEFAULT = [
        'gmail.com',
        'qq.com',
        '163.com',
        'yahoo.com',
        'sina.com',
        '126.com',
        'outlook.com',
        'yeah.net',
        'foxmail.com'
    ];
    CONST WITHDRAW_METHOD_WHITELIST_DEFAULT = [
        '支付宝',
        'USDT',
        'Paypal'
    ];
    // 营销邮件系统默认配置
CONST MARKETING_EMAIL_DEFAULT_CONFIG = [
    'marketing_email_enable' => 1,
    'marketing_email_host' => 'smtp.qiye.aliyun.com',
    'marketing_email_port' => 465,
    'marketing_email_encryption' => 'ssl',
    'marketing_email_username' => 'kirssr@kirssr.fun',
    'marketing_email_password' => 'Lizijie123456123123',
    'marketing_email_from_address' => 'kirssr@kirssr.fun',
    'marketing_email_from_name' => 'KirSSR',
    'marketing_email_frequency' => 'daily',
    'marketing_email_send_count' => 20,
    'marketing_email_user_limit' => 2,
    'marketing_email_time_limit' => 5,
    'marketing_email_subject' => 'KirSSR欢迎您回归，特奉上8折优惠码',
    'marketing_email_brand_name' => 'KirSSR',
    'marketing_email_coupon_code' => 'WelcomeBack',
];
}

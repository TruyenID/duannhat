<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'kds-bumps' => [
            'driver' => 'daily',
            'path' => storage_path('logs/kds-bumps.log'),
            'level' => 'info',
            'days' => 90,
        ],

        'pos_auth' => [
            'driver' => 'daily',
            'path' => storage_path('logs/pos-auth.log'),
            'level' => 'debug',
            'days' => 14,
        ],

        // Money-orchestration detail. NOT part of `stack`, so nothing written
        // here alone can reach alerting — see App\Support\Logging\
        // MoneyOrchestrationLog, which is the only sanctioned way to write an
        // ERROR to this channel and mirrors it to the default one (#1244).
        //
        // 90 days, not 14. This file carries the evidence for money that a
        // customer paid and the system declined to book — a stranded PayPay
        // charge is often found at a month-end reconciliation, by which point a
        // 14-day window has already rotated the only record of it away. Volume
        // is not a concern: these are failure events, not traffic.
        // #1871 — mức KHÔNG lấy từ `LOG_LEVEL`, có chủ đích.
        //
        // Đây là log BẰNG CHỨNG VẬN HÀNH, không phải log gỡ lỗi: điều kiện ra
        // của plan-055 Gate 6 là đếm `payment_policy_option_missing` và
        // `payment_policy_alias_would_refuse` về 0 rồi mới bật cưỡng chế policy.
        // Cả hai đều là `->warning()`.
        //
        // Trong khi đó `deploy-xserver.yml` KHÔNG ghi `LOG_LEVEL` vào `.env`
        // trên server, nên mức thật là thứ đang nằm trong file đó — không đọc
        // được từ repo. Ai đó siết `LOG_LEVEL=error` để giảm nhiễu là hai dòng
        // ấy thôi được ghi, hai lệnh đếm trả `0 / 0`, điều kiện ra thoả, và cú
        // flip từ chối tiền ở mọi quầy còn chạy client cũ. Con số `0` khi đó
        // nghĩa là "chưa bao giờ ghi", không phải "không có sự kiện".
        //
        // Mặc định `info` chứ không `debug`: đo trên channel này có 36 `info`,
        // 28 `warning`, 1 `error` và **không chỗ nào** `debug` — nên hạ xuống
        // `info` không mất dòng nào, mà miễn nhiễm với một quyết định vận hành
        // ở chỗ khác.
        'payment_orchestration' => [
            'driver' => 'daily',
            'path' => storage_path('logs/payment-orchestration.log'),
            'level' => env('PAYMENT_ORCHESTRATION_LOG_LEVEL', 'info'),
            'days' => 90,
            'replace_placeholders' => true,
        ],

    ],

];

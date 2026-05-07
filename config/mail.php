<?php

return [
    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    'markdown' => [
        'theme' => 'default',
        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail Branding
    |--------------------------------------------------------------------------
    |
    | Shared branding values consumed by both Markdown mailables (themed via
    | resources/views/vendor/mail/*) and Blade mailables (rendered through the
    | <x-mail.layout> component). The `subject_prefix` is automatically applied
    | by the `App\Mail\Concerns\HasBrandedSubject` trait so every email subject
    | reads "[<App Name>] <subject>" unless an explicit override is supplied.
    | Set `MAIL_BRAND_LOGO_URL` to a publicly-reachable absolute URL to swap
    | the text branding for an inline logo.
    |
    */

    'brand' => [
        'logo_url' => env('MAIL_BRAND_LOGO_URL'),
        'subject_prefix' => env('MAIL_SUBJECT_PREFIX', '['.env('APP_NAME', 'NNTmux').'] '),
        'queue' => env('MAIL_QUEUE', 'emails'),
        'incident_queue' => env('MAIL_INCIDENT_QUEUE', 'incidents'),
    ],

];

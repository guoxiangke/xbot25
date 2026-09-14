<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'xbot' => [
        'license' => env('XBOT_LICENSE', 'JGTYsDndJVYxCk/iJgTsYh+mdM84y4K3wtBcjfw=='),
        // 'xGroup' => env('XBOT_GROUP', 'xxx@chatroom'),
        // 'endpoint' => env('XBOT_ENDPOINT', 'http://localhost/api'),
        'resource_endpoint' => env('XBOT_RESOURCE_ENDPOINT', 'http://localhost/api/resources/'),
        'redirect' => env('XBOT_REDIRECT', 'http://localhost/redirect?url='),
        // 'test_endpoint' => env('XBOT_TEST_ENDPOINT', 'http://localhost/api/wechat/send'),
        'donate' => env('WECHAT_PAY_TXT', ''),
    ],
    'wechat_ios' => [
        'base_url' => env('WECHAT_IOS_BASE_URL', 'http://100.84.180.5:19088'),
        'app_id' => env('WECHAT_IOS_APP_ID', 'wx6618f1cfc6c132f8'),
    ],
    'bark' => [
        'url' => env('BARK_NOTIFY', ''), // bark 推送 token
    ],

];

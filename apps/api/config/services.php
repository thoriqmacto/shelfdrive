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

    // ShelfDrive: Google OAuth.
    //
    // Two distinct clients so revoking Drive access does not sign the user
    // out, and so least-privilege scopes can differ per purpose.
    //
    //   - login: primary identity. openid + email + profile.
    //   - drive: additional connected accounts for scanning + uploading.
    'google' => [
        'login' => [
            'client_id' => env('GOOGLE_LOGIN_CLIENT_ID'),
            'client_secret' => env('GOOGLE_LOGIN_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_LOGIN_REDIRECT_URI'),
            'scopes' => ['openid', 'email', 'profile'],
        ],
        'drive' => [
            'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI'),
            'scopes' => array_values(array_filter(explode(' ', (string) env(
                'GOOGLE_DRIVE_SCOPES',
                'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file',
            )))),
            'default_folder_name' => env('GOOGLE_DRIVE_DEFAULT_FOLDER_NAME', 'ShelfDrive'),
        ],
    ],

];

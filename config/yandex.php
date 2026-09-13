<?php

return [
    'html_reviews' => env('YANDEX_HTML_REVIEWS', true),
    'profile_dir' => env('YANDEX_PROFILE_DIR', storage_path('app/private/yandex-browser')),
    'transport' => env('YANDEX_TRANSPORT', 'browser'),
    'chrome_binary' => env('YANDEX_CHROME_BINARY', 'google-chrome'),
    'seed_email' => env('SEED_USER_EMAIL', 'demo@example.com'),
    'seed_password' => env('SEED_USER_PASSWORD'),
    'csrf_token' => env('YANDEX_CSRF_TOKEN', ''),
    'session_id' => env('YANDEX_SESSION_ID', ''),
    'cookie' => env('YANDEX_COOKIE', ''),
    'user_agent' => env('YANDEX_USER_AGENT', 'Mozilla/5.0'),
];

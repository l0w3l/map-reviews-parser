<?php

return [
    'pause_milliseconds' => env('YANDEX_PAUSE_MILLISECONDS', 500),
    'max_pages' => env('YANDEX_MAX_PAGES', 100),
    'connect_timeout' => env('YANDEX_CONNECT_TIMEOUT', 10),
    'request_timeout' => env('YANDEX_REQUEST_TIMEOUT', 30),
    'proxy' => env('YANDEX_PROXY'),
    'seed_email' => env('SEED_USER_EMAIL', 'demo@example.com'),
    'seed_password' => env('SEED_USER_PASSWORD'),
];

#!/bin/sh
set -eu
if [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY is required. Set it in .env.production before starting.' >&2
    exit 1
fi
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
php artisan config:cache --quiet
exec "$@"

# Деплой на VPS

Нужны Docker Engine и Compose v2+. На хосте не нужны PHP, Composer или Node: они устанавливаются при сборке. Chromium в образ не устанавливается.

Сервисы: `web` (Nginx), `app` (PHP-FPM), `worker` (один процесс очереди), `db` (PostgreSQL 17), `migrate` (одноразовые миграции). Redis не нужен: очередь, сессии и кеш используют PostgreSQL. Это новая отдельная БД, существующий локальный SQLite автоматически не импортируется.

## Первый запуск

Все команды выполняются из корня репозитория.

```bash
cp docker/env.production.example .env.production
```

Заполните `.env.production`:

- `APP_URL=https://reviews.example.com` и `SANCTUM_STATEFUL_DOMAINS=reviews.example.com` — ваш домен.
- `APP_KEY=base64:...` — постоянный ключ. Получить случайную часть: `openssl rand -base64 32`. Добавьте к результату `base64:`. Сохраните ключ вместе с резервными копиями.
- `DB_PASSWORD` — отдельный длинный пароль БД. При наличии `$` используйте одинарные кавычки в env-файле. Смена значения после создания volume не меняет пароль существующего пользователя PostgreSQL.
- При необходимости `YANDEX_PROXY`. SOCKS5 с паролем поддерживается через PHP/curl. Подробнее в основном README.

```bash
docker compose --env-file .env.production build
docker compose --env-file .env.production up -d
docker compose --env-file .env.production ps -a
docker compose --env-file .env.production exec app php artisan app:create-user
```

`migrate` должен завершиться с кодом 0; только после этого запустятся PHP и worker. Пользователь не создаётся автоматически, чтобы повторный deploy не менял пароль. Миграции выполняются с `--force`, но не `migrate:fresh`.

## Домен и HTTPS

По умолчанию `web` слушает только `127.0.0.1:8080`. Настройте HTTPS на Nginx/Caddy хоста, направив запросы на этот адрес. Для Nginx в HTTPS server-блоке:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

Внутренний Nginx передаёт PHP признак HTTPS от хостового прокси. БД и PHP-FPM не публикуют порты на хост. TLS-сертификат настраивается отдельно. Если внешний reverse proxy тоже в Docker, `127.0.0.1` внутри него — другой контейнер: подключите его к сети проекта и используйте `web:80`.

Для временного HTTP smoke test на локальной машине установите `APP_URL=http://localhost:8080`, `SANCTUM_STATEFUL_DOMAINS=localhost:8080`, `SESSION_SECURE_COOKIE=false`. Для доступа с других машин потребуется осознанно изменить `BIND_ADDRESS`; для production используйте HTTPS.

## Обновление

После получения нового кода сначала соберите образы. Перед изменением схемы сделайте резервную копию БД. Простой сценарий с коротким простоем:

```bash
docker compose --env-file .env.production build
docker compose --env-file .env.production stop web app worker
docker compose --env-file .env.production up -d --force-recreate migrate app worker web
```

Worker получает SIGTERM и имеет до 660 секунд на завершение текущей задачи. `--max-time=3600` периодически завершает процесс worker, а `restart: unless-stopped` запускает его снова. Настройки Laravel кешируются при старте каждого PHP-контейнера; env не встраивается в образ. После изменения `.env.production` пересоздайте сервисы тем же `up --force-recreate`: одного `restart` недостаточно для новых переменных контейнера.

## Логи и проверка

```bash
docker compose --env-file .env.production logs -f --tail=100 worker app migrate
docker compose --env-file .env.production exec app php artisan queue:failed
curl --fail http://127.0.0.1:8080/up
```

Healthcheck `web` проверяет Laravel `/up`, но не доступность Яндекса. После deploy проверьте вход, подключение карточки и вторую страницу отзывов.

## Постоянные данные и резервные копии

Volumes `database` и `storage` переживают пересоздание контейнеров и обычный `docker compose down`. **`down -v` удаляет данные.**

```bash
docker compose --env-file .env.production exec -T db pg_dump -U reviews -d reviews -Fc > reviews.dump
# Восстановление в подготовленную БД, при остановленных app/worker/web:
docker compose --env-file .env.production exec -T db pg_restore -U reviews -d reviews --clean --if-exists < reviews.dump
```

Храните резервную копию `.env.production` и volume storage отдельно с ограниченным доступом. Образ не содержит `.env`, HAR, локальную БД или node_modules.

# Развертывание И CI/CD

Документ фиксирует рабочую стратегию развертывания.

## Рекомендованный Production Вариант

Короткое решение: production тоже использует Docker, но production-сервер не собирает приложение. Готовый image собирает CI, публикует его в registry, а сервер только скачивает image и перезапускает контейнеры.

Для первого production-развертывания:

- один VPS;
- приложение в Docker container;
- reverse proxy в Docker container;
- stateful-сервисы установлены на хосте;
- CI собирает готовый Docker image;
- production-сервер скачивает готовый image и перезапускает приложение через Docker Compose;
- production не собирает приложение из исходников.

Это означает, что `Docker` и `CI-сборка` не являются альтернативами:

```text
CI: docker build + docker push
production: docker compose pull + docker compose up -d
```

Сборка на production-сервере не используется: там не нужен Composer install из исходников, `docker build`, frontend build или dev-зависимости.

Stateful-сервисы не контейнеризируются в production.

К ним относятся:

- PostgreSQL;
- Redis, если появится;
- RabbitMQ, если появится;
- другие сервисы, которые владеют состоянием, очередями, persistent volumes или критичными данными.

Причины:

- проще администрировать как обычный системный сервис;
- проще настраивать резервные копии, systemd, логи и обновления ОС;
- меньше риск случайно потерять volume при неаккуратной работе с Docker;
- проще подключать внешние инструменты мониторинга и backup.

Контейнеры в production используются для stateless-части:

- Symfony application;
- reverse proxy;
- worker-контейнеры приложения, если они не владеют состоянием;
- cron/scheduler-контейнеры приложения, если они только запускают команды.

## Dev И Production

Dev и production используют Docker по-разному:

```text
dev:
  - приложение можно запускать из рабочей директории;
  - PostgreSQL запускается в Docker Compose;
  - допускаются bind mounts исходного кода;
  - image может собираться локально для проверки.

production:
  - приложение запускается из готового immutable image;
  - PostgreSQL установлен на хосте как системный сервис;
  - bind mounts исходного кода не используются;
  - image собирается только в CI.
```

Причина различия: dev должен быть удобен для разработки и проверки миграций, а production должен быть воспроизводимым и не зависеть от сборочного окружения на сервере.

## Production Compose

`compose.prod.yaml` должен описывать только контейнерные части:

- application image;
- CLI service на том же application image;
- reverse proxy, например Caddy;
- возможно, отдельный worker/cron container позже.

PostgreSQL, Redis, RabbitMQ и другие stateful-сервисы в `compose.prod.yaml` не добавляются. Приложение подключается к ним на хосте через DSN/URL из `.env.prod.local` или другого production secret-хранилища.

Production defaults хранятся в `.env.prod` и коммитятся в Git. Этот файл не должен содержать реальные секреты, пароли, токены и host-specific значения. Реальные значения задаются в `.env.prod.local`; этот файл игнорируется Git и передается Docker Compose после `.env.prod`, чтобы переопределить безопасные дефолты:

```bash
docker compose --env-file .env.prod --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml config
```

Для подключения контейнеров к host-сервисам production compose задает `host.docker.internal:host-gateway`; поэтому `DATABASE_URL` в `.env.prod.local` обычно использует `host.docker.internal` как адрес PostgreSQL на хосте. Если PostgreSQL расположен на отдельном private IP, нужно заменить host в DSN на этот адрес.

Принцип Docker Compose для production: использовать готовый image и убрать bind mount исходного кода. Docker прямо указывает, что production compose обычно отличается от dev compose: код должен быть внутри контейнера, порты и переменные окружения отличаются, нужна restart policy.

В production compose есть два application-сервиса на одном immutable image:

- `php-web` - PHP-FPM runtime для web-запросов через nginx;
- `php-cli` - idle CLI container для миграций, bootstrap-команд, отправки квитанций и будущих фоновых задач.

В отличие от dev, production `php-cli` не использует bind mount исходников. Код, `vendor/` и compiled assets находятся внутри Docker image, который собран в CI.

## Border-Web И TLS Termination

Первый production/pilot вариант предполагает, что публичный TLS завершается на внешнем border-web сервере, а не внутри `compose.prod.yaml`.

Ответственность border-web:

- принимать публичные `80/443`;
- держать TLS-сертификаты;
- делать redirect `80 -> 443`;
- проксировать запросы на application nginx, например `http://127.0.0.1:8080`;
- выставлять `Host`, `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Forwarded-Port`;
- перезаписывать входящие `X-Forwarded-*` от внешних клиентов, а не прокидывать их как есть;
- ограничивать размер request body для загрузок PDF;
- настраивать HSTS после проверки домена и TLS.

Ответственность application compose:

- не публиковать application port на `0.0.0.0`;
- слушать только `127.0.0.1:${HTTP_PORT}` или private interface;
- доверять forwarded headers только от явно заданного border-web адреса;
- генерировать абсолютные URL через публичный `DEFAULT_URI`.

Production env для режима behind proxy:

```dotenv
HTTP_PORT=8080
DEFAULT_URI=https://komuchet.example.ru
SYMFONY_TRUSTED_HOSTS='^komuchet\.example\.ru$'
SYMFONY_TRUSTED_PROXIES=127.0.0.1
SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-host,x-forwarded-proto,x-forwarded-port
```

Если border-web находится не на том же хосте, вместо `127.0.0.1` нужно указать его private IP или CIDR. Широкое значение вроде `private_ranges` допустимо только если вся private network контролируется и туда не может попасть внешний клиент напрямую.

`DEFAULT_URI` должен быть именно публичным HTTPS URL. Он используется Symfony router в CLI-контекстах, например при генерации ссылок из фоновых команд. Secure cookies работают корректно только если Symfony доверяет `X-Forwarded-Proto: https` от border-web.

## CI И Registry

Production Docker images собираются в GitHub Actions и публикуются в GitHub Container Registry.

Workflow: `.github/workflows/docker-images.yml`.

Триггеры:

- push в `master`;
- ручной запуск через `workflow_dispatch`.

Права workflow:

- `contents: read`;
- `packages: write`.

Публикуемые images:

- `ghcr.io/sigalx/komuchet/php:sha-<commit>`;
- `ghcr.io/sigalx/komuchet/php:latest`;
- `ghcr.io/sigalx/komuchet/nginx:sha-<commit>`;
- `ghcr.io/sigalx/komuchet/nginx:latest`.

PHP image собирается из `docker/php/Dockerfile`, target `prod`. Внутри image устанавливаются production Composer-зависимости, компилируются Symfony Importmap/AssetMapper assets и подготавливается `public/assets`.

Nginx image собирается из `docker/nginx/Dockerfile`, target `prod`. Он использует только что опубликованный PHP image как build-stage и копирует из него `/var/www/html/public`, поэтому nginx получает ту же скомпилированную статику, что и приложение.

`.env.prod` по умолчанию указывает на `latest` images. Для deploy конкретной сборки оба image переопределяются в `.env.prod.local`:

```dotenv
APP_IMAGE=ghcr.io/sigalx/komuchet/php:sha-<commit>
NGINX_IMAGE=ghcr.io/sigalx/komuchet/nginx:sha-<commit>
```

## Deployment Flow

Базовый flow:

```text
git push
  -> GitHub Actions: docker build php image
  -> GitHub Actions: docker push php image to GHCR
  -> GitHub Actions: docker build nginx image from php image public assets
  -> GitHub Actions: docker push nginx image to GHCR
  -> server: docker compose pull
  -> server: docker compose up -d
  -> server: docker compose exec php-cli php bin/console doctrine:migrations:migrate --no-interaction
```

Минимальный ручной production deploy после публикации image:

```bash
docker compose --env-file .env.prod --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml pull
docker compose --env-file .env.prod --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml up -d
docker compose --env-file .env.prod --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml exec php-cli php bin/console doctrine:migrations:migrate --no-interaction
```

## Pilot/Prod Checklist

Минимальный checklist первого запуска:

- домен направлен на border-web;
- TLS настроен на border-web, приложение доступно только через него;
- `HTTP_PORT` application compose не опубликован наружу;
- `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET` задан реальным секретом;
- `DEFAULT_URI` задан публичным HTTPS URL;
- `SYMFONY_TRUSTED_HOSTS`, `SYMFONY_TRUSTED_PROXIES`, `SYMFONY_TRUSTED_HEADERS` соответствуют border-web схеме;
- `DATABASE_URL` указывает на PostgreSQL на хосте или другой approved stateful-сервер;
- production PostgreSQL backup настроен и restore проверен;
- email delivery provider выбран и проверен через HTTPS API на `443`; SMTP/relay допустим только как fallback, если он реально доступен и проверен;
- `MAILER_FROM_EMAIL` и `MAILER_FROM_NAME` заданы;
- SPF/DKIM/DMARC настроены для домена отправителя;
- `php-cli` запущен и используется для миграций, bootstrap и scheduled jobs;
- cron/systemd timer для `app:account-statement-deliveries:send` настроен, если email-доставка включена;
- первое хозяйство создано через `app:workspace:bootstrap`;
- первый глобальный администратор создан через `app:user:create-admin`;
- платежные реквизиты хозяйства внесены через админку;
- `/healthz`, `/login`, `/admin` проверены через публичный HTTPS домен.

## Первичный Bootstrap Pilot/Prod

После первого deploy и применения миграций нужно создать первое хозяйство и первого глобального администратора.

Для dev/pilot через Makefile:

```bash
make bootstrap-workspace ARGS='main "Название хозяйства" --association-name="Название для квитанций" --timezone=Europe/Moscow --invoice-generation-day=5 --reading-freshness-window-days=15'
make create-admin ARGS='admin@example.test'
```

`make create-admin` без `--password` интерактивно спросит пароль. Для production это предпочтительнее, чем передавать пароль аргументом командной строки.

Для production compose та же логика запускается напрямую внутри application container:

```bash
docker compose --env-file .env.prod --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml exec php-cli php bin/console app:workspace:bootstrap main "Название хозяйства" \
  --association-name="Название для квитанций" \
  --timezone=Europe/Moscow \
  --invoice-generation-day=5 \
  --reading-freshness-window-days=15

docker compose --env-file .env.prod --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml exec php-cli php bin/console app:user:create-admin admin@example.test
```

Команда `app:workspace:bootstrap` идемпотентна для одного `code`: если хозяйство уже есть, она обновит название, описание, timezone и настройки биллинга. Команда `app:user:create-admin` по умолчанию падает, если активный email уже занят; для автоматизированного smoke можно использовать `--if-exists=skip`.

Non-interactive пароль допустим только для dev/smoke-сценариев:

```bash
make create-admin ARGS='admin@example.test --password=temporary-password-123 --if-exists=skip'
```

После bootstrap нужно войти в `/login`, проверить доступ к `/admin`, создать или проверить платежные реквизиты хозяйства и только после этого переходить к загрузке/созданию рабочих данных.

## Регулярные CLI-Задачи

На первом pilot-этапе достаточно запускать регулярные команды с хоста через cron или systemd timer. Для отправки queued-квитанций:

```bash
docker compose --env-file .env.prod --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml exec -T php-cli php bin/console app:account-statement-deliveries:send --limit=50
```

Scheduler/worker-контейнер должен использовать тот же application image и не владеть состоянием.

## Backup

PostgreSQL backup:

- `pg_dump -Fc`;
- ежедневный backup;
- хранение вне production-сервера;
- retention: 7 daily, 4 weekly, 12 monthly;
- регулярный тест восстановления через `pg_restore`.

Backup без проверенного restore не считается надежным.

## Источники

- GitHub Actions: публикация Docker images: https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images
- GitHub trade controls: https://docs.github.com/en/site-policy/other-site-policies/github-and-trade-controls
- Docker Compose production: https://docs.docker.com/compose/how-tos/production/
- PostgreSQL pg_dump: https://www.postgresql.org/docs/current/app-pgdump.html
- PostgreSQL pg_restore: https://www.postgresql.org/docs/current/app-pgrestore.html

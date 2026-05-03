# Технологический Стек

Документ фиксирует базовый технический выбор проекта.

## Основной Стек

- Язык: PHP.
- Framework: Symfony.
- База данных: PostgreSQL.
- Frontend: серверные HTML-страницы на Symfony/Twig, без отдельного SPA.

Это сознательно классический серверный стек. На первом этапе проекту важнее надежная доменная модель, понятная административная часть и воспроизводимый расчет, чем сложная распределенная архитектура.

## Версии

Политика: брать актуальные стабильные версии на момент создания проекта и не начинать новую разработку на устаревших ветках без отдельной причины.

Проверено 2026-05-08:

- PHP CLI в текущем окружении: 8.5.5.
- Symfony components: 8.0.x.
- Doctrine ORM: 3.6.x.
- DoctrineBundle: 3.2.x.
- Doctrine Migrations Bundle: 4.0.x.
- MakerBundle: 1.67.x.
- PostgreSQL target: 18.x.

Точные patch-версии нужно перепроверять перед обновлением зависимостей.

## Текущий Состав Symfony

Проект инициализирован в корне репозитория. Базовый набор установлен:

- Symfony FrameworkBundle.
- Doctrine ORM и Doctrine Migrations.
- Symfony Security.
- Symfony Validator.
- Symfony Form для серверного ввода.
- Twig для серверных HTML-страниц.
- Symfony UID.
- MakerBundle для генерации сущностей и других Symfony-артефактов.
- Symfony Security form login для MVP-входа по email и паролю.
- Symfony AssetMapper/importmap для локальных frontend assets без отдельного Node-сборщика.
- Bootstrap 5 для базовой верстки серверных страниц и админки.
- `endroid/qr-code` для генерации QR-кодов оплаты в сохраненных квитанциях.
- PHP `ext-gd` для PNG-рендера QR-кодов, которые затем встраиваются в HTML/PDF как data URI.
- `dompdf/dompdf` для генерации PDF сохраненных квитанций из snapshot-HTML.
- Symfony Mailer для доставки сохраненных PDF-квитанций.

## PostgreSQL

PostgreSQL используется как основное хранилище:

- абоненты и доступы;
- участки;
- электросчетчики;
- показания;
- тарифы;
- социальные нормы;
- начисления;
- оплаты;
- аудит.

Для финансово значимых сумм использовать точные числовые типы, а не floating point.

Подробные правила схемы зафиксированы в [database-design.md](database-design.md).

## Локальное Окружение

Docker Compose для локальной разработки добавлен. Dev-СУБД запускается в Docker, а не устанавливается на хост. Docker Desktop WSL integration проверен, dev-контур поднимается через `make up-build`.

Минимальный состав локального окружения:

- PostgreSQL;
- `php-web`: PHP-FPM runtime с read-only mount исходников;
- `php-cli`: Symfony console, миграции, тесты и генераторы с writable mount исходников;
- `composer`: отдельный tool-сервис для Composer-команд с writable mount исходников;
- `e2e`: одноразовый Playwright tool-сервис для smoke-тестов UI;
- nginx reverse proxy.
- AssetMapper dev server для hashed `/assets/...` ресурсов в dev-режиме.

Файлы:

- `compose.yaml` - общая база сервисов;
- `compose.dev.yaml` - dev override с bind mounts и PostgreSQL 18;
- `compose.prod.yaml` - production override с готовыми image без bind mount исходников;
- `Makefile` - удобные команды `make init`, `make up`, `make migrate`, `make bootstrap-workspace`, `make create-admin ARGS="..."`, `make console ARGS="..."`;
- `docker/php/Dockerfile` - dev/prod targets для PHP image;
- `docker/nginx/` - dev/prod nginx конфигурации.

Production PHP image выполняет `importmap:install` и `asset-map:compile`, потому что `assets/vendor/` и `public/assets/` не хранятся в Git.

Значения по умолчанию:

- HTTP: `127.0.0.1:8080`;
- PostgreSQL на хосте: `127.0.0.1:5433`;
- PostgreSQL внутри Compose: `database:5432`;
- dev database/user/password: `snt`/`snt`/`snt`.

Для `postgres:18` dev-volume монтируется в `/var/lib/postgresql`. Не менять на `/var/lib/postgresql/data`: PostgreSQL 18 Docker image использует major-version-specific data directories и явно отвергает старый mount path.

Миграции проверены на PostgreSQL 18.3 в Docker. Схема хранится в одной baseline-миграции `Version20260518010000`:

- `make migrate` успешно применяет baseline-миграцию;
- создано 44 таблицы приложения;
- создано расширение `btree_gist`;
- созданы PostgreSQL enum-типы, индексы и пользовательские triggers.

Fresh install smoke для dev-среды запускается отдельным Compose-проектом, чтобы не трогать основной локальный контур:

- команда: `make fresh-install-smoke`;
- Compose project: `snt_fresh_smoke`;
- HTTP: `127.0.0.1:18080`;
- PostgreSQL на хосте: `127.0.0.1:15433`;
- проверяет старт с пустых volumes, миграции, bootstrap хозяйства, создание администратора, `/healthz`, `/login`, PHPUnit и Playwright smoke;
- очистка отдельного smoke-контура: `make fresh-install-smoke-down`.

`doctrine:schema:validate` подтверждает mapping, но database sync ожидаемо сообщает расхождение: ручные миграции используют PostgreSQL enum, DB defaults, partial indexes, workspace-aware composite FK и triggers, которые Doctrine ORM не может полностью вывести из entity mapping. Источник истины для схемы - ручные migrations и `docs/database-schema.md`, а не `doctrine:schema:update`.

Реальная отправка email в dev не требуется. По умолчанию используется `MAILER_DSN=null://null`, поэтому обработчики доставки можно прогонять без внешнего SMTP.

Production предполагает работу behind border-web: TLS завершается на внешнем reverse proxy, а application nginx слушает только localhost или private interface. Для этого production env должен задавать `DEFAULT_URI`, `SYMFONY_TRUSTED_HOSTS`, `SYMFONY_TRUSTED_PROXIES` и `SYMFONY_TRUSTED_HEADERS`.

## Production Deployment

Подробная стратегия production-развертывания описана в [deployment.md](deployment.md).

Базовый подход:

- GitHub Actions workflow `.github/workflows/docker-images.yml` собирает production Docker images и публикует их в GitHub Container Registry;
- production-сервер только скачивает готовый image и запускает его;
- production не собирает Composer-зависимости и assets из исходников на сервере.

Для первого production-развертывания допустим single-host Docker Compose, но Compose должен использовать готовый image из registry, а не `build: .` на сервере.

Практическая схема:

- `compose.yaml` - общая база сервисов;
- `compose.dev.yaml` - локальные dev-настройки, не для production;
- `compose.prod.yaml` - production-сервисы с готовыми image приложения, `php-cli` для console-команд и reverse proxy;
- `.env.prod` - безопасные production defaults без секретов, под Git;
- `.env.prod.local` или секреты окружения - реальные production-значения вне Git.

Stateful-сервисы в production устанавливаются прямо на хосте, без контейнеризации: PostgreSQL, Redis, RabbitMQ и другие сервисы, которые владеют состоянием.

Такой подход проще обычного VPS-деплоя из исходников и оставляет production-сервер без сборочного окружения.

## Архитектурные Ориентиры

- Доменная логика расчета не должна жить в контроллерах.
- Расчет начислений должен быть покрыт тестами.
- Изменения показаний, тарифов, норм и оплат должны быть аудируемыми.
- Баланс участка остается вычисляемым значением, пока не принято решение о кэшировании.
- UI абонента и администратора должны использовать одну расчетную модель.
- Аутентификация должна опираться на единую сущность `User` с ролями.
- Вход через внешних провайдеров должен добавляться через отдельные связанные идентичности, а не через дублирование пользователей.

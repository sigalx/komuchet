# Журнал Решений

Документ фиксирует принятые проектные решения. Если решение позже меняется, нужно добавить новую запись, а не молча переписать историю.

## 2026-05-08: Каркас Persistence-Модели MVP

- Symfony-проект создан в корне репозитория.
- Базовая PostgreSQL-схема MVP перенесена в Doctrine-сущности и ручные Doctrine migrations.
- Миграции намеренно написаны вручную для PostgreSQL-специфики: enum-типы, partial indexes, composite FK, exclusion constraints и triggers.
- Реализованы persistence-блоки: workspaces/auth, subscribers/accounts/groups, electricity meters/readings, tariff model, consumption band rules, billing/accruals, payments, billing settings, audit logs.
- Dev-СУБД запускается через Docker Compose, а не устанавливается на хост. Миграции применены к PostgreSQL 18.3 в Docker; проверены PHP lint, Doctrine mapping validation, container lint и `git diff --check`.
- Для `audit_logs.changed_fields text[]` добавлен custom DBAL type `text_array`.

## 2026-05-08: Docker Dev-Окружение

- Добавлен Docker Compose каркас: `compose.yaml` как общая база, `compose.dev.yaml` для разработки и `compose.prod.yaml` для production override.
- Dev-контур включает PHP-FPM, nginx и PostgreSQL 18 в контейнере.
- Production override не содержит PostgreSQL, Redis, RabbitMQ или другие stateful-сервисы.
- PHP image имеет `dev` и `prod` targets; production image должен собираться в CI и публиковаться в registry.
- Управляющие команды вынесены в `Makefile`: `make init`, `make up`, `make migrate`, `make console ARGS="..."`.
- После включения Docker Desktop WSL integration `docker compose config` проверен, dev-контур реально поднят.
- Для `postgres:18` volume монтируется в `/var/lib/postgresql`, а не в `/var/lib/postgresql/data`, потому что PostgreSQL 18 image использует major-version-specific data directories.
- `Zend OPcache` уже есть в `php:8.5-fpm-bookworm`; повторно собирать его через `docker-php-ext-install opcache` не нужно.

## 2026-05-08: Проверка Миграций На PostgreSQL

- `make migrate` успешно применил 9 миграций к чистой PostgreSQL 18.3 в Docker.
- Итоговая версия: `DoctrineMigrations\Version20260507214944`.
- Созданы 35 таблиц, PostgreSQL enum-типы, расширение `btree_gist` и 21 пользовательский trigger.
- `doctrine:schema:validate` подтверждает корректность mapping, но database sync ожидаемо не сходится с ORM metadata.
- Причина расхождения: ручные миграции намеренно используют PostgreSQL enum, DB-side defaults, triggers, partial indexes и workspace-aware composite foreign keys. Doctrine `schema:update` не должен использоваться как источник истины для этой схемы.

## 2026-05-08: Bootstrap CLI

- Добавлена команда `app:workspace:bootstrap` для создания или обновления workspace и связанных `billing_settings`.
- Добавлена команда `app:user:create-admin` для создания первого approved глобального администратора с verified email, текущим password credential, password history и активными `admin_granted_at/admin_revoked_at`.
- В `Makefile` добавлены ярлыки `make bootstrap-workspace` и `make create-admin ARGS="..."`.
- `user_password_history` вставляется через DBAL, а не через ORM persist entity: таблица имеет согласованный composite primary key `(user_uuid, changed_at)`, где `changed_at` является `timestamptz`, и Doctrine ORM не должен быть источником истины для такой insert-операции.

## 2026-05-08: Auth Foundation

- Login/logout сгенерированы через Symfony Maker `make:security:form-login`.
- Защищенный корневой dashboard сгенерирован через Symfony Maker `make:controller`.
- Symfony Security использует custom provider `App\Security\UserEmailProvider`, потому что email хранится в `user_email_identities`, а не в `users`.
- Login выполняется по активному verified email; session refresh перезагружает `User` по `uuid`.
- Глобальный `ROLE_ADMIN` выводится из `users.admin_granted_at is not null and admin_revoked_at is null`.
- Проверка жизненного цикла пользователя вынесена в `App\Security\UserAccountChecker`: `approved_at`, `blocked_at`, `deleted_at`.
- Все маршруты, кроме `/login` и `/healthz`, требуют аутентификации.

## 2026-05-08: Admin Shell And Bootstrap

- Bootstrap подключен через Symfony AssetMapper/importmap, без npm/webpack и без CDN-зависимости во время работы приложения.
- `assets/vendor/` и `public/assets/` не коммитятся; vendor assets восстанавливаются командой `importmap:install`, которая добавлена в Composer auto-scripts рецептом Symfony.
- Importmap polyfill отключен, чтобы HTML не ссылался на внешний `ga.jspm.io`.
- `AdminController` сгенерирован через Symfony Maker `make:controller`.
- `/admin` является первым каркасом админки: левое меню разделов, верхняя панель пользователя, рабочий выбор текущего workspace и кнопка logout.
- `/admin` защищен правом `WORKSPACE_ACCESS`.
- Текущее хозяйство определяется сервисом `WorkspaceContext`: выбранный workspace хранится в Symfony session, а при отсутствии или недоступности выбора используется первое доступное пользователю хозяйство.

## 2026-05-12: Naming

- Продукт называется "КомУчёт".
- Пользовательский термин для `Workspace` - "хозяйство".
- Технические имена в коде и БД остаются `Workspace`, `workspace_uuid`, `workspace_user_role_assignments` и т.п.
- "СНТ" используется только как конкретный пример хозяйства или в правовом/историческом контексте, а не как название системы.

## 2026-05-08: PostgreSQL Timestamptz Doctrine Type

- Стандартный Doctrine DBAL `datetimetz_immutable` переопределен классом `App\Doctrine\Type\PostgreSQLDateTimeTzImmutableType`.
- Причина: PostgreSQL возвращает `timestamptz` с микросекундами и timezone offset вида `2026-05-08 19:22:46.434715+00`, а стандартный DBAL parser ожидает более узкий формат.
- Значение остается PostgreSQL `timestamptz`; меняется только PHP-конвертация значения в `DateTimeImmutable`.

## 2026-05-11: Timezone Хранится На Уровне Хозяйства

- IANA timezone хранится в `workspaces.timezone`, а не в `billing_settings`.
- Причина: timezone описывает локальный контур эксплуатации хозяйства целиком, а не только правила биллинга.
- Абсолютные моменты времени (`timestamptz`) отображаются через timezone текущего хозяйства. Бизнес-даты без времени (`date`) не конвертируются, чтобы не сдвигать расчетные периоды и даты оплат.
- В UI настроек биллинга timezone выбирается из предопределенного списка основных российских зон от Калининграда до Чукотки.

## 2026-05-08: Admin Accounts Section

- Первый реальный раздел админки - `Участки` (`/admin/accounts`).
- CRUD-заготовка сгенерирована через Symfony Maker `make:crud Account`, затем адаптирована под доменную модель.
- Форма участка редактирует только `number` и `notes`; `workspace` и audit-поля не показываются в форме.
- Создание участка привязывает его к текущему workspace из `WorkspaceContext`.
- Список показывает только активные участки текущего workspace: `deleted_at is null`.
- Удаление участка выполняется через soft-delete `Account::delete()`, без physical remove из БД.
- На карточке участка реализована выдача и отзыв активного доступа абонентов через `subscriber_account_accesses`.

## 2026-05-09: Admin Subscribers Section

- Раздел админки `Абоненты` доступен по `/admin/subscribers`.
- CRUD-заготовка сгенерирована через Symfony Maker `make:crud Subscriber`, затем адаптирована под доменную модель.
- Форма абонента редактирует только ФИО, контактный email, контактный телефон и заметки.
- `workspace`, `user` и audit-поля не показываются в форме; привязка абонента к учетной записи и участкам будет отдельным сценарием.
- Создание абонента привязывает его к текущему workspace из `WorkspaceContext`.
- Список показывает только активных абонентов текущего workspace: `deleted_at is null`.
- Удаление абонента выполняется через soft-delete `Subscriber::delete()`, без physical remove из БД.
- На карточке абонента реализована выдача и отзыв активного доступа к участкам через `subscriber_account_accesses`.
- Связкой `subscriber_account_accesses` можно управлять с обеих сторон: с карточки абонента и с карточки участка.
- `subscriber_account_accesses.granted_at` остается частью PostgreSQL primary key, но не помечается как ORM `Id`: Doctrine ORM не поддерживает `DateTimeImmutable` как часть identity key. Для MVP ORM работает только с активной строкой `(workspace, subscriber, account)`, а полная историческая уникальность остается DB-ограничением.

## 2026-05-09: Admin Account Groups Section

- Раздел админки `Группы участков` доступен по `/admin/account-groups`.
- CRUD-заготовка сгенерирована через Symfony Maker `make:crud AccountGroup`, затем адаптирована под доменную модель.
- Форма группы редактирует только `code`, `name` и `description`; `workspace` и audit-поля не показываются в форме.
- Создание группы привязывает ее к текущему workspace из `WorkspaceContext`.
- Список показывает только активные группы текущего workspace: `deleted_at is null`.
- Удаление группы выполняется через soft-delete `AccountGroup::delete()`, без physical remove из БД.
- На карточке группы реализовано добавление и закрытие активного членства участков через `account_group_members`.
- `account_group_members.valid_from` остается частью PostgreSQL primary key и ORM identity. В PHP-модели дата наружу отдается как `DateTimeImmutable`, но внутри Doctrine identity хранится строкой `YYYY-MM-DD`, чтобы обновления членства не затрагивали исторические строки того же участка и группы.
- На уровне PostgreSQL добавлен partial unique index `ux_account_group_members_active`, чтобы запретить два активных членства одного участка в одной группе.

## 2026-05-09: Searchable Admin Selects

- Для длинных списков в админке используется Tom Select: он не требует jQuery и подключен через Symfony Importmap/AssetMapper.
- Поисковый select включен для привязки участка к абоненту, абонента к участку, участка к группе, а также выбора участка и тарифных зон при создании электросчетчика.
- Используется локальный список options в HTML: несколько сотен записей допустимы для текущего объема данных.

## 2026-05-09: Admin Date Picker

- Native HTML5 `input[type=date]` не используется в админских формах, потому что браузер отображает формат даты по своей локали и может показывать американский `mm/dd/yyyy`.
- Для дат используется flatpickr с русской локалью, `disableMobile=true` и форматом `дд.мм.гггг`.
- Symfony `DateType` настроен с `html5=false` и `format=dd.MM.yyyy`, чтобы серверный парсинг совпадал с форматом виджета.

## 2026-05-09: Admin Electricity Meters Section

- Раздел админки `Электросчетчики` доступен по `/admin/electricity-meters`.
- CRUD-заготовка сгенерирована через Symfony Maker `make:crud ElectricityMeter`, затем адаптирована под доменную модель и перенесена в admin namespace routes/templates.
- Поле `model` добавлено через Symfony Maker `make:entity ElectricityMeter` как опциональное текстовое поле.
- При создании счетчика администратор выбирает участок и тарифные зоны; строки `electricity_meter_registers` создаются вместе со счетчиком.
- Участок и регистры после создания не редактируются через CRUD, потому что регистры immutable, а привязка счетчика к участку влияет на историю показаний и начислений.
- Редактируемые поля счетчика: серийный номер, модель, даты установки/снятия/поверки, дата окончания поверки и заметки.
- Повторный активный счетчик на тот же участок запрещается на уровне формы и PostgreSQL partial unique index `ux_electricity_meters_one_active_per_account`.
- Удаление счетчика выполняется через soft-delete `ElectricityMeter::delete()`, без physical remove из БД; регистры остаются в истории.

## 2026-05-09: Admin Electricity Tariff Zones Section

- Раздел админки `Тарифные зоны` доступен по `/admin/electricity-tariff-zones`.
- CRUD-заготовка сгенерирована через Symfony Maker `make:crud ElectricityTariffZone`, затем адаптирована под admin routes/templates.
- Форма тарифной зоны редактирует только `code`, `name`, `description` и `sort_order`; `workspace` и audit-поля не показываются.
- Создание тарифной зоны привязывает ее к текущему workspace из `WorkspaceContext`.
- Список показывает только активные тарифные зоны текущего workspace: `deleted_at is null`, сортировка идет по `sort_order`, затем по `name`.
- Активный `code` уникален внутри workspace; проверка есть в форме, а окончательная гарантия остается за PostgreSQL partial unique index `ux_electricity_tariff_zones_code_active`.
- Удаление тарифной зоны выполняется через soft-delete `ElectricityTariffZone::delete()`, без physical remove из БД.

## 2026-05-09: Admin Electricity Consumption Bands Section

- Раздел админки `Диапазоны потребления` доступен по `/admin/electricity-consumption-bands`.
- CRUD-заготовка сгенерирована через Symfony Maker `make:crud ElectricityConsumptionBand --with-tests`, затем адаптирована под admin routes/templates.
- Форма диапазона редактирует только `code`, `name`, `description` и `sort_order`; `workspace` и audit-поля не показываются.
- Создание диапазона привязывает его к текущему workspace из `WorkspaceContext`.
- Список показывает только активные диапазоны текущего workspace: `deleted_at is null`, сортировка идет по `sort_order`, затем по `name`.
- Активный `code` уникален внутри workspace; проверка есть в форме, а окончательная гарантия остается за PostgreSQL partial unique index `ux_electricity_consumption_bands_code_active`.
- Удаление диапазона выполняется через soft-delete `ElectricityConsumptionBand::delete()`, без physical remove из БД.

## 2026-05-09: Admin Electricity Consumption Band Rules Section

- Раздел админки `Правила диапазонов` доступен по `/admin/electricity-consumption-band-rules`.
- CRUD-заготовка сгенерирована через Symfony Maker `make:crud ElectricityConsumptionBandRule --with-tests`, форма диапазона - через `make:form ElectricityConsumptionBandRuleRangeType`.
- Правило задает тарифный профиль, месяц, период действия, способ распределения потребления по диапазонам, приоритет, документ-основание и заметки.
- При создании правила автоматически создается `electricity_consumption_band_rule_all_scopes` в режиме `include`; для MVP правило применяется ко всем участкам.
- Group/account scopes остаются заложенными в схеме, но UI для них не вводится на этом шаге.
- На карточке правила администратор добавляет или обновляет строки `electricity_consumption_band_rule_ranges`; пустая верхняя граница означает бесконечность.
- Приложение предварительно проверяет пересечение диапазонов правила, а окончательная гарантия остается за PostgreSQL exclusion constraint `ex_electricity_consumption_band_rule_ranges_no_overlap`.
- Для одного тарифного профиля, месяца, периода действия и приоритета не допускаются пересекающиеся активные правила, чтобы не получить неоднозначный выбор правила при расчете.
- Удаление правила выполняется через soft-delete `ElectricityConsumptionBandRule::delete()`, без physical remove из БД.

## 2026-05-09: Admin Electricity Tariff Profiles Section

- Раздел админки `Тарифные профили` доступен по `/admin/electricity-tariff-profiles`.
- CRUD-заготовка сгенерирована через Symfony Maker `make:crud ElectricityTariffProfile`, затем адаптирована под admin routes/templates.
- Форма тарифного профиля редактирует только `code`, `name` и `description`; `workspace` и audit-поля не показываются.
- Создание тарифного профиля привязывает его к текущему workspace из `WorkspaceContext`.
- Список показывает только активные тарифные профили текущего workspace: `deleted_at is null`.
- Активный `code` уникален внутри workspace; проверка есть в форме, а окончательная гарантия остается за PostgreSQL partial unique index `ux_electricity_tariff_profiles_code_active`.
- Удаление тарифного профиля выполняется через soft-delete `ElectricityTariffProfile::delete()`, без physical remove из БД.

## 2026-05-09: Admin Account Tariff Profile Assignments

- Назначение тарифного профиля участку выполняется из карточки участка `/admin/accounts/{uuid}`.
- Form-класс `AccountElectricityTariffProfileAssignType` сгенерирован через Symfony Maker `make:form`, затем адаптирован под выбор профиля, дату начала и заметку.
- На карточке участка показывается история `account_electricity_tariff_profile_assignments`; строка без `valid_to` считается текущей открытой.
- Новое назначение другого профиля закрывает предыдущий открытый период датой начала нового назначения и создает новую строку истории.
- Повторное назначение того же открытого профиля запрещается на уровне формы.
- PostgreSQL exclusion constraint остается окончательной защитой от пересекающихся периодов; в приложении порядок сохранения выполняется транзакционно: сначала закрывается старый открытый период, затем вставляется новый.

## 2026-05-09: Admin Electricity Tariff Periods And Rates

- Тарифные периоды создаются из карточки тарифного профиля, потому что период всегда принадлежит конкретному `electricity_tariff_profile`.
- Карточка тарифного профиля показывает историю активных периодов действия ставок.
- Карточка тарифного периода показывает ставки и позволяет добавить или обновить ставку для пары “тарифная зона + диапазон потребления”.
- Для `electricity_tariff_rates` отдельный CRUD с surrogate-страницей не вводится: строка ставки адресуется композитным ключом `(workspace_uuid, tariff_period_uuid, tariff_zone_uuid, consumption_band_uuid)`.
- Активные тарифные периоды одного профиля не должны пересекаться. В приложении добавлена предварительная проверка, окончательная гарантия остается за PostgreSQL exclusion constraint.
- Формы дат тарифного периода используют тот же flatpickr-виджет и формат `дд.мм.гггг`, что и остальные админские даты.
- Ставка принимает десятичное число с точностью до 6 знаков; запятая в форме нормализуется в точку перед сохранением.

## 2026-05-09: Admin Electricity Meter Readings Section

- Раздел админки `Показания` доступен по `/admin/electricity-meter-readings`.
- Новое показание вносится из карточки электросчетчика `/admin/electricity-meters/{uuid}`; после сохранения администратор попадает на карточку созданного показания.
- В форме показания тарифная зона выбирается только из регистров выбранного счетчика.
- Показание нельзя внести раньше даты установки счетчика, позже даты снятия счетчика или меньше предыдущего активного показания той же зоны.
- Показания не редактируются и не удаляются физически. Ошибочная запись отменяется отдельным действием с причиной.
- Активное показание в интерфейсе определяется тем же правилом, что и в модели: `cancelled_at is null and replacing_reading_uuid is null`.

## 2026-05-09: Admin Payments Section

- Раздел админки `Оплаты` доступен по `/admin/payments`.
- Новая ручная оплата вносится из карточки участка `/admin/accounts/{uuid}`; после сохранения администратор попадает на карточку созданной оплаты.
- В форме ручной оплаты оператор вводит сумму, дату оплаты, плательщика, назначение платежа и внешний референс. Workspace, участок, источник и audit-поля задаются кодом.
- Карточка участка показывает историю оплат и сумму активных пополнений по участку.
- Оплаты не редактируются и не удаляются физически. Ошибочная запись отменяется отдельным действием с причиной.
- Активная оплата в интерфейсе определяется тем же правилом, что и в модели: `cancelled_at is null and replacing_payment_uuid is null`.

## 2026-05-09: Account Balance Display

- Карточка участка показывает вычисляемую финансовую сводку: active posted начисления, active оплаты и баланс.
- Раздел `/admin/account-balances` показывает тот же вычисляемый баланс по всем активным участкам текущего хозяйства.
- Баланс считается как `active payments - active posted accruals`; положительное значение означает переплату, отрицательное - задолженность. Сумма к оплате вычисляется отдельно как положительная часть задолженности.
- Баланс не хранится отдельной колонкой и не обновляется триггером.
- Draft, cancelled и superseded начисления не участвуют в балансе.
- Cancelled и superseded оплаты не участвуют в балансе.
- В карточке участка вычитание выполняется в копейках, без float-арифметики; в списке балансов агрегирование выполняет PostgreSQL `numeric`.

## 2026-05-10: Admin Dashboard

- `/admin` стал рабочим столом оператора, а не только shell-заглушкой.
- Dashboard агрегирует уже существующие read-модели и разделы: открытые проблемы расчетов, расписание формирования квитанций из `billing_settings`, первые участки с долгом из вычисляемого баланса, последние оплаты и последние показания.
- Dashboard не хранит собственное состояние и не становится мастер-системой: все карточки и таблицы ведут в профильные разделы админки.
- Доступ к `/admin` теперь проверяется через `WORKSPACE_ACCESS`; глобальный администратор проходит эту проверку автоматически, а обычный пользователь должен иметь роль хозяйства `admin` или `operator`.

## 2026-05-09: Admin CRUD Redirects

- После успешного submit формы создания или редактирования CRUD-раздел должен перенаправлять пользователя на view измененной записи.
- Redirect на index после успешного создания/сохранения не используется, потому что оператору обычно нужно сразу увидеть карточку и связанные данные.
- После удаления redirect на index остается допустимым: удаленная через soft-delete запись не должна открываться в обычном view.

## 2026-05-08: Testing Policy

- Новый функционал должен сопровождаться тестами.
- Тестовый стек подключен через `symfony/test-pack`; тестовые классы создаются через Symfony Maker `make:test`, если нужен новый класс.
- PHPUnit запускается внутри PHP-контейнера командой `make test`.
- Тестовая PostgreSQL-БД отделена от dev-БД стандартным Doctrine suffix `_test`; перед запуском тестов Makefile создает test-БД и применяет миграции.
- Для разделов `Участки`, `Абоненты`, `Группы участков`, `Электросчетчики`, `Показания электросчетчиков`, `Оплаты`, `Тарифные зоны`, `Диапазоны потребления`, `Правила диапазонов`, `Тарифные профили`, `Тарифные периоды`, `Ставки`, `Баланс участков`, `Рабочий стол` и `Аудит` добавлены functional-тесты: доступ только после login, пустой список, создание, валидация, редактирование, soft-delete, выдача и отзыв доступа к участкам с обеих сторон, добавление и закрытие членства участков в группах, создание счетчиков с регистрами, внесение и отмена показаний, внесение и отмена оплат, вычисление баланса участка, агрегированный dashboard, назначение и замена тарифного профиля участка, запрет пересекающихся тарифных периодов, добавление и обновление тарифных ставок, all-scope правила диапазонов, добавление/обновление/удаление диапазонов правила, права видимости audit log и фильтры audit.

## 2026-05-08: Audit Log

- `audit_logs` является append-only таблицей без `updated_at`, soft-delete и штатных сценариев редактирования.
- `audit_logs.workspace_uuid` nullable, потому что часть событий относится к глобальному auth/security-контексту.
- Ссылка на пользователя называется `actor_user_uuid`, потому что это не lifecycle/audit actor-поле с суффиксом `_by`.
- Для таблиц с single UUID PK используется `entity_uuid`; для composite PK используется `entity_pk jsonb`.
- `old_values`, `new_values` и `entity_pk` хранятся как `jsonb`.
- `changed_fields` хранится как PostgreSQL `text[]`.
- В audit log нельзя писать секреты: password hash, TOTP secrets, session ids, токены и аналогичные значения.

## 2026-05-10: Admin Audit Log UI

- Раздел `/admin/audit-logs` сгенерирован через Symfony Maker и доработан как рабочий экран админки.
- Просмотр audit защищен правом `WORKSPACE_ADMIN`.
- Глобальный администратор видит все события: все хозяйства и глобальные события `workspace_uuid is null`.
- Администратор хозяйства видит только события текущего хозяйства.
- Оператор хозяйства и абонент audit log не видят; пункт меню скрыт для пользователей без `WORKSPACE_ADMIN`.
- Введен сервис `AuditLogger`, который централизованно заполняет actor, request id, IP, user agent и текущего DB-пользователя. Existing audit-события выдачи доступа к порталу и ролей хозяйства переведены на этот сервис.
- Экран audit поддерживает фильтры по хозяйству, source, actor email/uuid, action, entity table, entity uuid и дате события.

## 2026-05-10: Admin List Filters

- Index-экраны админки должны поддерживать рабочие фильтры до перехода к абонентскому интерфейсу, потому что в реальном СНТ будут сотни записей.
- Для длинных index-экранов используется общая серверная пагинация на базе Doctrine paginator, размер страницы по умолчанию - 50 записей.
- `/admin/accounts` получил поиск по номеру/заметке и фильтр по наличию активных абонентов.
- `/admin/subscribers` получил поиск по ФИО/email/телефону, фильтр по доступу к порталу и фильтр по наличию активных участков.
- `/admin/electricity-meters` получил поиск по участку/серийному номеру/модели/заметке и фильтр по статусу счетчика.
- `/admin/electricity-meter-readings` получил поиск по участку/счетчику/заметке, фильтр по тарифной зоне, статусу и дате снятия.
- `/admin/payments` получил поиск по участку/сумме/плательщику/назначению/внешнему референсу, фильтр по статусу, источнику и дате оплаты.
- `/admin/accruals` получил поиск по участку/сумме/комментарию/версии расчета, фильтр по типу, статусу и началу периода.
- `/admin/billing-runs` получил фильтр по типу, статусу, наличию открытых проблем, наличию начислений, началу периода и дате создания.
- Пустое состояние различается: без фильтров показывается, что сущности пока не созданы; с фильтрами - что записи не найдены.

## 2026-05-11: Admin Smoke-Test

- Добавлен сквозной functional smoke-test административного сценария.
- Сценарий проходит через UI: создать абонента, подключить портал, создать участок, выдать доступ к участку, настроить тарифы и нормы, назначить тарифный профиль, создать счетчик, внести два показания и оплату, создать расчет, сгенерировать начисление, провести расчет и проверить баланс участка.
- Smoke-test фиксирует готовность административного MVP к переходу на абонентский интерфейс.

## 2026-05-03: Именование UUID И Auth-Схема

- UUID primary key называется `uuid`, а не `id`.
- UUID foreign key называется `<entity>_uuid`, например `user_uuid`, `account_uuid`.
- `users` хранит жизненный цикл учетной записи, но не хранит пароль, роли и динамическую историю входов.
- `users` согласована без поля `status`; состояние выводится из `approved_at`, `blocked_at`, `deleted_at`.
- Локальный пароль хранится в `user_password_credentials`.
- `user_password_credentials` не имеет `created_at`, потому что его смысл дублирует `changed_at`.
- `user_password_credentials` не имеет `changed_by`; инициатор смены пароля хранится в `user_password_history`.
- История локальных паролей хранится отдельно в append-only таблице `user_password_history`.
- `user_password_history` не имеет отдельного `uuid`; primary key - `(user_uuid, changed_at)`.
- `user_password_history` согласована без `created_at`/`updated_at`/`deleted_at` и без `expires_at`.
- Email хранится в отдельной таблице `user_email_identities`.
- Primary key `user_email_identities` - пара `(user_uuid, email_normalized)`, без отдельного surrogate UUID.
- Отвязка email выполняется soft-delete через `deleted_at`; отвязанная запись не мешает привязать тот же email другому пользователю.
- Один пользователь может иметь несколько активных email; один активный email не может быть привязан к нескольким пользователям.
- Телефон и внешние провайдеры будут отдельными identity-таблицами, если понадобятся.
- Обычные UUID foreign key называются с суффиксом `_uuid`.
- Lifecycle/audit actor-поля с суффиксом `_by` являются исключением и пишутся без `_uuid`: `created_by`, `updated_by`, `deleted_by`, `changed_by`, `posted_by`, `cancelled_by`.
- Ссылки на пользователя без суффикса `_by` используют обычное правило `_uuid`, например `actor_user_uuid`.
- Вводится `workspaces` как изолированный контур бизнес-данных. Для бизнес-кода все хозяйства равнозначны; назначение контура задается через `name`/`description`, без enum типа workspace.
- Все бизнес-таблицы явно хранят `workspace_uuid`.
- Auth-таблицы `users`, email/password credentials остаются глобальными. Административные роли хозяйства получают `workspace_uuid` через `workspace_user_role_assignments`.
- FK между workspace-scoped таблицами должен включать `workspace_uuid`; для таблиц с surrogate `uuid` добавляется `unique (workspace_uuid, uuid)`.
- Глобальное администрирование платформы хранится в `users.admin_granted_at/admin_revoked_at`, без отдельного boolean.
- Роли пользователей внутри хозяйства хранятся через PostgreSQL enum `workspace_user_role_code` и связующую таблицу `workspace_user_role_assignments`; отдельной справочной таблицы `roles` нет.
- Начальный набор ролей хозяйства: `admin`, `operator`.
- `workspace_user_role_assignments` согласована без `created_at`/`updated_at` и `created_by`/`updated_by`; события описываются `granted_*` и `revoked_*`.
- TOTP не входит в MVP-схему и помечается как вероятное развитие функционала аутентификации.
- MVP использует стандартные Symfony sessions без доменной таблицы сессий.
- `user_login_events` не входит в MVP-схему; security audit попыток входа будет отдельным развитием, если понадобится.
- Auth-блок согласован для MVP.

## 2026-05-02: Пользователи И Роли

- Для входа используется единая сущность `User`.
- Администраторы не хранятся в отдельной таблице логинов: глобальное администрирование выводится из `users.admin_granted_at/admin_revoked_at`, администрирование хозяйства - из `workspace_user_role_assignments`.
- MVP использует email + пароль.
- Телефон хранится как контакт и будущий способ входа.
- Свободная саморегистрация не входит в ближайший этап. Выдача доступа идет через подключение известного email к `Subscriber` текущего хозяйства.
- TOTP для администраторов не входит в MVP и рассматривается как вероятное развитие функционала аутентификации.
- Внешние провайдеры аутентификации идут после MVP через отдельную identity-таблицу.
- В MVP любой активный доступ абонента к участку позволяет видеть участок и передавать показания. `access_role` хранится для будущего разделения прав внутри семьи или доверенных лиц.
- `Subscriber` хранит ФИО раздельно: `last_name`, `first_name`, `second_name`; `full_name` не хранится.
- `SubscriberAccountAccess.access_role` хранится как PostgreSQL enum `subscriber_account_access_role`: `owner`, `representative`, `viewer`.
- `subscriber_account_accesses` не имеет surrogate `uuid`; primary key - `(workspace_uuid, subscriber_uuid, account_uuid, granted_at)`.
- `subscriber_account_accesses` не имеет технических `created_at`/`updated_at`; историю описывают `granted_*` и `revoked_*`.

## 2026-05-10: Выдача Доступа К Хозяйству Вместо Инвайтов

- В ближайшем MVP не делаем свободную публичную регистрацию.
- Не делаем классические invite-link. Оператор выполняет бизнес-действие "подключить email к абоненту в хозяйстве".
- Если глобальный `User` уже существует, система только привязывает его к `Subscriber` текущего хозяйства и отправляет уведомление о новом доступе.
- Если `User` не существует, система создает пользователя, email identity, временный пароль и привязывает его к `Subscriber`.
- Временный пароль можно помечать протухшим через `expires_at = '1970-01-01 00:00:00+00'`, чтобы после первого входа отправить пользователя в flow смены пароля.
- Административные роли хозяйства хранятся в `workspace_user_role_assignments` с role code `admin` и `operator`.
- Абонентский доступ к порталу выводится из `subscribers.user_uuid`; обычный абонент не является строкой в таблице ролей хозяйства.
- Глобальный администратор платформы хранится в `users.admin_granted_at/admin_revoked_at`, без отдельного boolean `admin`.
- На первом этапе выдача глобального администратора через UI не нужна; достаточно CLI или ручного серверного действия. UI можно добавить позже.
- Все действия выдачи/отзыва доступа пишутся в `audit_logs`; временный пароль в audit не пишется.
- Самозаявку на регистрацию можно добавить позже отдельным расширением.

## 2026-05-10: Доступ В Админку Через Роли Хозяйства

- `/admin` больше не завязан только на глобальный `ROLE_ADMIN`; доступ проверяется через `WORKSPACE_ACCESS`.
- `WORKSPACE_ACCESS` получают глобальный администратор, активный `admin` текущего хозяйства и активный `operator` текущего хозяйства.
- Переключатель хозяйства в верхней панели показывает только доступные пользователю хозяйства: глобальному администратору все, администратору/оператору хозяйства только те, где у него активная роль.
- Управление ролями хозяйства защищено отдельным правом `WORKSPACE_ADMIN`.
- `WORKSPACE_ADMIN` получают глобальный администратор и активный `admin` текущего хозяйства; `operator` не может выдавать или отзывать роли.
- Выдача и отзыв ролей хозяйства доступны из карточки пользователя `/admin/users/{uuid}`.
- Отзыв роли заполняет `revoked_at`, `revoked_by`, `revoked_reason`; физическое удаление строки не используется.
- Нельзя отозвать последнюю активную роль `admin` текущего хозяйства.

## 2026-05-02: Технический Стек И Развертывание

- Symfony-проект размещается в корне репозитория.
- Frontend - Symfony + Twig + Forms, без SPA.
- Doctrine ORM и Doctrine Migrations используются для сущностей и миграций.
- Локальная разработка идет через Docker Compose.
- Первый администратор создается CLI-командой.
- Реальная отправка email нужна только в production; в dev используется dev-mailer, лог или неотправляющий transport.
- Production должен запускать готовый Docker image, собранный в CI. На production-сервере не нужно собирать приложение из исходников.
- Для первого production-деплоя допустим single-host Docker Compose с готовым image из registry.
- Stateful-сервисы в production устанавливаются на хосте, без контейнеризации: PostgreSQL, Redis, RabbitMQ и другие сервисы, которые владеют состоянием.
- GitHub + GitHub Actions + GHCR остаются предпочтительным вариантом для open-source проекта, но перед завязкой на них нужен практический smoke-test доступности из РФ.

## 2026-05-02: Участки И Начисления

- `Account` моделирует участок СНТ.
- Участок хранит текстовое `number`; в текущем СНТ это номер участка, но схема не зависит от формата.
- `Account.status` не используется; активность участка определяется через `deleted_at is null`.
- Начисления относятся к участку, а не к человеку.
- Баланс участка общий: все posted-оплаты минус все posted-начисления.
- Раздельные балансы по типам начислений не нужны в MVP; детализация показывается в отчетах.
- Кадастровые номера, площадь и адрес не входят в MVP. В будущем связь `Account` с кадастровыми объектами должна быть 1..N.
- Площадь и адрес лучше привязывать к кадастровому объекту, а не к `Account`.
- В будущем можно рассмотреть кадастровые номера не только земельных участков, но и объектов капитального строительства.
- Группы участков входят в MVP. Начальный практический сценарий: летнее использование участка и круглогодичное проживание.
- Категории групп в MVP не вводятся.
- `account_group_members` не имеет surrogate `uuid`; primary key - `(workspace_uuid, account_group_uuid, account_uuid, valid_from)`.
- Членские взносы, вода и другие неэлектрические платежи не проектируются как расчетные формулы в MVP.
- Первый будущий шаг для неэлектрических платежей - ручное начисление администратором с суммой, периодом, типом, комментарием и основанием.
- Расчетные формулы членских взносов и воды являются далеким будущим и требуют отдельного изучения устава, решений СНТ и 217-ФЗ.

## 2026-05-02: Финансовая История

- Данные, которые уже повлияли или могут повлиять на деньги, нельзя удалять или перетирать.
- Финансовая история проектируется как append-only: исправления выполняются новыми записями, статусами и ссылками на заменяемые записи.
- Ошибочное показание заменяется новым показанием с ссылкой на старое.
- Ошибочное начисление переводится в `superseded`, затем создается новое начисление.
- Ошибочная оплата переводится в `cancelled`, затем создается новая posted-оплата.
- Все финансово значимые исправления должны попадать в audit log.

## 2026-05-02: Электросчетчики

- MVP поддерживает один активный электросчетчик на участок.
- История замен счетчика хранится несколькими записями `electricity_meters`.
- `electricity_meters.serial_number` nullable.
- `electricity_meters.model` nullable.
- `electricity_meters.status` не используется.
- Начальное и финальное показания не хранятся в `electricity_meters`; они должны быть событиями в `electricity_meter_readings`.
- Поверка счетчика хранится полями `verified_on` и `verification_valid_until`.
- Показание меньше предыдущего не принимается как обычное показание. Для такой ситуации нужна замена счетчика или админская корректировка с причиной.
- `electricity_meter_readings.status` не используется. Активное показание определяется условием `cancelled_at is null and replacing_reading_uuid is null`.
- `provided_by_subscriber_uuid` оставлен для фиксации абонента, который сообщил показание, если запись внес другой пользователь.
- Исправление показания фиксируется через `replacing_reading_uuid` в старой записи; отмена без замены фиксируется через `cancelled_at`, `cancelled_by` и `cancellation_reason`.
- Показания хранятся по тарифной зоне счетчика: `electricity_meter_readings` содержит `electricity_meter_uuid` и `tariff_zone_uuid`.
- `electricity_meter_registers` фиксирует, какие тарифные зоны есть у счетчика. Primary key - `(workspace_uuid, electricity_meter_uuid, tariff_zone_uuid)`, surrogate `uuid` не используется.
- `electricity_meter_registers` immutable: строки не редактируются и не удаляются; при ошибке soft-delete выполняется на `electricity_meters`.
- Несколько активных электросчетчиков на один участок оставлены за пределами MVP.

## 2026-05-02: Расчет Показаний

- Формирование квитанций привязано к настраиваемому дню месяца, предварительно 5 число.
- Актуальность показания определяется настраиваемым окном, предварительно 15 дней.
- Если актуального показания нет, MVP не начисляет по среднему автоматически.
- Участок без актуального показания попадает в список контроля администратора.
- Если администратор снял показание после даты формирования, оно может закрыть расчетный период только явным действием администратора в draft `billing_run`.
- Если начисление уже posted, позднее показание требует пересчета с supersede старого начисления.

## 2026-05-02: Округление

- Все расчеты выполняются в `numeric`.
- Денежные компоненты начисления округляются до копеек по правилу half-up.
- Итог начисления равен сумме округленных денежных компонентов.
- Для спорных случаев округление должно быть покрыто тестами.

## 2026-05-02: Тарифы И Социальные Нормы

- Тарифы и правила распределения потребления по диапазонам вводит администратор вручную на основании внешнего документа или решения СНТ. Точный внешний источник находится вне системы.
- Для тарифа и правила диапазонов хранится ссылка/описание документа-основания.
- Исправление тарифа или правила диапазонов задним числом должно запускать пересчет затронутых начислений.
- Тарифная модель состоит из профилей, зон, диапазонов потребления, периодов и ставок.
- Время действия тарифной зоны внутри суток не хранится: система получает готовые показания по зонам/регистрам.
- `electricity_tariff_periods` оставляет surrogate `uuid`, потому что дата начала периода может быть исправлена. Дополнительно есть unique index по `(workspace_uuid, tariff_profile_uuid, valid_from)` для активных записей.
- Пересекающиеся активные тарифные периоды внутри одного тарифного профиля запрещаются на уровне БД.
- `electricity_tariff_rates` не имеет surrogate `uuid`; primary key - `(workspace_uuid, tariff_period_uuid, tariff_zone_uuid, consumption_band_uuid)`.
- Социальная норма моделируется как частный случай правил распределения потребления по диапазонам.
- `electricity_consumption_band_rules` имеет surrogate `uuid`, потому что это редактируемая шапка правила.
- `electricity_consumption_band_rule_ranges` не имеет surrogate `uuid`; primary key - `(workspace_uuid, rule_uuid, consumption_band_uuid)`.
- Области применения правил диапазонов разбиты на три таблицы без nullable-ссылок и без surrogate `uuid`: all-scopes, group-scopes, account-scopes.
- Правила диапазонов поддерживают область применения: все участки, группа участков, отдельный участок, включение или исключение.
- В MVP достаточно глобального all-scope правила для всех участков; группы и исключения остаются расширением модели.

## 2026-05-07: Начисления

- `billing_runs` не имеет `status`, `created_*`, `updated_*`, `deleted_at` и `notes`; состояние выводится из `generated_*`, `posted_*`, `cancelled_*`.
- Первый админский экран `billing_runs` создает draft-запуск расчета за период и сразу генерирует `billing_run_account_issues` по активным участкам. Начисления и posting добавляются отдельными шагами.
- Draft `billing_run` можно отменить с причиной. Edit/delete не используются.
- `billing_run_account_issues` является рабочей нефинансовой таблицей: имеет `updated_*`, закрывается через `closed_at`, `closed_by`, `close_reason`.
- Повторная проверка draft-расчета закрывает исчезнувшие открытые проблемы как `resolved`, но не переоткрывает проблемы, вручную закрытые как `ignored`.
- Future posting должен быть запрещен, если в `billing_run` есть открытые `billing_run_account_issues`.
- Draft-начисления электроэнергии создаются отдельным действием из `billing_run`, только для участков без открытых проблем. Повторная генерация не создает дубли для уже сгенерированных участков.
- Каждый запуск генерации draft-начислений фиксируется в `billing_run.accruals_generated_*`, даже если повторная генерация только подтвердила уже созданные начисления.
- Posting `billing_run` запрещен при открытых проблемах, отсутствии начислений или изменениях `billing_run_account_issues` после последней генерации начислений. Успешный posting проставляет `posted_*` у расчетного запуска и у draft-начислений этого запуска.
- `accruals` хранит `period_start`/`period_end`, даже если начисление создано из `billing_run`, потому что начисление является самостоятельной финансовой сущностью.
- `accruals` имеет `created_*`/`updated_*`, потому что у начисления есть draft-стадия.
- `accruals` не имеет `deleted_at`; финансовые начисления отменяются или заменяются.
- Состояние `accruals` вычисляется из `posted_at`, `cancelled_at`, `replacing_accrual_uuid`.
- В MVP разрешены ручные posted-начисления типов `membership_fee`, `water` и `other`; они участвуют в балансе на тех же правилах, что и расчетные начисления.
- `electricity_accrual_contexts` заменяет старый черновик `electricity_accrual_details` и хранит только примененный контекст расчета, без вычислимых итогов.
- `electricity_accrual_registers` не дублирует значения показаний и расход; она хранит только ссылки на использованные immutable-показания.
- `electricity_accrual_lines` хранит финансовый snapshot строк начисления: `consumption_kwh`, `rate`, `amount`.

## 2026-05-02: Оплаты

- В MVP posted-оплата всегда относится к одному участку.
- Платеж без понятного участка не превращается в `Payment`; он остается вне системы до ручного выяснения. Для будущего импорта будут отдельные raw-таблицы.
- Переплата разрешена и отображается как отрицательный долг или положительный баланс.
- Перенос оплаты между участками выполняется не тихим изменением posted-записи, а отменой старой оплаты и созданием новой записи с причиной.
- `payments` не имеет `status` и `deleted_at`; состояние вычисляется из `cancelled_at` и `replacing_payment_uuid`.
- Старый платеж указывает на новую запись-замену через `replacing_payment_uuid`.
- Прямой связи `payments` с квитанциями нет. Будущая квитанция/statement будет ссылаться на начисления и оплаты как snapshot-отчет.

## 2026-05-02: Отчеты

- Текущий PDF является функциональным образцом, но не требует pixel-perfect повторения.
- Массовая генерация PDF и email-рассылка не входят в MVP.
- Печатная форма нужна позже для выдачи через контору СНТ.

## 2026-05-11: Admin Audit Coverage

- Финансово значимые действия в админке пишутся через общий `AuditLogger`.
- Lifecycle пользователя фиксируется отдельными событиями: создание пользователя, добавление и отвязка email, одобрение, блокировка, разблокировка, soft-delete и установка пароля.
- Password hash, временный пароль и иные секреты в audit не пишутся; для пароля фиксируются только факт изменения, наличие credential и срок действия.
- Изменение `billing_settings` и timezone текущего workspace фиксируется событием `billing_settings.created` или `billing_settings.updated`.
- Базовый доменный контур фиксирует создание, изменение и soft-delete участков, абонентов, групп участков и электросчетчиков.
- Связи фиксируются отдельными событиями: выдача/отзыв доступа абонента к участку, привязка/отвязка `User` к `Subscriber`, добавление/закрытие членства участка в группе.
- Назначения тарифного профиля участку фиксируются по составному `entity_pk`; закрытие предыдущего открытого назначения пишется отдельным событием.
- В audit log фиксируются создание и отмена оплат, создание и отмена показаний электросчетчиков, создание и отмена ручных начислений.
- Расчетный контур фиксирует создание `billing_run`, повторную проверку проблем, генерацию начислений, posting, закрытие проблем и отмену расчета.
- Тарифный контур фиксирует создание, изменение и удаление тарифных зон, тарифных профилей, тарифных периодов, диапазонов потребления и правил диапазонов; ставки и строки диапазонов пишутся по составному `entity_pk`.
- В audit log записывается бизнес-контекст действия: workspace, таблица, `entity_uuid`, измененные поля, old/new values и причина отмены или закрытия, если она есть.
- Секреты и временные пароли в audit не пишутся.

## 2026-05-11: Admin UX Pass

- Рабочий стол в левом меню находится в группе `Основное`, без промежуточной ссылки `Обзор`.
- На desktop-ширине боковое меню и верхняя панель админки закреплены при прокрутке.
- Кнопки применения фильтров в list-разделах приведены к единой подписи `Применить`.
- Страницы тарифных периодов подсвечивают родительский пункт `Тарифные профили`.
- Добавлен functional-тест пользовательского сценария `/login -> / -> /admin`, чтобы вход через форму не проверялся только косвенно через `loginUser()`.

## 2026-05-12: Forced Password Change

- `user_password_credentials.expires_at` используется для принудительной смены временного локального пароля.
- Протухший пароль не блокирует сам login: пользователь должен войти текущим паролем, затем сменить его на `/password/change`.
- До смены пароля request-listener перенаправляет пользователя с остальных защищенных страниц на `/password/change`; исключения - login, logout, сама форма смены пароля и служебные Symfony routes.
- Успешная смена пароля сбрасывает `expires_at` в `NULL`, пишет append-only запись в `user_password_history` через `UserPasswordManager` и audit-событие `user.password_changed` без password hash.

## 2026-05-12: User Profile MVP

- Добавлен self-service профиль `/profile` для любого аутентифицированного пользователя.
- Профиль показывает глобальную учетную запись, активные email identities, дату установки пароля и доступы к хозяйствам.
- Доступы к хозяйствам в профиле объединяют административные права (`users.admin_*`, `workspace_user_role_assignments`) и абонентскую привязку (`subscribers.user_uuid`).
- Добровольная смена пароля использует тот же `/password/change`, что и forced-flow для временных паролей.
- Профиль показывает быстрый переход в личный кабинет при наличии абонентского доступа и в админку при наличии административного доступа.
- После добровольной смены пароля пользователь возвращается в профиль; forced-flow протухшего пароля по-прежнему возвращает на общий dashboard.
- Самостоятельная смена email, TOTP, внешние identity, управляемые серверные сессии, отзыв сессий и security audit входов отложены в отдельный будущий identity/security-блок.

## 2026-05-12: Admin Хозяйства

- Добавлен раздел `/admin/workspaces` для глобального администратора.
- В MVP раздел поддерживает список, карточку, создание и редактирование хозяйства.
- Редактируются `code`, `name`, `description`, `timezone`; `code` остается уникальным.
- Действия пишутся в audit log как `workspace.created` и `workspace.updated`.
- Администратор и оператор хозяйства не могут управлять списком хозяйств; они только переключаются между доступными хозяйствами через существующий switcher.
- Удаление/архивирование хозяйства отложено. Для него нужно отдельное lifecycle-решение, потому что `Workspace` является корнем tenant-изоляции и связан с финансовой историей.

## 2026-05-12: Проект Абонентского Интерфейса

- Проект MVP личного кабинета зафиксирован в `docs/subscriber-portal.md`.
- Абонентский доступ к хозяйствам выводится из активных `Subscriber`, привязанных к текущему `User`.
- `WorkspaceContext` остается административным контекстом. Для портала нужен отдельный `SubscriberPortalContext`, потому что чистый абонент не имеет `workspace_user_role_assignments`.
- `WORKSPACE_ACCESS` остается административным правом. Для портала вводятся отдельные grants: `SUBSCRIBER_PORTAL_ACCESS`, `SUBSCRIBER_ACCOUNT_VIEW`, `SUBSCRIBER_ACCOUNT_READING_SUBMIT`.
- Первый вертикальный сценарий портала: login, список участков, карточка участка, активный счетчик, баланс, история показаний и передача нового показания.

## 2026-05-12: MVP Абонентского Интерфейса

- Первый вертикальный сценарий `/portal` реализован и покрыт functional-тестами.
- Чистый абонент после входа попадает в личный кабинет, а пользователь с административным и абонентским доступом видит оба раздела.
- Список и карточка участка показывают активные доступы абонента, вычисляемый баланс, активный электросчетчик, историю показаний, posted-начисления и оплаты.
- Передача показаний создает по одной `ElectricityMeterReading` на каждый регистр активного счетчика, требует заполнить все зоны и проверяет дату, границы установки/снятия счетчика, предыдущее и следующее активное показание.
- Портал использует отдельный `SubscriberPortalContext` и session key `_snt_current_portal_workspace_uuid`, чтобы абонентский выбор хозяйства не смешивался с административным `WorkspaceContext`.

## 2026-05-12: Пагинация Историй В Абонентском Интерфейсе

- Карточка участка в `/portal/accounts/{uuid}` больше не загружает полные истории показаний, начислений и оплат.
- Для трех блоков используются независимые paginated-выборки с размером страницы 10 записей и отдельными query-параметрами `readings_page`, `accruals_page`, `payments_page`.
- Показания фильтруются по статусу и дате снятия; начисления - по статусу и началу периода; оплаты - по статусу и дате оплаты.
- Начисления в портале остаются только posted: draft-записи не показываются абоненту даже через фильтры.
- Общий ORM-пагинатор вынесен в `QueryPaginator`; `AdminPaginator` сохранен как совместимое имя для админских контроллеров.

## 2026-05-12: Общая Валидация Показаний

- Правила проверки показаний электросчетчика вынесены в `ElectricityMeterReadingValidator`.
- Сервис проверяет, что тарифная зона задана, счетчик имеет регистр этой зоны, дата не выходит за период установки/снятия счетчика, а новое значение не меньше предыдущего и не больше следующего активного показания этой зоны.
- Запрет будущей даты сделан опциональным: портал включает его для абонента, админка сохраняет текущую возможность вводить показания без этой дополнительной проверки.
- Контроллеры больше не содержат доменную проверку монотонности: они только отображают ошибки сервиса в нужных полях формы.
- Сравнение значений выполняется как decimal со scale 3, без float-сравнения.

## 2026-05-12: Первый Экран Квитанции / Statement

- Добавлен read-only экран текущего statement по участку без PDF и без сохраненного snapshot в БД.
- В пользовательском интерфейсе используется термин "квитанция"; в коде используется `statement`, потому что текущий экран является отчетом-сверкой, а не юридически зафиксированным документом с номером и доставкой.
- Экран доступен из админки `/admin/accounts/{uuid}/statement`; в личном кабинете позднее переименован в "Баланс и операции" и перенесен на `/portal/accounts/{uuid}/balance`.
- Statement строится динамически из active posted начислений, active оплат и вычисляемого баланса.
- Draft, cancelled и superseded начисления, а также cancelled и superseded оплаты не участвуют в statement. Это совпадает с правилами вычисляемого баланса.
- Сумма к оплате считается как `max(active_posted_accruals - active_payments, 0)`. При переплате сумма к оплате равна нулю, а переплата показывается отдельно.
- Следующий этап по квитанциям должен добавить persistent snapshot: заголовок, номер, связи или snapshot-строки начислений/оплат, totals, lifecycle, а уже потом PDF, QR-код и доставку.

## 2026-05-13: Persistent Snapshot Квитанции

- Добавлена snapshot-модель квитанций: `account_statements`, `account_statement_accruals`, `account_statement_payments`.
- Snapshot формируется администратором из текущего dynamic statement и получает номер вида `ST-YYYYMMDD-XXXXXXXX`, где suffix - 8 uppercase hex-символов из UUID snapshot.
- В `account_statements` сохраняются snapshot-поля `workspace_name` и `account_number`, чтобы переименование хозяйства или участка не меняло уже сформированный документ.
- В строках snapshot сохраняются ссылки на исходные `accruals`/`payments` и копии ключевых отображаемых полей: период, тип, сумма, комментарий начисления; дата, источник, плательщик, назначение и сумма оплаты.
- Snapshot не пересчитывается и не меняется при последующей отмене или замене исходных начислений/оплат. Для корректировки нужно сформировать новый snapshot.
- Lifecycle snapshot пока состоит из `generated_*` и будущего `cancelled_*`; отдельный `status` не хранится.
- PDF, QR-код и доставка остаются следующими этапами.

## 2026-05-13: Snapshot-Детализация Электроэнергии

- Добавлены таблицы `account_statement_electricity_registers` и `account_statement_electricity_lines`.
- Snapshot регистров хранит ссылки на исходный счетчик, тарифную зону и использованные показания, а также копии отображаемых данных: название зоны, модель/серийный номер счетчика, значения и даты предыдущего и текущего показания.
- Snapshot строк расчета хранит ссылки на исходную тарифную зону и диапазон потребления, а также копии названий зоны/диапазона, расход, ставку и сумму.
- Имена справочников и значения расчета копируются в snapshot, потому что сохраненная квитанция не должна менять содержание после переименования тарифных зон, диапазонов или последующих правок исходных данных.
- Сохраненная квитанция теперь самодостаточна для HTML/PDF-формы в рамках уже рассчитанной электроэнергии.

## 2026-05-13: Печатная HTML-Форма Snapshot-Квитанции

- Добавлена печатная HTML-форма сохраненной квитанции: `/admin/accounts/{uuid}/statements/{statementUuid}/print`.
- Форма строится только от snapshot-таблиц, а не от текущего dynamic statement.
- Печатная страница не использует административный shell, чтобы браузерная печать не захватывала меню, верхнюю панель и flash-сообщения.
- Экранная toolbar с кнопками "Назад" и "Печать" скрывается в `@media print`.
- PDF-генерация, QR-код оплаты и доставка остаются отдельными следующими этапами.

## 2026-05-13: Платежные Реквизиты Для Квитанций

- Реквизиты не хранятся прямо в `workspaces`; для них добавлен справочник `payment_requisite_profiles` внутри хозяйства.
- Назначения реквизитов хранятся отдельно в `payment_requisite_assignments`: `accrual_type null` означает default-реквизиты для всех квитанций, заполненный `accrual_type` задает реквизиты для конкретного типа начислений.
- UI через `/admin/payment-requisite-profiles` управляет профилями реквизитов, default-назначением, назначениями по типам начислений и снятием открытых назначений.
- При формировании snapshot реквизиты копируются в `account_statements`, включая получателя, ИНН/КПП, банк, БИК, счета и назначение платежа.
- Ссылка `payment_requisite_profile_uuid` остается в snapshot только как audit-ссылка на источник; печатная форма выводит сохраненные snapshot-поля.
- PDF-генерация и QR-код оплаты будут использовать те же snapshot-реквизиты, чтобы старые квитанции не менялись после правки справочника.

## 2026-05-13: QR-Код Оплаты

- QR-код оплаты формируется только из сохраненных snapshot-полей `account_statements`, без чтения текущего справочника реквизитов.
- Payload строится в формате `ST00012|Name=...|PersonalAcc=...|...|Sum=...`; `Sum` указывается в копейках.
- QR не формируется, если нет обязательных реквизитов получателя/банка или сумма к оплате равна нулю.
- Для генерации PNG используется библиотека `endroid/qr-code`, без собственной реализации QR-алгоритма.
- QR хранится в HTML/PDF как `data:image/png;base64,...`; для этого в PHP-образе явно устанавливается `ext-gd`.
- Печатная HTML-форма и карточка сохраненной квитанции используют один сервис `AccountStatementPaymentQrCodeGenerator`.

## 2026-05-13: PDF Snapshot-Квитанции

- Добавлен маршрут `/admin/accounts/{uuid}/statements/{statementUuid}/pdf`.
- PDF строится только от snapshot-таблиц и тех же snapshot-реквизитов, что HTML-форма.
- Для генерации используется `dompdf/dompdf`, без внешнего браузера или системного `wkhtmltopdf`.
- Для кириллицы в PDF используется DejaVu Sans, встроенный в Dompdf.
- PDF-шаблон отдельный и табличный: `account_statement/pdf.html.twig`, чтобы не зависеть от поддержки современного CSS в PDF-рендере.
- Ответ отдается как `application/pdf` с inline `Content-Disposition`.

## 2026-05-13: Каркас Доставки Snapshot-Квитанций

- Добавлены `account_statement_deliveries` и `account_statement_delivery_attempts`.
- Канал доставки хранится PostgreSQL enum `account_statement_delivery_channel`; в MVP доступен только `email`.
- Доставка ставится в очередь из карточки сохраненной квитанции через `/admin/accounts/{uuid}/statements/{statementUuid}/deliveries/enqueue`.
- Получатели берутся из активных абонентов участка: сначала используется `subscribers.contact_email`, затем активный verified email связанного пользователя.
- Email и имя получателя копируются в delivery как snapshot-поля, чтобы уже созданная отправка не менялась после правки абонента.
- Дубли не создаются: активная отправка уникальна по `workspace_uuid`, `account_statement_uuid`, `channel`, `recipient_email_normalized`.
- Первая попытка создается в статусе queued.
- Статус доставки и попытки вычисляется из timestamp-полей, отдельная `status`-колонка не хранится.

## 2026-05-13: SMTP-Обработчик Доставки Квитанций

- Для доставки email добавлен Symfony Mailer.
- В dev по умолчанию используется `MAILER_DSN=null://null`, чтобы можно было проверять lifecycle без реальной отправки писем.
- Команда `app:account-statement-deliveries:send` обрабатывает queued attempts с ограничением `--limit`.
- Перед отправкой попытка помечается `started_at`; успешная отправка заполняет `succeeded_at`, ошибка заполняет `failed_at` и `failure_reason`.
- Письмо строится из snapshot-данных квитанции и содержит PDF-вложение, сформированное тем же `AccountStatementPdfRenderer`, что и ручная PDF-кнопка в админке.

## 2026-05-13: Массовое Формирование Квитанций По Расчету

- `account_statements.billing_run_uuid` связывает snapshot-квитанцию с расчетным запуском, если квитанция сформирована массово.
- Активная квитанция уникальна по `workspace_uuid`, `billing_run_uuid`, `account_uuid`, если `billing_run_uuid is not null` и `cancelled_at is null`.
- Массовая генерация доступна только для проведенного `billing_run`, потому что квитанция должна опираться на уже posted начисления, участвующие в балансе.
- Источник участков для массовой генерации - active posted начисления этого расчета.
- Повторный запуск идемпотентен: существующие активные квитанции повторно не создаются, но для них можно дозапустить постановку доставок в очередь.
- Карточка расчета показывает список сформированных квитанций по этому расчету со ссылками на карточку/PDF и сводкой статусов доставок.

## 2026-05-13: Админский Раздел Очереди Доставок

- Добавлен раздел `/admin/account-statement-deliveries`.
- Раздел показывает email-доставки snapshot-квитанций по текущему хозяйству.
- Фильтры статуса: все, queued, sending, sent, failed, cancelled.
- Поиск работает по номеру участка, номеру квитанции, email и имени получателя.
- Строка очереди ведет в карточку квитанции и PDF.

## 2026-05-14: Админский Раздел Квитанций

- Добавлен общий раздел `/admin/account-statements` для просмотра сохраненных snapshot-квитанций по текущему хозяйству.
- Раздел не пересчитывает квитанции и читает только сохраненные `account_statements`.
- Фильтры: поиск по участку/номеру квитанции, статус квитанции, расчет, отсутствие расчета, дата формирования, сумма к оплате и наличие доставок.
- Доставка в списке показывается как сводка по существующим `account_statement_deliveries`; отправка писем по-прежнему выполняется отдельной CLI-командой.

## 2026-05-14: Отмена Snapshot-Квитанций И Доставок

- Отмена квитанции заполняет `account_statements.cancelled_at`, `cancelled_by`, `cancellation_reason`.
- Вместе с квитанцией отменяются все ее активные `account_statement_deliveries`.
- `account_statement_delivery_attempts` остается транспортным журналом: бизнес-операция отмены его не обновляет и не удаляет.
- SMTP-обработчик не выбирает queued attempts, если отменена доставка или сама квитанция.
- Успешная попытка отправки остается в истории даже после отмены квитанции; такая доставка отображается как "Отменена после отправки".

## 2026-05-14: Выдача Ролей Хозяйства По Email

- В разделе `/admin/users` добавлено бизнес-действие "выдать доступ к хозяйству": email + роль `admin`/`operator`.
- Если активный `UserEmailIdentity` уже есть, роль текущего хозяйства выдается существующему `User`.
- Если пользователя нет, система создает approved `User`, verified email identity и временный протухший пароль.
- Если существующий пользователь не имеет локального пароля, система выдает ему временный протухший пароль.
- Оператор хозяйства не видит форму и не может вызвать маршрут выдачи роли; действие защищено `WORKSPACE_ADMIN`.
- Список и карточка пользователей доступны оператору по `WORKSPACE_ACCESS`, но identity-действия защищены `WORKSPACE_ADMIN`: создание пользователя, email identities, approval/block/delete, установка пароля, прямая связь `User` с `Subscriber` из карточки пользователя и управление ролями.
- Оператор хозяйства продолжает подключать абонентский портал из карточки `Subscriber`; это бизнес-действие над абонентом, а не прямое управление глобальной учетной записью из раздела пользователей.

## 2026-05-14: Матрица Прав Админки

- Новый рабочий раздел текущего хозяйства по умолчанию защищается `WORKSPACE_ACCESS`.
- `WORKSPACE_ACCESS` означает операционную работу в хозяйстве: участки, абоненты, счетчики, показания, оплаты, начисления, расчеты, квитанции, доставки и платежные реквизиты.
- `WORKSPACE_ADMIN` используется для управления identity, ролями хозяйства и просмотра audit log.
- `ROLE_ADMIN` используется для платформенных действий вне одного хозяйства, сейчас это реестр хозяйств `/admin/workspaces`.
- Оператор хозяйства может выполнять финансово значимые действия, но такие действия должны писать `audit_logs`.
- Матрица закреплена в `docs/admin-permissions.md` и functional-тесте `AdminPermissionMatrixTest`.
- Сквозной административный smoke-сценарий выполняется и глобальным администратором, и оператором хозяйства. Операторский smoke подтверждает, что рабочий процесс доступен без системных прав, а пункты `Хозяйства` и `Аудит` скрыты.

## 2026-05-14: Smoke Личного Кабинета Абонента

- Добавлен сквозной smoke-сценарий личного кабинета абонента через реальную форму `/login`.
- Чистый абонент после входа попадает с `/` в `/portal`.
- Smoke проверяет dashboard портала, карточку участка, экран "Баланс и операции", форму передачи показаний и появление нового показания в истории.
- Сценарий использует обычного пользователя без `workspace_user_role_assignments`, поэтому админские права не участвуют в проверке портала.
- В портальном shell добавлена явная вкладка `Главная`; вкладка `Участки` остается отдельным списком участков.
- Dashboard и список участков показывают прямое действие `Передать показания`, если у участка есть активный электросчетчик.

## 2026-05-14: Demo Seed CLI

- Для обучения, демонстраций и pilot-проверок нужен отдельный demo seed CLI, а не Doctrine fixtures.
- Команда реализована как `app:demo:seed` с опциями `--workspace-code`, `--workspace-name`, `--size`, `--as-of`, `--seed`, `--grant-admin-email`, `--grant-operator-email`, `--reset`, `--confirm`.
- Demo seed создает отдельное демо-хозяйство с псевдоданными: абоненты, участки, представители, группы, счетчики, тарифы, показания, оплаты, расчеты, квитанции, доставки и открытые проблемы.
- Доступ к админке демо-хозяйства выдается существующим пользователям по active verified email; demo seed не создает отдельные демо-логины и временные пароли.
- Данные сценарные: долг, переплата, неактуальные показания, исправленное показание, два владельца/представителя, абонент с двумя участками, двухтарифный счетчик, queued/failed/cancelled доставки.
- Генерируемые email демо-абонентов используют reserved-домен `example.test`; email существующих пользователей, которым выдается доступ через CLI-опции, могут быть реальными.
- Реальные персональные данные и реальные платежные реквизиты в демо-данных не используются.
- `--reset` защищен и ограничен демо-хозяйствами `demo`/`demo-*`.
- Подробности реализации зафиксированы в `docs/demo-data.md`.

## 2026-05-18: PDF-Импорт Не Создает Квитанции

- Custom-импорт PDF "Заветы Мичурина" восстанавливает исходные доменные данные: участки, абонентов, связи, счетчики, показания, тарифы, социальные нормы, платежи, реквизиты и исторические posted-начисления.
- Импортированный PDF не является источником истины для `account_statements`; сохраненные квитанции КомУчета формируются только системой из собственных подтвержденных данных.
- QR-код и PDF КомУчета остаются свойствами системной snapshot-квитанции, а не импортированного внешнего документа.
- Итоговая сумма к оплате, напечатанная в импортируемом PDF, используется как контрольная сумма: система должна пересчитывать `начислено - оплачено` по импортированным строкам и показывать расхождение оператору.
- Подробности зафиксированы в `docs/zavety-michurina-import.md`.

## 2026-05-18: Знаковый Баланс Участка

- Пользовательский баланс участка считается как `active_payments - active_posted_accruals`.
- Положительный баланс означает переплату, нулевой - закрытый баланс, отрицательный - задолженность.
- Сумма к оплате не равна балансу напрямую и считается отдельно: `amount_to_pay = max(-balance, 0)`.
- Переплата считается как `overpayment_amount = max(balance, 0)`.
- Внутренние поля `balance_amount` в динамических statement и snapshot-квитанциях используют эту знаковую семантику.

## 2026-05-18: Терминология Портала По Балансу И Квитанциям

- В личном кабинете абонента динамический read-model по начислениям, оплатам и балансу называется "Баланс и операции".
- Маршрут динамического экрана в портале: `/portal/accounts/{uuid}/balance`; старый `/portal/accounts/{uuid}/statement` не оставляется как legacy, потому что продукт еще не был выпущен.
- Слово "Квитанция" в портале используется только для сохраненных active `account_statements`, сформированных системой.
- Сохраненные квитанции доступны абоненту отдельными маршрутами `/portal/accounts/{uuid}/statements/{statementUuid}` и `/portal/accounts/{uuid}/statements/{statementUuid}/pdf`.
- Если долг есть, но snapshot-квитанция еще не сформирована, портал показывает долг и прямо сообщает, что квитанции для оплаты пока нет.

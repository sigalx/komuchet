# Схема Таблиц

Документ описывает согласованную PostgreSQL-схему для MVP.

Синтаксис остается документационным SQL, а не единственным источником миграций. Фактическая реализация находится в Doctrine-сущностях и ручных PostgreSQL-миграциях в `migrations/`.

Статус реализации на 2026-05-08: базовый persistence-каркас MVP создан для auth/workspaces, абонентов и участков, счетчиков и показаний, тарифов, правил диапазонов потребления, начислений, оплат, настроек биллинга и audit log. Docker Compose dev-окружение добавлено, миграции применены к PostgreSQL 18.3 в Docker.

## Правила По Умолчанию

Если не указано иное, доменная таблица имеет:

```sql
uuid uuid primary key default uuidv7(),
created_at timestamptz not null default clock_timestamp(),
updated_at timestamptz not null default clock_timestamp(),
created_by uuid null references users(uuid),
updated_by uuid null references users(uuid)
```

Если таблица поддерживает soft-delete, добавляются:

```sql
deleted_at timestamptz null,
deleted_by uuid null references users(uuid)
```

UUID-колонки называются по смыслу: primary key - `uuid`, обычный foreign key - `<entity>_uuid`. Lifecycle/audit actor-поля с суффиксом `_by` являются исключением и пишутся без `_uuid`: `created_by`, `updated_by`, `deleted_by`, `changed_by` и т.п. Прочие ссылки на пользователя используют `_uuid`, например `actor_user_uuid`.

Для `created_at` и `updated_at` используется общий `before insert or update` trigger. Подробные правила описаны в [database-design.md](database-design.md).

## Обзор Связей

```text
workspaces 1..N subscribers
workspaces 1..N accounts
workspaces 1..N account_groups
workspaces 1..N electricity_tariff_profiles
workspaces 1..N electricity_tariff_zones
workspaces 1..N electricity_consumption_bands
workspaces 1..N billing_runs
workspaces 1..N accruals
workspaces 1..N payments
workspaces 1..N payment_requisite_profiles
workspaces 1..1 billing_settings
users 1..N user_email_identities
users 1..1 user_password_credentials
users 1..N user_password_history
workspaces N..M users via workspace_user_role_assignments
users 0..1 ---- 0..1 subscribers
subscribers 1..N ---- N..1 accounts via subscriber_account_accesses
accounts 1..N electricity_meters
electricity_meters N..M electricity_tariff_zones via electricity_meter_registers
electricity_meter_registers 1..N electricity_meter_readings
electricity_tariff_profiles 1..N account_electricity_tariff_profile_assignments
electricity_tariff_profiles 1..N electricity_tariff_periods
electricity_tariff_periods 1..N electricity_tariff_rates
electricity_tariff_zones 1..N electricity_tariff_rates
electricity_consumption_bands 1..N electricity_tariff_rates
electricity_tariff_profiles 1..N electricity_consumption_band_rules
electricity_consumption_band_rules 1..N electricity_consumption_band_rule_ranges
electricity_consumption_bands 1..N electricity_consumption_band_rule_ranges
accounts 1..N accruals
accruals 0..1 electricity_accrual_contexts
accruals 1..N electricity_accrual_registers
accruals 1..N electricity_accrual_lines
accounts 1..N payments
payment_requisite_profiles 1..N payment_requisite_assignments
accounts 1..N account_statements
billing_runs 1..N account_statements
account_statements 1..N account_statement_accruals
account_statements 1..N account_statement_payments
account_statements 1..N account_statement_electricity_registers
account_statements 1..N account_statement_electricity_lines
account_statements 1..N account_statement_deliveries
account_statement_deliveries 1..N account_statement_delivery_attempts
billing_runs 1..N accruals
billing_runs 1..N billing_run_account_issues
```

## Workspaces

Workspace - изолированный контур бизнес-данных внутри одного развертывания. В MVP может быть два workspace: основной и тестовый. Для бизнес-кода все workspace равнозначны; назначение вроде "боевой" или "песочница" задается человеком через `name` и `description`.

```sql
workspaces (
    uuid uuid primary key default uuidv7(),
    code text not null,
    name text not null,
    description text null,
    timezone text not null default 'Europe/Moscow',
    created_at timestamptz not null,
    updated_at timestamptz not null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    check (code <> ''),
    check (name <> ''),
    check (timezone <> '')
)
```

Индексы:

```sql
create unique index ux_workspaces_code
on workspaces (code);
```

Все бизнес-таблицы явно хранят `workspace_uuid`. Обычные auth-таблицы (`users`, email/password/role assignments) остаются глобальными. Если таблица ссылается на другую workspace-scoped таблицу, FK должен включать `workspace_uuid`, чтобы БД не позволяла связать данные из разных workspace.

`timezone` задает IANA timezone текущего workspace и используется для локального отображения абсолютных моментов времени (`timestamptz`) и календарных расчетов вроде даты формирования квитанций. Сама БД и приложение продолжают хранить абсолютные моменты как `timestamptz`.

Пример правила:

```sql
foreign key (workspace_uuid, account_uuid)
    references accounts(workspace_uuid, uuid)
```

Для таких FK у workspace-scoped таблиц с surrogate `uuid` добавляется уникальность:

```sql
unique (workspace_uuid, uuid)
```

## Auth И Доступы

Статус раздела: согласовано для MVP.

### users

Базовые учетные записи. Таблица хранит жизненный цикл пользователя, но не хранит пароль, роли и динамическую историю входов.

```sql
users (
    uuid uuid primary key default uuidv7(),
    created_at timestamptz not null,
    updated_at timestamptz not null,
    approved_at timestamptz null,
    approved_by uuid null references users(uuid),
    blocked_at timestamptz null,
    blocked_reason text null,
    blocked_by uuid null references users(uuid),
    deleted_at timestamptz null,
    deleted_by uuid null references users(uuid),
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    admin_granted_at timestamptz null,
    admin_granted_by uuid null references users(uuid),
    admin_revoked_at timestamptz null,
    admin_revoked_by uuid null references users(uuid),
    admin_revoked_reason text null,

    check (admin_revoked_at is null or admin_granted_at is not null),
    check (admin_revoked_at is null or admin_revoked_at >= admin_granted_at)
)
```

Индексы:

```sql
create index ix_users_lifecycle
on users (approved_at, blocked_at, deleted_at);

create index ix_users_admin_active
on users (admin_granted_at)
where admin_granted_at is not null and admin_revoked_at is null;
```

Логин разрешен только если:

- `approved_at is not null`;
- `blocked_at is null`;
- `deleted_at is null`;
- есть действующий пароль или другой включенный credential.

Ожидание одобрения выводится из `approved_at is null`.

Пользователь является глобальным администратором платформы, если `admin_granted_at is not null and admin_revoked_at is null`. Отдельный boolean `admin` не хранится: признак выводится из дат выдачи и отзыва. В UI выдача глобального админа на первом этапе не нужна; первый глобальный администратор создается через CLI или ручное серверное действие.

Статус: согласовано. Отдельное поле `status` не используется: состояние пользователя выводится из дат. `updated_at`/`updated_by` остаются, потому что `users` хранит изменяемый жизненный цикл учетной записи. Отдельный флажок `admin` не используется: глобальное администрирование выводится из `admin_granted_at`/`admin_revoked_at`.

### user_email_identities

Email-идентификаторы пользователя. Email хранится отдельно от `users`, чтобы поддержать подтверждение, отвязку, смену email и историю.

```sql
user_email_identities (
    user_uuid uuid not null references users(uuid),
    email text not null,
    email_normalized text not null,
    verified_at timestamptz null,
    created_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    check (email <> ''),
    check (email_normalized <> ''),
    primary key (user_uuid, email_normalized)
)
```

Индексы:

```sql
create unique index ux_user_email_identities_active_email
on user_email_identities (email_normalized)
where deleted_at is null;

create index ix_user_email_identities_user
on user_email_identities (user_uuid)
where deleted_at is null;
```

Soft-delete означает отвязку email. Отвязанная запись физически не удаляется, но partial unique index не мешает привязать тот же `email_normalized` другому пользователю.

MVP использует email + пароль. Телефон можно хранить как контакт абонента и позже подключить отдельной identity-таблицей.

Статус: согласовано. Один пользователь может иметь несколько активных email. Один активный email не может быть привязан к нескольким пользователям. Primary key - `(user_uuid, email_normalized)`, без surrogate `uuid`.

### user_password_credentials

Текущий локальный пароль пользователя.

```sql
user_password_credentials (
    user_uuid uuid primary key references users(uuid),
    password_hash text not null,
    changed_at timestamptz not null default clock_timestamp(),
    expires_at timestamptz null
)
```

`expires_at is null` означает, что пароль не протухает по времени.

Статус: согласовано. `user_password_credentials` хранит только текущее состояние credential. `changed_by` здесь не нужен: инициатор смены хранится в append-only `user_password_history`.

### user_password_history

Append-only история локальных паролей. Используется для аудита смены пароля и будущего запрета повторного использования старых паролей.

```sql
user_password_history (
    user_uuid uuid not null references users(uuid),
    password_hash text not null,
    changed_at timestamptz not null,
    changed_by uuid null references users(uuid),

    primary key (user_uuid, changed_at)
)
```

Индексы:

```sql
create index ix_user_password_history_changed_by
on user_password_history (changed_by, changed_at)
where changed_by is not null;
```

При каждой установке нового локального пароля приложение обновляет `user_password_credentials` и добавляет запись в `user_password_history`. Таблица append-only: записи не обновляются и не удаляются. `created_at` здесь не нужен: событие описывает `changed_at`.

Статус: согласовано. Таблица append-only, без surrogate `uuid`, без `created_at`/`updated_at`/`deleted_at`, без `expires_at`.

### workspace_user_role_code

PostgreSQL enum для административных ролей пользователя внутри конкретного workspace.

```sql
create type workspace_user_role_code as enum (
    'admin',
    'operator'
);
```

`admin` - администратор хозяйства: управляет настройками и может выдавать роли `admin` и `operator` в своем workspace.

`operator` - оператор хозяйства: работает с абонентами и данными workspace, но не выдает административные роли.

Обычный абонент не является ролью в этой таблице. Абонентский доступ к порталу выводится из активной связи `subscribers.user_uuid` внутри workspace.

Статус: согласовано. Начальный набор workspace-ролей: `admin`, `operator`.

### workspace_user_role_assignments

Связь пользователей и административных ролей внутри workspace с историей выдачи и отзыва.

```sql
workspace_user_role_assignments (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    user_uuid uuid not null references users(uuid),
    role_code workspace_user_role_code not null,
    granted_at timestamptz not null default clock_timestamp(),
    granted_by uuid null references users(uuid),
    revoked_at timestamptz null,
    revoked_by uuid null references users(uuid),
    revoked_reason text null,

    check (revoked_at is null or revoked_at >= granted_at)
)
```

Индексы:

```sql
create unique index ux_workspace_user_role_assignments_active
on workspace_user_role_assignments (workspace_uuid, user_uuid, role_code)
where revoked_at is null;

create index ix_workspace_user_role_assignments_user
on workspace_user_role_assignments (user_uuid, workspace_uuid)
where revoked_at is null;

create index ix_workspace_user_role_assignments_role
on workspace_user_role_assignments (workspace_uuid, role_code)
where revoked_at is null;
```

`uuid` нужен, чтобы админка могла адресовать конкретное назначение роли при отзыве без DateTime-части в ORM identity.

Эффективный доступ пользователя к workspace собирается из двух источников:

- админка хозяйства: активные строки `workspace_user_role_assignments`;
- абонентский портал: активная строка `subscribers` этого workspace, где `subscribers.user_uuid = users.uuid`.

Статус: согласовано. Технические `created_at`/`updated_at` и `created_by`/`updated_by` не нужны: история назначения роли описывается `granted_at`/`granted_by`, история отзыва - `revoked_at`/`revoked_by`.

### subscribers

Доменная сущность абонента СНТ.

```sql
subscribers (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    user_uuid uuid null references users(uuid),
    last_name text not null,
    first_name text not null,
    second_name text null,
    contact_email text null,
    contact_phone text null,
    notes text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid)
)
```

Индексы:

```sql
create unique index ux_subscribers_user_active
on subscribers (workspace_uuid, user_uuid)
where user_uuid is not null and deleted_at is null;

create index ix_subscribers_name
on subscribers (workspace_uuid, last_name, first_name, second_name)
where deleted_at is null;
```

`Subscriber` может существовать без `User`, если человек не пользуется личным кабинетом.

Статус: согласовано. ФИО хранится раздельно: `last_name`, `first_name`, `second_name`. `second_name` nullable. `full_name` не хранится как дублируемое поле.

### subscriber_account_access_role

PostgreSQL enum для роли абонента относительно участка.

```sql
create type subscriber_account_access_role as enum (
    'owner',
    'representative',
    'viewer'
);
```

### subscriber_account_accesses

Связь абонента с участком.

```sql
subscriber_account_accesses (
    workspace_uuid uuid not null references workspaces(uuid),
    subscriber_uuid uuid not null,
    account_uuid uuid not null,
    access_role subscriber_account_access_role not null default 'owner',
    granted_at timestamptz not null default clock_timestamp(),
    granted_by uuid null references users(uuid),
    revoked_at timestamptz null,
    revoked_by uuid null references users(uuid),
    revoked_reason text null,
    notes text null,

    primary key (workspace_uuid, subscriber_uuid, account_uuid, granted_at),
    foreign key (workspace_uuid, subscriber_uuid)
        references subscribers(workspace_uuid, uuid),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid),
    check (revoked_at is null or revoked_at >= granted_at)
)
```

Индексы:

```sql
create unique index ux_subscriber_account_accesses_active
on subscriber_account_accesses (workspace_uuid, subscriber_uuid, account_uuid)
where revoked_at is null;

create index ix_subscriber_account_accesses_account
on subscriber_account_accesses (workspace_uuid, account_uuid)
where revoked_at is null;
```

История выдачи доступа хранится через `granted_at`/`granted_by`, история отзыва - через `revoked_at`/`revoked_by`/`revoked_reason`, поэтому технические `created_at`/`updated_at` и soft-delete здесь не нужны.

Статус: согласовано. Отдельный surrogate `uuid` не используется; primary key - `(workspace_uuid, subscriber_uuid, account_uuid, granted_at)`.

## Участки

### accounts

Участки СНТ, основной объект начислений.

```sql
accounts (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    number text not null,
    notes text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid)
)
```

Индексы:

```sql
create unique index ux_accounts_number_active
on accounts (workspace_uuid, number)
where deleted_at is null;
```

Номер участка хранится текстом. В текущем СНТ это номер формата `N-M`, но схема не должна зависеть от этого формата.

Статус: согласовано. Отдельное поле `status` не используется; активность определяется через `deleted_at is null`. Дополнительные кадастровые, площадные и адресные поля не входят в MVP.

## Группы Участков

Группы нужны для правил и отчетов по участкам. В MVP востребованы группы вроде `summer` и `year_round`: участки, используемые только летом, и участки круглогодичного проживания.

### account_groups

```sql
account_groups (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    code text not null,
    name text not null,
    description text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid)
)
```

Индексы:

```sql
create unique index ux_account_groups_code_active
on account_groups (workspace_uuid, code)
where deleted_at is null;
```

### account_group_members

```sql
account_group_members (
    workspace_uuid uuid not null references workspaces(uuid),
    account_group_uuid uuid not null,
    account_uuid uuid not null,
    valid_from date not null,
    valid_to date null,
    created_by uuid null references users(uuid),

    primary key (workspace_uuid, account_group_uuid, account_uuid, valid_from),
    foreign key (workspace_uuid, account_group_uuid)
        references account_groups(workspace_uuid, uuid),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid),
    check (valid_to is null or valid_to > valid_from)
)
```

Индексы:

```sql
create index ix_account_group_members_account
on account_group_members (workspace_uuid, account_uuid, valid_from, valid_to);

create unique index ux_account_group_members_active
on account_group_members (workspace_uuid, account_group_uuid, account_uuid)
where valid_to is null;
```

Статус: согласовано. Категории групп в MVP не вводятся. `account_group_members` не имеет surrogate `uuid`; primary key - `(workspace_uuid, account_group_uuid, account_uuid, valid_from)`. Технические `created_at`/`updated_at` не нужны: период членства задается `valid_from`/`valid_to`.

## Электросчетчики И Показания

### electricity_meters

Электросчетчики, установленные на участках.

```sql
electricity_meters (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    account_uuid uuid not null,
    serial_number text null,
    model text null,
    installed_on date not null,
    removed_on date null,
    verified_on date null,
    verification_valid_until date null,
    notes text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid),

    check (removed_on is null or removed_on >= installed_on),
    check (
        verification_valid_until is null
        or verified_on is null
        or verification_valid_until >= verified_on
    )
)
```

Индексы:

```sql
create unique index ux_electricity_meters_one_active_per_account
on electricity_meters (workspace_uuid, account_uuid)
where removed_on is null and deleted_at is null;

create index ix_electricity_meters_account
on electricity_meters (workspace_uuid, account_uuid, installed_on);
```

MVP предполагает один активный электросчетчик на участок. История замен поддерживается через несколько записей счетчиков.

Статус: согласовано. `serial_number` и `model` nullable. `status` не используется. Начальное и финальное показания не хранятся в `electricity_meters`; они должны быть событиями в `electricity_meter_readings`. Поверка хранится через `verified_on` и `verification_valid_until`.

### electricity_meter_registers

Регистры электросчетчика. Таблица фиксирует, какие тарифные зоны есть у конкретного счетчика.

```sql
electricity_meter_registers (
    workspace_uuid uuid not null references workspaces(uuid),
    electricity_meter_uuid uuid not null,
    tariff_zone_uuid uuid not null,

    primary key (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid),
    foreign key (workspace_uuid, electricity_meter_uuid)
        references electricity_meters(workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_zone_uuid)
        references electricity_tariff_zones(workspace_uuid, uuid)
)
```

Индексы:

```sql
create index ix_electricity_meter_registers_zone
on electricity_meter_registers (workspace_uuid, tariff_zone_uuid);
```

Однотарифный счетчик имеет один регистр `single`. Двухтарифный счетчик может иметь регистры `day` и `night`. Трехтарифный счетчик может иметь регистры `peak`, `half_peak` и `night`.

Таблица immutable: регистры создаются вместе со счетчиком, не редактируются и не удаляются. Если счетчик создан ошибочно, soft-delete выполняется на `electricity_meters`; если счетчик заменен, создается новый счетчик с новым набором регистров.

В миграции нужно добавить `before update` и `before delete` triggers, которые запрещают изменение строк `electricity_meter_registers`.

### electricity_meter_readings

События передачи показаний электросчетчика.

```sql
create type electricity_meter_reading_source as enum (
    'subscriber',
    'admin',
    'import'
);

electricity_meter_readings (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    electricity_meter_uuid uuid not null,
    tariff_zone_uuid uuid not null,
    reading_value numeric(14, 3) not null,
    taken_on date not null,
    submitted_at timestamptz not null default clock_timestamp(),
    source electricity_meter_reading_source not null,
    submitted_by uuid null references users(uuid),
    provided_by_subscriber_uuid uuid null,

    replacing_reading_uuid uuid null,
    replaced_at timestamptz null,
    replaced_by uuid null references users(uuid),
    replacement_reason text null,

    cancelled_at timestamptz null,
    cancelled_by uuid null references users(uuid),
    cancellation_reason text null,

    notes text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    unique (workspace_uuid, uuid),
    check (reading_value >= 0),
    check (replacing_reading_uuid is null or replacing_reading_uuid <> uuid),
    check (
        replacing_reading_uuid is null
        or (replaced_at is not null and replacement_reason is not null)
    ),
    check (
        cancelled_at is null
        or cancellation_reason is not null
    ),
    check (
        not (cancelled_at is not null and replacing_reading_uuid is not null)
    ),
    foreign key (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid)
        references electricity_meter_registers(workspace_uuid, electricity_meter_uuid, tariff_zone_uuid),
    foreign key (workspace_uuid, provided_by_subscriber_uuid)
        references subscribers(workspace_uuid, uuid),
    foreign key (workspace_uuid, replacing_reading_uuid)
        references electricity_meter_readings(workspace_uuid, uuid)
)
```

Индексы:

```sql
create index ix_electricity_meter_readings_active_meter_zone_taken
on electricity_meter_readings (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid, taken_on, submitted_at)
where cancelled_at is null and replacing_reading_uuid is null;

create unique index ux_electricity_meter_readings_replacing
on electricity_meter_readings (workspace_uuid, replacing_reading_uuid)
where replacing_reading_uuid is not null;

create index ix_electricity_meter_readings_provider
on electricity_meter_readings (workspace_uuid, provided_by_subscriber_uuid, submitted_at)
where provided_by_subscriber_uuid is not null;
```

Показание не является месячной строкой. За месяц может быть несколько активных показаний. Расчет выбирает подходящее показание по правилам из [electricity-calculation.md](electricity-calculation.md).

Показание относится не просто к счетчику, а к конкретной тарифной зоне счетчика. Составной foreign key запрещает передать показание в зону, которой у счетчика нет.

Активная запись определяется условием `cancelled_at is null and replacing_reading_uuid is null`.

Исправление предпочтительно делать не переписыванием значения, а созданием новой записи и установкой `replacing_reading_uuid` в старой записи. Отмена ошибочного показания без замены фиксируется через `cancelled_at`, `cancelled_by` и `cancellation_reason`.

## Тарифы И Социальные Нормы

### electricity_tariff_profiles

Тарифные профили. Профиль описывает категорию тарификации участка.

```sql
electricity_tariff_profiles (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    code text not null,
    name text not null,
    description text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid)
)
```

Индексы:

```sql
create unique index ux_electricity_tariff_profiles_code_active
on electricity_tariff_profiles (workspace_uuid, code)
where deleted_at is null;
```

Примеры профилей: `snt`, `urban`, `rural`, `electric_heating`.

### account_electricity_tariff_profile_assignments

История назначения тарифного профиля участку.

```sql
account_electricity_tariff_profile_assignments (
    workspace_uuid uuid not null references workspaces(uuid),
    account_uuid uuid not null,
    tariff_profile_uuid uuid not null,
    valid_from date not null,
    valid_to date null,
    assigned_at timestamptz not null default clock_timestamp(),
    assigned_by uuid null references users(uuid),
    notes text null,

    primary key (workspace_uuid, account_uuid, valid_from),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_profile_uuid)
        references electricity_tariff_profiles(workspace_uuid, uuid),
    check (valid_to is null or valid_to > valid_from)
)
```

Индексы:

```sql
create index ix_account_electricity_tariff_profile_assignments_profile
on account_electricity_tariff_profile_assignments (workspace_uuid, tariff_profile_uuid, valid_from, valid_to);
```

Назначения одного участка не должны пересекаться внутри workspace. В миграции нужно использовать exclusion constraint по `workspace_uuid`, `account_uuid` и `daterange(valid_from, coalesce(valid_to, 'infinity'::date), '[)')`.

В MVP всем участкам можно назначить профиль `snt`, но история назначений оставляет возможность будущих исключений.

### electricity_tariff_zones

Тарифные зоны или регистры тарифа. Время действия зоны внутри суток в системе не хранится: абонент передает готовые показания по регистрам счетчика.

```sql
electricity_tariff_zones (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    code text not null,
    name text not null,
    description text null,
    sort_order integer not null default 100,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid)
)
```

Индексы:

```sql
create unique index ux_electricity_tariff_zones_code_active
on electricity_tariff_zones (workspace_uuid, code)
where deleted_at is null;
```

Примеры зон: `single`, `day`, `night`, `peak`, `half_peak`.

### electricity_consumption_bands

Диапазоны потребления, для которых могут действовать разные ставки.

```sql
electricity_consumption_bands (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    code text not null,
    name text not null,
    description text null,
    sort_order integer not null default 100,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid)
)
```

Индексы:

```sql
create unique index ux_electricity_consumption_bands_code_active
on electricity_consumption_bands (workspace_uuid, code)
where deleted_at is null;
```

Примеры диапазонов: `social_norm`, `above_social_norm`, `range_1`, `range_2`, `range_3`.

Таблица только именует тарифный диапазон. Размер социальной нормы и правила распределения потребления по диапазонам задаются `electricity_consumption_band_rules` и расчетным кодом.

### electricity_tariff_periods

Периоды действия тарифной сетки внутри тарифного профиля.

```sql
electricity_tariff_periods (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    tariff_profile_uuid uuid not null,
    valid_from date not null,
    valid_to date null,
    source_document text null,
    notes text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_profile_uuid)
        references electricity_tariff_profiles(workspace_uuid, uuid),

    check (valid_to is null or valid_to > valid_from)
)
```

Индексы:

```sql
create unique index ux_electricity_tariff_periods_profile_from_active
on electricity_tariff_periods (workspace_uuid, tariff_profile_uuid, valid_from)
where deleted_at is null;

create index ix_electricity_tariff_periods_profile_period
on electricity_tariff_periods (workspace_uuid, tariff_profile_uuid, valid_from, valid_to)
where deleted_at is null;
```

Активные тарифные периоды не должны пересекаться внутри одного тарифного профиля и workspace. В миграции нужно использовать exclusion constraint по `workspace_uuid`, `tariff_profile_uuid` и `daterange(valid_from, coalesce(valid_to, 'infinity'::date), '[)')` для записей, где `deleted_at is null`.

Для exclusion constraint по UUID и диапазону дат в PostgreSQL понадобится расширение `btree_gist`.

### electricity_tariff_rates

Ставки тарифов. Ставка определяется комбинацией периода, тарифной зоны и диапазона потребления.

```sql
electricity_tariff_rates (
    workspace_uuid uuid not null references workspaces(uuid),
    tariff_period_uuid uuid not null,
    tariff_zone_uuid uuid not null,
    consumption_band_uuid uuid not null,
    rate numeric(12, 6) not null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    primary key (workspace_uuid, tariff_period_uuid, tariff_zone_uuid, consumption_band_uuid),
    foreign key (workspace_uuid, tariff_period_uuid)
        references electricity_tariff_periods(workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_zone_uuid)
        references electricity_tariff_zones(workspace_uuid, uuid),
    foreign key (workspace_uuid, consumption_band_uuid)
        references electricity_consumption_bands(workspace_uuid, uuid),
    check (rate >= 0)
)
```

Индексы:

```sql
create index ix_electricity_tariff_rates_zone_band
on electricity_tariff_rates (workspace_uuid, tariff_zone_uuid, consumption_band_uuid);
```

В MVP это может быть две ставки для зоны `single`: `social_norm` и `above_social_norm`. Для двухтарифного счетчика ставки задаются отдельно для `day` и `night`.

### electricity_consumption_band_allocation_method

PostgreSQL enum для способа распределения потребления по диапазонам.

```sql
create type electricity_consumption_band_allocation_method as enum (
    'total_proportional',
    'per_tariff_zone'
);
```

`total_proportional` означает, что диапазоны считаются по общему потреблению участка, затем распределяются по тарифным зонам пропорционально. `per_tariff_zone` означает, что каждая тарифная зона раскладывается по диапазонам отдельно.

Для MVP с зоной `single` методы дают одинаковый результат.

### electricity_consumption_band_rules

Правила распределения потребления по диапазонам. Социальная норма является частным случаем такого правила.

```sql
electricity_consumption_band_rules (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    tariff_profile_uuid uuid not null,
    valid_from date not null,
    valid_to date null,
    month smallint not null,
    allocation_method electricity_consumption_band_allocation_method not null default 'total_proportional',
    priority integer not null default 100,
    source_document text null,
    notes text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_profile_uuid)
        references electricity_tariff_profiles(workspace_uuid, uuid),

    check (valid_to is null or valid_to > valid_from),
    check (month between 1 and 12)
)
```

Индексы:

```sql
create index ix_electricity_consumption_band_rules_profile_month_period
on electricity_consumption_band_rules (workspace_uuid, tariff_profile_uuid, month, valid_from, valid_to)
where deleted_at is null;
```

Правило выбирается по тарифному профилю участка, месяцу расчетного периода, периоду действия и области применения. Чем меньше `priority`, тем выше приоритет. Если для участка найдено несколько применимых правил с одинаковым приоритетом, это ошибка настройки.

### electricity_consumption_band_rule_ranges

Диапазоны внутри правила.

```sql
electricity_consumption_band_rule_ranges (
    workspace_uuid uuid not null references workspaces(uuid),
    rule_uuid uuid not null,
    consumption_band_uuid uuid not null,
    lower_bound_kwh numeric(14, 3) not null,
    upper_bound_kwh numeric(14, 3) null,

    primary key (workspace_uuid, rule_uuid, consumption_band_uuid),
    foreign key (workspace_uuid, rule_uuid)
        references electricity_consumption_band_rules(workspace_uuid, uuid),
    foreign key (workspace_uuid, consumption_band_uuid)
        references electricity_consumption_bands(workspace_uuid, uuid),
    check (lower_bound_kwh >= 0),
    check (upper_bound_kwh is null or upper_bound_kwh > lower_bound_kwh)
)
```

В миграции нужно добавить exclusion constraint, запрещающий пересечение диапазонов внутри одного правила:

```sql
exclude using gist (
    workspace_uuid with =,
    rule_uuid with =,
    numrange(lower_bound_kwh, upper_bound_kwh, '[)') with &&
)
```

Для exclusion constraint по UUID и `numrange` в PostgreSQL понадобится расширение `btree_gist`.

Пример социальной нормы:

```text
social_norm        0      150
above_social_norm  150    infinity
```

### electricity_consumption_band_rule_scope_mode

PostgreSQL enum для режима области применения правила.

```sql
create type electricity_consumption_band_rule_scope_mode as enum (
    'include',
    'exclude'
);
```

### electricity_consumption_band_rule_all_scopes

Область применения правила: все участки.

```sql
electricity_consumption_band_rule_all_scopes (
    workspace_uuid uuid not null references workspaces(uuid),
    rule_uuid uuid not null,
    mode electricity_consumption_band_rule_scope_mode not null default 'include',

    primary key (workspace_uuid, rule_uuid),
    foreign key (workspace_uuid, rule_uuid)
        references electricity_consumption_band_rules(workspace_uuid, uuid)
)
```

### electricity_consumption_band_rule_group_scopes

Область применения правила: группа участков.

```sql
electricity_consumption_band_rule_group_scopes (
    workspace_uuid uuid not null references workspaces(uuid),
    rule_uuid uuid not null,
    account_group_uuid uuid not null,
    mode electricity_consumption_band_rule_scope_mode not null default 'include',

    primary key (workspace_uuid, rule_uuid, account_group_uuid),
    foreign key (workspace_uuid, rule_uuid)
        references electricity_consumption_band_rules(workspace_uuid, uuid),
    foreign key (workspace_uuid, account_group_uuid)
        references account_groups(workspace_uuid, uuid)
)
```

Индексы:

```sql
create index ix_electricity_consumption_band_rule_group_scopes_group
on electricity_consumption_band_rule_group_scopes (workspace_uuid, account_group_uuid);
```

### electricity_consumption_band_rule_account_scopes

Область применения правила: отдельный участок.

```sql
electricity_consumption_band_rule_account_scopes (
    workspace_uuid uuid not null references workspaces(uuid),
    rule_uuid uuid not null,
    account_uuid uuid not null,
    mode electricity_consumption_band_rule_scope_mode not null default 'include',

    primary key (workspace_uuid, rule_uuid, account_uuid),
    foreign key (workspace_uuid, rule_uuid)
        references electricity_consumption_band_rules(workspace_uuid, uuid),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid)
)
```

Индексы:

```sql
create index ix_electricity_consumption_band_rule_account_scopes_account
on electricity_consumption_band_rule_account_scopes (workspace_uuid, account_uuid);
```

Для MVP достаточно правила с all-scope. Группы и исключения заложены для будущих случаев.

## Начисления

Для финансово значимых данных действует append-only принцип: опубликованные начисления, оплаты и использованные в расчетах показания не перетираются молча. Исправления выполняются через `superseded`, `cancelled`, `replacing_*` и audit log.

### billing_run_kind

PostgreSQL enum для типа расчетного запуска.

```sql
create type billing_run_kind as enum (
    'electricity'
);
```

### billing_runs

Событие расчета начислений за период.

```sql
billing_runs (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    kind billing_run_kind not null,
    period_start date not null,
    period_end date not null,
    generated_at timestamptz not null default clock_timestamp(),
    generated_by uuid null references users(uuid),
    accruals_generated_at timestamptz null,
    accruals_generated_by uuid null references users(uuid),
    posted_at timestamptz null,
    posted_by uuid null references users(uuid),
    cancelled_at timestamptz null,
    cancelled_by uuid null references users(uuid),
    cancellation_reason text null,

    unique (workspace_uuid, uuid),
    check (period_end > period_start),
    check (accruals_generated_at is null or accruals_generated_at >= generated_at),
    check (posted_at is null or posted_at >= generated_at),
    check (posted_at is null or (accruals_generated_at is not null and posted_at >= accruals_generated_at)),
    check (cancelled_at is null or cancelled_at >= generated_at),
    check (cancelled_at is null or cancellation_reason is not null),
    check (not (posted_at is not null and cancelled_at is not null))
)
```

Индексы:

```sql
create index ix_billing_runs_kind_period
on billing_runs (workspace_uuid, kind, period_start, period_end);

create unique index ux_billing_runs_active_kind_period
on billing_runs (workspace_uuid, kind, period_start, period_end)
where cancelled_at is null;
```

`billing_runs` нужен, чтобы формирование начислений 5-го числа было воспроизводимым событием. Состояние вычисляется по событиям: `cancelled_at is not null` - cancelled, `posted_at is not null` - posted, иначе draft.

`accruals_generated_*` фиксирует последний запуск генерации draft-начислений. Posting разрешен только после такого запуска и только если после него не менялись `billing_run_account_issues`.

Технические `created_*`/`updated_*`, `status`, `deleted_at` и `notes` не используются. `generated_*`, `accruals_generated_*`, `posted_*` и `cancelled_*` описывают жизненный цикл расчетного запуска.

### billing_run_account_issue_type

PostgreSQL enum для типа проблемы расчетного запуска по участку.

```sql
create type billing_run_account_issue_type as enum (
    'missing_reading',
    'stale_reading',
    'invalid_reading',
    'missing_tariff',
    'missing_consumption_band_rule',
    'calculation_error'
);
```

### billing_run_account_issue_close_reason

PostgreSQL enum для причины закрытия проблемы.

```sql
create type billing_run_account_issue_close_reason as enum (
    'resolved',
    'ignored',
    'cancelled_run',
    'obsolete'
);
```

### billing_run_account_issues

Проблемы по участкам при расчетном запуске.

```sql
billing_run_account_issues (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    billing_run_uuid uuid not null,
    account_uuid uuid not null,
    issue_type billing_run_account_issue_type not null,
    message text not null,
    closed_at timestamptz null,
    closed_by uuid null references users(uuid),
    close_reason billing_run_account_issue_close_reason null,
    close_comment text null,
    created_at timestamptz not null default clock_timestamp(),
    updated_at timestamptz not null default clock_timestamp(),
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    unique (workspace_uuid, uuid),
    foreign key (workspace_uuid, billing_run_uuid)
        references billing_runs(workspace_uuid, uuid),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid),
    check (
        (closed_at is null and closed_by is null and close_reason is null)
        or (closed_at is not null and close_reason is not null)
    )
)
```

Эта таблица поддерживает административный сценарий: перед формированием квитанций показать участки без актуальных показаний или с ошибками настройки. Состояние вычисляется по `closed_at`: `closed_at is null` - open, иначе closed with reason.

Индексы:

```sql
create unique index ux_billing_run_account_issues_open
on billing_run_account_issues (workspace_uuid, billing_run_uuid, account_uuid, issue_type)
where closed_at is null;

create index ix_billing_run_account_issues_account
on billing_run_account_issues (workspace_uuid, account_uuid, created_at);

create index ix_billing_run_account_issues_run
on billing_run_account_issues (workspace_uuid, billing_run_uuid);
```

### accrual_type

PostgreSQL enum для типа начисления.

```sql
create type accrual_type as enum (
    'electricity',
    'membership_fee',
    'water',
    'other'
);
```

### accruals

Обобщенные начисления по участку.

```sql
accruals (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    account_uuid uuid not null,
    billing_run_uuid uuid null,
    type accrual_type not null,
    period_start date not null,
    period_end date not null,
    amount numeric(14, 2) not null,
    posted_at timestamptz null,
    posted_by uuid null references users(uuid),
    replacing_accrual_uuid uuid null,
    replaced_at timestamptz null,
    replaced_by uuid null references users(uuid),
    replacement_reason text null,
    cancelled_at timestamptz null,
    cancelled_by uuid null references users(uuid),
    cancellation_reason text null,
    calculated_at timestamptz not null default clock_timestamp(),
    calculation_version text null,
    notes text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    unique (workspace_uuid, uuid),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid),
    foreign key (workspace_uuid, billing_run_uuid)
        references billing_runs(workspace_uuid, uuid),
    foreign key (workspace_uuid, replacing_accrual_uuid)
        references accruals(workspace_uuid, uuid),
    check (period_end > period_start),
    check (amount >= 0),
    check (posted_at is null or posted_at >= calculated_at),
    check (replacing_accrual_uuid is null or replacing_accrual_uuid <> uuid),
    check (
        replacing_accrual_uuid is null
        or (replaced_at is not null and replacement_reason is not null)
    ),
    check (cancelled_at is null or cancellation_reason is not null),
    check (not (cancelled_at is not null and replacing_accrual_uuid is not null))
)
```

Индексы:

```sql
create index ix_accruals_account_period
on accruals (workspace_uuid, account_uuid, period_start, period_end);

create unique index ux_accruals_one_posted_per_period
on accruals (workspace_uuid, account_uuid, type, period_start, period_end)
where posted_at is not null
  and cancelled_at is null
  and replacing_accrual_uuid is null;

create unique index ux_accruals_replacing
on accruals (workspace_uuid, replacing_accrual_uuid)
where replacing_accrual_uuid is not null;
```

Для ручных начислений MVP использует `membership_fee`, `water` и `other` без отдельной расчетной детализации. `electricity` резервируется для расчетных начислений электроэнергии.

Состояние вычисляется по событиям: `cancelled_at is not null` - cancelled, `replacing_accrual_uuid is not null` - superseded, `posted_at is not null` - posted, иначе draft. В балансе участвуют только active posted начисления.

### electricity_accrual_contexts

Контекст расчета электроэнергии для начисления.

```sql
electricity_accrual_contexts (
    workspace_uuid uuid not null references workspaces(uuid),
    accrual_uuid uuid not null,
    electricity_meter_uuid uuid not null,
    tariff_profile_uuid uuid not null,
    tariff_period_uuid uuid not null,
    consumption_band_rule_uuid uuid not null,
    created_at timestamptz not null default clock_timestamp(),

    primary key (workspace_uuid, accrual_uuid),
    foreign key (workspace_uuid, accrual_uuid)
        references accruals(workspace_uuid, uuid),
    foreign key (workspace_uuid, electricity_meter_uuid)
        references electricity_meters(workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_profile_uuid)
        references electricity_tariff_profiles(workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_period_uuid)
        references electricity_tariff_periods(workspace_uuid, uuid),
    foreign key (workspace_uuid, consumption_band_rule_uuid)
        references electricity_consumption_band_rules(workspace_uuid, uuid)
)
```

Контекст хранит примененные правила и справочники. Вычислимые итоги расхода и суммы здесь не дублируются.

### electricity_accrual_registers

Использованные расчетные показания по регистрам счетчика.

```sql
electricity_accrual_registers (
    workspace_uuid uuid not null references workspaces(uuid),
    accrual_uuid uuid not null,
    electricity_meter_uuid uuid not null,
    tariff_zone_uuid uuid not null,
    previous_reading_uuid uuid null,
    current_reading_uuid uuid not null,

    primary key (workspace_uuid, accrual_uuid, electricity_meter_uuid, tariff_zone_uuid),

    foreign key (workspace_uuid, accrual_uuid)
        references accruals(workspace_uuid, uuid),

    foreign key (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid)
        references electricity_meter_registers(workspace_uuid, electricity_meter_uuid, tariff_zone_uuid),

    foreign key (workspace_uuid, previous_reading_uuid, electricity_meter_uuid, tariff_zone_uuid)
        references electricity_meter_readings(workspace_uuid, uuid, electricity_meter_uuid, tariff_zone_uuid),

    foreign key (workspace_uuid, current_reading_uuid, electricity_meter_uuid, tariff_zone_uuid)
        references electricity_meter_readings(workspace_uuid, uuid, electricity_meter_uuid, tariff_zone_uuid),

    check (previous_reading_uuid is null or previous_reading_uuid <> current_reading_uuid)
)
```

Для composite FK на показания нужен уникальный индекс:

```sql
create unique index ux_electricity_meter_readings_uuid_meter_zone
on electricity_meter_readings (workspace_uuid, uuid, electricity_meter_uuid, tariff_zone_uuid);
```

Таблица фиксирует, какие immutable-показания использованы для расчета по каждому регистру. Значения показаний и расход не дублируются.

### electricity_accrual_lines

Финансовые строки детализации начисления электроэнергии.

```sql
electricity_accrual_lines (
    workspace_uuid uuid not null references workspaces(uuid),
    accrual_uuid uuid not null,
    tariff_zone_uuid uuid not null,
    consumption_band_uuid uuid not null,
    consumption_kwh numeric(14, 3) not null,
    rate numeric(12, 6) not null,
    amount numeric(14, 2) not null,

    primary key (workspace_uuid, accrual_uuid, tariff_zone_uuid, consumption_band_uuid),
    foreign key (workspace_uuid, accrual_uuid)
        references accruals(workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_zone_uuid)
        references electricity_tariff_zones(workspace_uuid, uuid),
    foreign key (workspace_uuid, consumption_band_uuid)
        references electricity_consumption_bands(workspace_uuid, uuid),

    check (consumption_kwh >= 0),
    check (rate >= 0),
    check (amount >= 0)
)
```

Строки начисления являются финансовым snapshot. `consumption_kwh`, `rate` и `amount` хранятся явно, потому что именно эти строки объясняют сумму квитанции.

## Оплаты

### payments

Ручные оплаты, привязанные к участку.

```sql
create type payment_source as enum (
    'manual',
    'import'
);

payments (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    account_uuid uuid not null,
    amount numeric(14, 2) not null,
    paid_on date not null,
    paid_at timestamptz null,
    source payment_source not null default 'manual',
    payer_name text null,
    purpose text null,
    external_reference text null,
    replacing_payment_uuid uuid null,
    replaced_at timestamptz null,
    replaced_by uuid null references users(uuid),
    replacement_reason text null,
    cancelled_at timestamptz null,
    cancelled_by uuid null references users(uuid),
    cancellation_reason text null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    unique (workspace_uuid, uuid),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid),
    foreign key (workspace_uuid, replacing_payment_uuid)
        references payments(workspace_uuid, uuid),
    check (amount > 0),
    check (paid_at is null or paid_at::date >= paid_on),
    check (replacing_payment_uuid is null or replacing_payment_uuid <> uuid),
    check (
        replacing_payment_uuid is null
        or (replaced_at is not null and replacement_reason is not null)
    ),
    check (cancelled_at is null or cancellation_reason is not null),
    check (not (cancelled_at is not null and replacing_payment_uuid is not null))
)
```

Индексы:

```sql
create index ix_payments_account_paid_on
on payments (workspace_uuid, account_uuid, paid_on);

create unique index ux_payments_replacing
on payments (workspace_uuid, replacing_payment_uuid)
where replacing_payment_uuid is not null;

create index ix_payments_external_reference
on payments (workspace_uuid, external_reference)
where external_reference is not null;
```

`paid_on` - бухгалтерская дата оплаты. `paid_at` заполняется только если источник дает точный абсолютный момент платежа.

Состояние вычисляется по событиям: `cancelled_at is not null` - cancelled, `replacing_payment_uuid is not null` - superseded, иначе posted.

Коррекция posted-оплаты выполняется через отмену старой записи или создание новой записи-замены. Старая запись указывает на новую через `replacing_payment_uuid`. Это нужно для переносов оплаты между участками и исправления ошибочно внесенных сумм.

Баланс участка вычисляется как:

```text
sum(active payments) - sum(active posted accruals)
```

Положительный баланс означает переплату, отрицательный - задолженность. Сумма к оплате вычисляется отдельно как положительная часть долга.

Прямой lifecycle-связи `payments` с квитанциями нет. Сохраненная квитанция/statement ссылается на оплаты через snapshot-строки и дополнительно хранит копию отображаемых данных.

## Платежные Реквизиты

### payment_requisite_profiles

Справочник банковских реквизитов внутри хозяйства. Не хранится прямо в `workspaces`, потому что реквизиты имеют собственную историю действия и могут отличаться по типам начислений в будущем.

```sql
payment_requisite_profiles (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    code text not null,
    name text not null,
    recipient_name text not null,
    recipient_inn text null,
    recipient_kpp text null,
    bank_name text not null,
    bank_bik text not null,
    bank_correspondent_account text null,
    bank_account text not null,
    payment_purpose_template text null,
    valid_from date not null,
    valid_to date null,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    deleted_at timestamptz null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),
    deleted_by uuid null references users(uuid),

    unique (workspace_uuid, uuid),
    check (code <> ''),
    check (name <> ''),
    check (recipient_name <> ''),
    check (bank_name <> ''),
    check (bank_bik <> ''),
    check (bank_account <> ''),
    check (valid_to is null or valid_to > valid_from)
)
```

Индексы:

```sql
create unique index ux_payment_requisite_profiles_code_active
on payment_requisite_profiles (workspace_uuid, code)
where deleted_at is null;

create index ix_payment_requisite_profiles_validity
on payment_requisite_profiles (workspace_uuid, valid_from, valid_to);
```

`payment_purpose_template` поддерживает подстановки `{statement_number}`, `{account_number}`, `{statement_date}`, `{amount_to_pay}`, `{workspace_name}`.

### payment_requisite_assignments

Назначение профиля реквизитов внутри хозяйства. `accrual_type null` означает реквизиты по умолчанию для всех квитанций. Если `accrual_type` заполнен, назначение применяется к квитанциям, где все строки начислений относятся к этому типу; если подходящего типового назначения нет, используется default-назначение.

```sql
payment_requisite_assignments (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    payment_requisite_profile_uuid uuid not null,
    accrual_type accrual_type null,
    valid_from date not null,
    valid_to date null,
    assigned_at timestamptz not null,
    assigned_by uuid null references users(uuid),
    closed_at timestamptz null,
    closed_by uuid null references users(uuid),
    close_reason text null,

    unique (workspace_uuid, uuid),
    foreign key (workspace_uuid, payment_requisite_profile_uuid)
        references payment_requisite_profiles(workspace_uuid, uuid),
    check (valid_to is null or valid_to > valid_from),
    check (
        (closed_at is null and close_reason is null)
        or (closed_at is not null and close_reason is not null)
    )
)
```

Индексы:

```sql
create unique index ux_payment_requisite_assignments_default_open
on payment_requisite_assignments (workspace_uuid)
where accrual_type is null and valid_to is null and closed_at is null;

create unique index ux_payment_requisite_assignments_type_open
on payment_requisite_assignments (workspace_uuid, accrual_type)
where accrual_type is not null and valid_to is null and closed_at is null;
```

## Квитанции / Statements

### account_statements

Сохраненный snapshot квитанции по участку.

```sql
account_statements (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    account_uuid uuid not null,
    billing_run_uuid uuid null,
    number text not null,
    workspace_name text not null,
    account_number text not null,
    statement_date date not null,
    generated_at timestamptz not null,
    generated_by uuid null references users(uuid),
    cancelled_at timestamptz null,
    cancelled_by uuid null references users(uuid),
    cancellation_reason text null,
    active_accrual_total numeric(14, 2) not null,
    active_payment_total numeric(14, 2) not null,
    balance_amount numeric(14, 2) not null,
    amount_to_pay numeric(14, 2) not null,
    overpayment_amount numeric(14, 2) not null,
    payment_requisite_profile_uuid uuid null,
    payment_recipient_name text null,
    payment_recipient_inn text null,
    payment_recipient_kpp text null,
    payment_bank_name text null,
    payment_bank_bik text null,
    payment_bank_correspondent_account text null,
    payment_bank_account text null,
    payment_purpose text null,

    unique (workspace_uuid, uuid),
    unique (workspace_uuid, number),
    foreign key (workspace_uuid, account_uuid)
        references accounts(workspace_uuid, uuid),
    foreign key (workspace_uuid, billing_run_uuid)
        references billing_runs(workspace_uuid, uuid),
    foreign key (workspace_uuid, payment_requisite_profile_uuid)
        references payment_requisite_profiles(workspace_uuid, uuid),
    check (number <> ''),
    check (workspace_name <> ''),
    check (account_number <> ''),
    check (active_accrual_total >= 0),
    check (active_payment_total >= 0),
    check (amount_to_pay >= 0),
    check (overpayment_amount >= 0),
    check (cancelled_at is null or cancellation_reason is not null)
)

create unique index ux_account_statements_active_billing_run_account
on account_statements (workspace_uuid, billing_run_uuid, account_uuid)
where billing_run_uuid is not null and cancelled_at is null;
```

`workspace_name` и `account_number` хранятся как snapshot-поля, чтобы уже сформированный документ не менялся после переименования хозяйства или участка.

`billing_run_uuid` заполняется для квитанций, сформированных массово из проведенного расчета. Ручные snapshot-квитанции из карточки участка могут оставаться без связи с расчетом.

Отмена квитанции заполняет `cancelled_at`, `cancelled_by`, `cancellation_reason`. После отмены квитанция остается в истории и может использоваться для аудита, но не может быть поставлена в новую активную доставку.

Платежные реквизиты также копируются в `account_statements`, чтобы сохраненная квитанция не менялась после правки справочника реквизитов. `payment_requisite_profile_uuid` остается только ссылкой для аудита источника.

### account_statement_accruals

Snapshot-строки начислений, вошедших в квитанцию.

```sql
account_statement_accruals (
    workspace_uuid uuid not null references workspaces(uuid),
    account_statement_uuid uuid not null,
    accrual_uuid uuid not null,
    type accrual_type not null,
    period_start date not null,
    period_end date not null,
    amount numeric(14, 2) not null,
    notes text null,
    sort_order integer not null,

    primary key (workspace_uuid, account_statement_uuid, accrual_uuid),
    foreign key (workspace_uuid, account_statement_uuid)
        references account_statements(workspace_uuid, uuid),
    foreign key (workspace_uuid, accrual_uuid)
        references accruals(workspace_uuid, uuid),
    check (period_end > period_start),
    check (amount >= 0),
    check (sort_order > 0)
)
```

Строка хранит ссылку на исходное начисление и копию ключевых отображаемых данных. Если исходное начисление позже заменено или отменено, snapshot-строка не меняется.

### account_statement_payments

Snapshot-строки оплат, вошедших в квитанцию.

```sql
account_statement_payments (
    workspace_uuid uuid not null references workspaces(uuid),
    account_statement_uuid uuid not null,
    payment_uuid uuid not null,
    amount numeric(14, 2) not null,
    paid_on date not null,
    source payment_source not null,
    payer_name text null,
    purpose text null,
    sort_order integer not null,

    primary key (workspace_uuid, account_statement_uuid, payment_uuid),
    foreign key (workspace_uuid, account_statement_uuid)
        references account_statements(workspace_uuid, uuid),
    foreign key (workspace_uuid, payment_uuid)
        references payments(workspace_uuid, uuid),
    check (amount > 0),
    check (sort_order > 0)
)
```

Строка хранит ссылку на исходную оплату и копию ключевых отображаемых данных. Если исходная оплата позже отменена или заменена, snapshot-строка не меняется.

### account_statement_electricity_registers

Snapshot показаний по регистрам счетчика, использованных в квитанции.

```sql
account_statement_electricity_registers (
    workspace_uuid uuid not null references workspaces(uuid),
    account_statement_uuid uuid not null,
    accrual_uuid uuid not null,
    electricity_meter_uuid uuid not null,
    tariff_zone_uuid uuid not null,
    tariff_zone_code text not null,
    tariff_zone_name text not null,
    electricity_meter_serial_number text null,
    electricity_meter_model text null,
    previous_reading_uuid uuid null,
    previous_reading_value numeric(14, 3) null,
    previous_reading_taken_on date null,
    current_reading_uuid uuid not null,
    current_reading_value numeric(14, 3) not null,
    current_reading_taken_on date not null,
    sort_order integer not null,

    primary key (
        workspace_uuid,
        account_statement_uuid,
        accrual_uuid,
        electricity_meter_uuid,
        tariff_zone_uuid
    ),
    foreign key (workspace_uuid, account_statement_uuid)
        references account_statements(workspace_uuid, uuid),
    foreign key (workspace_uuid, accrual_uuid)
        references accruals(workspace_uuid, uuid),
    foreign key (workspace_uuid, electricity_meter_uuid)
        references electricity_meters(workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_zone_uuid)
        references electricity_tariff_zones(workspace_uuid, uuid),
    foreign key (workspace_uuid, previous_reading_uuid)
        references electricity_meter_readings(workspace_uuid, uuid),
    foreign key (workspace_uuid, current_reading_uuid)
        references electricity_meter_readings(workspace_uuid, uuid),
    check (tariff_zone_code <> ''),
    check (tariff_zone_name <> ''),
    check (
        (previous_reading_uuid is null and previous_reading_value is null and previous_reading_taken_on is null)
        or (previous_reading_uuid is not null and previous_reading_value is not null and previous_reading_taken_on is not null)
    ),
    check (previous_reading_value is null or previous_reading_value >= 0),
    check (current_reading_value >= 0),
    check (sort_order > 0)
)
```

### account_statement_electricity_lines

Snapshot строк расчета электроэнергии.

```sql
account_statement_electricity_lines (
    workspace_uuid uuid not null references workspaces(uuid),
    account_statement_uuid uuid not null,
    accrual_uuid uuid not null,
    tariff_zone_uuid uuid not null,
    consumption_band_uuid uuid not null,
    tariff_zone_code text not null,
    tariff_zone_name text not null,
    consumption_band_code text not null,
    consumption_band_name text not null,
    consumption_kwh numeric(14, 3) not null,
    rate numeric(12, 6) not null,
    amount numeric(14, 2) not null,
    sort_order integer not null,

    primary key (
        workspace_uuid,
        account_statement_uuid,
        accrual_uuid,
        tariff_zone_uuid,
        consumption_band_uuid
    ),
    foreign key (workspace_uuid, account_statement_uuid)
        references account_statements(workspace_uuid, uuid),
    foreign key (workspace_uuid, accrual_uuid)
        references accruals(workspace_uuid, uuid),
    foreign key (workspace_uuid, tariff_zone_uuid)
        references electricity_tariff_zones(workspace_uuid, uuid),
    foreign key (workspace_uuid, consumption_band_uuid)
        references electricity_consumption_bands(workspace_uuid, uuid),
    check (tariff_zone_code <> ''),
    check (tariff_zone_name <> ''),
    check (consumption_band_code <> ''),
    check (consumption_band_name <> ''),
    check (consumption_kwh >= 0),
    check (rate >= 0),
    check (amount >= 0),
    check (sort_order > 0)
)
```

Эти таблицы копируют отображаемые значения из расчетного начисления. Если исходные показания, тарифные зоны или диапазоны позже изменятся административно, сохраненная квитанция останется прежней.

### account_statement_deliveries

Delivery job по сохраненной квитанции и конкретному получателю.

```sql
create type account_statement_delivery_channel as enum (
    'email'
);

account_statement_deliveries (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    account_statement_uuid uuid not null,
    recipient_subscriber_uuid uuid null,
    channel account_statement_delivery_channel not null default 'email',
    recipient_email text not null,
    recipient_email_normalized text not null,
    recipient_name text null,
    created_at timestamptz not null,
    created_by uuid null references users(uuid),
    cancelled_at timestamptz null,
    cancelled_by uuid null references users(uuid),
    cancellation_reason text null,

    unique (workspace_uuid, uuid),
    foreign key (workspace_uuid, account_statement_uuid)
        references account_statements(workspace_uuid, uuid),
    foreign key (workspace_uuid, recipient_subscriber_uuid)
        references subscribers(workspace_uuid, uuid),
    check (recipient_email <> ''),
    check (recipient_email_normalized <> ''),
    check (recipient_name is null or recipient_name <> ''),
    check (
        (cancelled_at is null and cancellation_reason is null)
        or (cancelled_at is not null and cancellation_reason is not null)
    )
)

create unique index ux_account_statement_deliveries_active_recipient
on account_statement_deliveries (
    workspace_uuid,
    account_statement_uuid,
    channel,
    recipient_email_normalized
)
where cancelled_at is null;
```

`recipient_email` и `recipient_name` являются snapshot-полями доставки: если абонент позже сменит контактный email или ФИО, уже созданная отправка останется воспроизводимой. `recipient_subscriber_uuid` сохраняется как ссылка на источник получателя.

При отмене квитанции активные доставки этой квитанции также получают `cancelled_at`, `cancelled_by`, `cancellation_reason`. Уже записанные попытки доставки при этом не меняются.

### account_statement_delivery_attempts

Попытки доставки. В MVP создается первая queued-попытка без реальной SMTP-отправки; будущий обработчик будет переводить ее в sending/sent/failed.

```sql
account_statement_delivery_attempts (
    workspace_uuid uuid not null references workspaces(uuid),
    delivery_uuid uuid not null,
    attempt_number integer not null,
    queued_at timestamptz not null,
    queued_by uuid null references users(uuid),
    started_at timestamptz null,
    succeeded_at timestamptz null,
    failed_at timestamptz null,
    failure_reason text null,
    provider_message_id text null,

    primary key (workspace_uuid, delivery_uuid, attempt_number),
    foreign key (workspace_uuid, delivery_uuid)
        references account_statement_deliveries(workspace_uuid, uuid),
    check (attempt_number > 0),
    check (succeeded_at is null or failed_at is null),
    check (failed_at is null or (failure_reason is not null and failure_reason <> '')),
    check (provider_message_id is null or provider_message_id <> '')
)
```

Статус попытки вычисляется из timestamp-полей: queued, если есть только `queued_at`; sending, если заполнен `started_at`; sent, если заполнен `succeeded_at`; failed, если заполнен `failed_at`. Отдельная status-колонка не хранится. Очередь SMTP-обработчика выбирает только попытки, у которых не отменена связанная доставка и не отменена связанная квитанция.

## Настройки Расчета

### billing_settings

Настройки расчета для одного workspace.

```sql
billing_settings (
    workspace_uuid uuid primary key references workspaces(uuid),
    association_name text not null,
    invoice_generation_day smallint not null default 5,
    reading_freshness_window_days integer not null default 15,
    created_at timestamptz not null,
    updated_at timestamptz not null,
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    check (association_name <> ''),
    check (invoice_generation_day between 1 and 28),
    check (reading_freshness_window_days between 1 and 60)
)
```

Каждый workspace имеет собственные настройки биллинга. Для MVP в одном развертывании можно создать основной workspace и тестовый workspace.

## Custom Импорт Исторических Квитанций

Эти таблицы относятся к custom-компоненту СНТ "Заветы Мичурина" и не являются универсальной моделью импорта для всех хозяйств. Они хранят промежуточный результат разбора PDF-квитанций, но не создают участки, абонентов, показания, оплаты или начисления без отдельного подтверждения.

### zavety_michurina_statement_import_batches

Пачка загруженных PDF.

```sql
zavety_michurina_statement_import_batches (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    name text null,
    created_at timestamptz not null default clock_timestamp(),
    updated_at timestamptz not null default clock_timestamp(),
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    check (name is null or name <> '')
)
```

Индексы:

```sql
create unique index ux_zm_statement_import_batches_workspace_uuid
on zavety_michurina_statement_import_batches (workspace_uuid, uuid);

create index ix_zm_statement_import_batches_created
on zavety_michurina_statement_import_batches (workspace_uuid, created_at);
```

Статус пачки отдельно не хранится: он выводится из статусов файлов внутри пачки.

### zavety_michurina_statement_import_files

Результат разбора одного PDF или одного заранее извлеченного `pdftotext -layout` файла.

```sql
create type zavety_michurina_statement_import_file_status as enum (
    'pending',
    'parsed',
    'failed',
    'applied',
    'cancelled'
);

zavety_michurina_statement_import_files (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid not null references workspaces(uuid),
    batch_uuid uuid not null references zavety_michurina_statement_import_batches(uuid) on delete cascade,
    original_filename text not null,
    storage_key text null,
    source_sha256 text null,
    file_size_bytes integer null,
    parser_version text not null default 'zavety_michurina_pdf_v1',
    status zavety_michurina_statement_import_file_status not null default 'pending',
    parsed_result jsonb null,
    parse_error text null,
    detected_account_number text null,
    detected_subscriber_full_name text null,
    parsed_at timestamptz null,
    created_at timestamptz not null default clock_timestamp(),
    updated_at timestamptz not null default clock_timestamp(),
    created_by uuid null references users(uuid),
    updated_by uuid null references users(uuid),

    check (original_filename <> ''),
    check (storage_key is null or storage_key <> ''),
    check (source_sha256 is null or source_sha256 ~ '^[a-f0-9]{64}$'),
    check (file_size_bytes is null or file_size_bytes >= 0),
    check (parser_version <> ''),
    check (
        status <> 'parsed'
        or (parsed_result is not null and parsed_at is not null and parse_error is null)
    ),
    check (
        status <> 'failed'
        or parse_error is not null
    )
)
```

Индексы:

```sql
create unique index ux_zm_statement_import_files_workspace_uuid
on zavety_michurina_statement_import_files (workspace_uuid, uuid);

create unique index ux_zm_statement_import_files_batch_hash
on zavety_michurina_statement_import_files (workspace_uuid, batch_uuid, source_sha256);

create index ix_zm_statement_import_files_batch_status
on zavety_michurina_statement_import_files (workspace_uuid, batch_uuid, status);

create index ix_zm_statement_import_files_detected_account
on zavety_michurina_statement_import_files (workspace_uuid, detected_account_number)
where detected_account_number is not null;

create index ix_zm_statement_import_files_created
on zavety_michurina_statement_import_files (workspace_uuid, created_at);
```

`parsed_result` хранит dry-run JSON парсера. `detected_account_number` и `detected_subscriber_full_name` дублируют часть JSON осознанно: они нужны для быстрых списков, фильтров и ручного сопоставления.

## Audit Log

### audit_logs

Append-only журнал финансово и административно значимых изменений.

```sql
create type audit_log_source as enum (
    'app',
    'db',
    'import',
    'system'
);

audit_logs (
    uuid uuid primary key default uuidv7(),
    workspace_uuid uuid null references workspaces(uuid),
    occurred_at timestamptz not null default clock_timestamp(),
    actor_user_uuid uuid null references users(uuid),
    source audit_log_source not null default 'app',
    db_user text null,
    action text not null,
    entity_table text null,
    entity_uuid uuid null,
    entity_pk jsonb null,
    old_values jsonb null,
    new_values jsonb null,
    changed_fields text[] null,
    reason text null,
    request_id text null,
    ip_address inet null,
    user_agent text null,

    check (entity_uuid is null or entity_pk is null)
)
```

Индексы:

```sql
create index ix_audit_logs_entity_uuid
on audit_logs (entity_table, entity_uuid, occurred_at)
where entity_uuid is not null;

create index ix_audit_logs_actor_user
on audit_logs (actor_user_uuid, occurred_at)
where actor_user_uuid is not null;

create index ix_audit_logs_workspace_occurred
on audit_logs (workspace_uuid, occurred_at)
where workspace_uuid is not null;

create index ix_audit_logs_request
on audit_logs (request_id)
where request_id is not null;
```

`audit_logs` не имеет `updated_at` и soft-delete. Записи журнала не редактируются и не удаляются штатными сценариями; в миграции нужно добавить trigger, запрещающий `update` и `delete`.

`workspace_uuid` nullable: часть событий может относиться к глобальному auth/security-контексту. `entity_uuid` используется для таблиц с single UUID PK, `entity_pk` - для таблиц с composite PK. Секреты вроде password hash, TOTP secrets, session ids и токенов нельзя писать в `old_values`/`new_values`.

## Таблицы После MVP

Эти таблицы не нужны для первого этапа, но схема должна оставлять им место:

- `user_totp_credentials` - TOTP-двухфакторная аутентификация для администраторов.
- `user_phone_identities` - вход по телефону, если понадобится.
- `user_external_identities` - вход через VK, Яндекс, Госуслуги и другие внешние провайдеры.
- `user_sessions` - управляемые пользовательские сессии, если понадобятся список активных устройств, отзыв сессий или выход со всех устройств.
- `user_login_events` - security audit попыток входа, если понадобятся отдельный аудит неуспешных/pending/blocked входов и brute-force анализ.
- `account_cadastral_objects` - кадастровые объекты, связанные с участком `Account`, включая земельные участки и потенциально объекты капитального строительства.
- `payment_import_batches` - импорт бухгалтерских сводок.
- `payment_import_rows` - сырые строки банковских/бухгалтерских импортов.
- `invoices` - сформированные квитанции.
- `invoice_delivery_attempts` - отправка квитанций по email.
- `membership_fee_rules` - правила членских взносов.
- `water_connection_charges` - годовая плата за воду.

## Открытые Схемные Вопросы

На текущий момент блокирующих схемных вопросов для MVP нет.

Решения:

- UI для групп и исключений правил диапазонов потребления не обязателен в MVP; достаточно глобальных норм.
- TOTP не входит в MVP-схему и рассматривается как вероятное развитие функционала аутентификации.

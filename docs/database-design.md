# Проектирование Базы Данных

Документ фиксирует базовые правила PostgreSQL-схемы.

## Общие Принципы

- PostgreSQL является источником истины для доменных данных.
- Доменная целостность должна обеспечиваться не только приложением, но и ограничениями БД.
- Ручные исправления напрямую в БД должны быть видны по техническим audit-полям.
- Финансовые значения нельзя хранить в floating point типах.
- Бизнес-идентификаторы не должны использоваться как primary key, если они могут измениться.

## Primary Keys

По умолчанию primary key всех доменных таблиц:

```sql
uuid uuid primary key default uuidv7()
```

PostgreSQL 18 поддерживает генерацию UUIDv7. UUIDv7 предпочтителен для новых таблиц, потому что он time-ordered и лучше ведет себя в B-tree индексах, чем случайный UUIDv4.

Если нужно поддержать PostgreSQL ниже 18, fallback:

```sql
uuid uuid primary key default gen_random_uuid()
```

Натуральные бизнес-ключи оформляются как `unique` ограничения, а не как PK, если они потенциально могут измениться.

Примеры:

- номер участка `Account.number` - `unique`, но не PK;
- email пользователя - `unique`, но не PK;
- серийный номер счетчика - может быть `unique` в рамках нужной области, но не PK.

Исключения возможны только для настоящих неизменяемых справочных кодов или технических join-таблиц, если это явно упрощает модель. Для таблиц с audit-полями и soft-delete предпочтительно все равно иметь UUID PK.

## Workspace-Scoping

`workspaces` задают изолированные контуры бизнес-данных внутри одного развертывания: например, основной контур и тестовый контур.

Все бизнес-таблицы явно хранят `workspace_uuid`. Auth-таблицы остаются глобальными.

Связи между workspace-scoped таблицами должны включать `workspace_uuid` в foreign key:

```sql
foreign key (workspace_uuid, account_uuid)
    references accounts(workspace_uuid, uuid)
```

Для workspace-scoped таблиц с surrogate `uuid` добавляется дополнительная уникальность:

```sql
unique (workspace_uuid, uuid)
```

Это нужно не для уникальности UUID как таковой, а чтобы БД могла проверять составные FK и не позволяла связать данные из разных workspace.

## Время

Для абсолютного момента времени использовать:

```sql
timestamp with time zone
```

или PostgreSQL-алиас:

```sql
timestamptz
```

Примеры абсолютных моментов:

- `created_at`;
- `updated_at`;
- `deleted_at`;
- `submitted_at`;
- `recorded_at`;
- `logged_at`;
- будущие события входа или сессии, если будет добавлен security audit.

`timestamp without time zone` не использовать для событий, которые обозначают момент на временной шкале.

PostgreSQL хранит `timestamptz` как UTC-момент и выводит его в timezone текущей сессии. Поэтому на уровне приложения и подключений к БД нужно держать timezone `UTC`, а локальное отображение делать на уровне UI через IANA timezone текущего workspace.

Для календарных дат без времени использовать `date`.

Примеры календарных дат:

- дата установки счетчика, если точное время неизвестно;
- дата фактического снятия показания, если абонент вводит только дату;
- начало и конец расчетного периода;
- дата действия тарифа или нормы.

Если в будущем нужно будет знать и дату, и точный момент отправки, хранить оба поля:

```sql
taken_on date not null,
submitted_at timestamptz not null
```

## Денежные Значения И Количества

Деньги хранить в `numeric`, не в `float`, `real` или `double precision`.

Предварительное правило:

```sql
amount numeric(14, 2) not null
```

Тарифы могут иметь больше знаков после запятой:

```sql
rate numeric(12, 6) not null
```

Показания и потребление электроэнергии хранить как точные числа. Для текущих бытовых счетчиков допустимо:

```sql
reading_value numeric(14, 3) not null
consumption_kwh numeric(14, 3) not null
```

Если будет подтверждено, что все счетчики целочисленные, можно сузить до integer/bigint, но начинать лучше с `numeric`.

## Audit-Поля В Доменных Таблицах

UUID-колонки называются по смыслу:

- primary key: `uuid`;
- обычные foreign key: `<entity>_uuid`, например `user_uuid`, `account_uuid`;
- lifecycle/audit actor-поля с суффиксом `_by`: без `_uuid`, например `created_by`, `updated_by`, `deleted_by`, `changed_by`;
- прочие ссылки на пользователя, где `_by` не используется: с `_uuid`, например `actor_user_uuid`.

Большинство доменных таблиц должны иметь технические audit-поля:

```sql
created_at timestamptz not null default clock_timestamp(),
updated_at timestamptz not null default clock_timestamp(),
deleted_at timestamptz null,
created_by uuid null references users(uuid),
updated_by uuid null references users(uuid),
deleted_by uuid null references users(uuid)
```

`deleted_at` и `deleted_by` добавляются только там, где применим soft-delete.

Таблицы, где soft-delete обычно применим:

- пользователи;
- абоненты;
- участки;
- счетчики;
- тарифы;
- правила диапазонов потребления, включая социальные нормы.

Таблицы, где soft-delete может быть лишним:

- append-only audit log;
- чистые связки, если история доступа хранится отдельно;
- финансовые начисления и оплаты, где исправления выполняются через `cancelled_at` или `replacing_*`;
- показания, где исправления выполняются через `cancelled_at` или `replacing_reading_uuid`;
- временные/технические таблицы импорта.

## Триггеры Audit-Полей

`created_at` и `updated_at` должны выставляться на уровне БД, а не только приложением.

Минимальная функция:

```sql
create or replace function set_row_timestamps()
returns trigger
language plpgsql
as $$
begin
    if tg_op = 'INSERT' then
        new.created_at = coalesce(new.created_at, clock_timestamp());
        new.updated_at = coalesce(new.updated_at, new.created_at);
    elsif tg_op = 'UPDATE' then
        new.updated_at = clock_timestamp();
    end if;

    return new;
end;
$$;
```

Пример подключения:

```sql
create trigger trg_users_timestamps
before insert or update on users
for each row execute function set_row_timestamps();
```

`created_by`, `updated_by`, `deleted_by` приложение должно выставлять явно на основе текущего пользователя. Для ручных SQL-исправлений эти поля могут остаться `null`, но `updated_at` все равно изменится триггером.

Позже можно добавить контекст текущего пользователя через session variable:

```sql
set local app.current_user_uuid = '<uuid>';
```

и триггер, который будет читать:

```sql
current_setting('app.current_user_uuid', true)
```

Это позволит заполнять `updated_by` на уровне БД для операций приложения.

## Soft Delete

Soft-delete означает:

```sql
deleted_at is not null
```

Для таблиц с soft-delete запросы приложения по умолчанию должны фильтровать:

```sql
deleted_at is null
```

Уникальные ограничения для soft-delete таблиц обычно должны быть частичными:

```sql
create unique index ux_accounts_number_active
on accounts (workspace_uuid, number)
where deleted_at is null;
```

Так можно архивировать запись и создать новую с тем же бизнес-идентификатором, если это допустимо по предметной области.

## Аудит Финансово Значимых Изменений

Технические поля `created_at` / `updated_at` не заменяют полноценный audit log.

Базовый принцип: данные, которые уже повлияли или могут повлиять на деньги, нельзя удалять или перетирать. Исправления выполняются новыми записями, статусами и ссылками на заменяемые записи.

Для финансово значимых сущностей нужен отдельный журнал изменений:

- показания электросчетчиков;
- тарифы;
- правила диапазонов потребления, включая социальные нормы;
- начисления;
- платежи;
- привязки абонентов к участкам;
- изменения ролей пользователей.

Журнал должен хранить:

- таблицу и идентификатор записи;
- тип действия;
- момент действия `timestamptz`;
- пользователя, если известен;
- старое значение;
- новое значение;
- комментарий или причину изменения, если указана.

Предпочтительный формат старого и нового значения:

```sql
jsonb
```

## Имена Таблиц И Полей

Предварительный стиль PostgreSQL:

- таблицы: `snake_case`, во множественном числе;
- поля: `snake_case`;
- UUID primary key: `uuid`;
- UUID foreign key: `<entity>_uuid`;
- индексы: `ix_<table>_<columns>`;
- уникальные индексы: `ux_<table>_<columns>`;
- внешние ключи: `fk_<table>_<column>`.

Примеры:

- `users`;
- `subscribers`;
- `accounts`;
- `electricity_meters`;
- `electricity_meter_readings`;
- `electricity_tariff_periods`.

## Индексы

Индексы нужны не только на PK.

Базовые индексы:

- все foreign key поля;
- поля поиска в админке: номер участка, email, телефон, ФИО;
- временные поля, по которым строится история;
- активные записи soft-delete таблиц через partial index.

Для истории показаний:

```sql
create index ix_electricity_meter_readings_active_meter_zone_taken
on electricity_meter_readings (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid, taken_on, submitted_at)
where cancelled_at is null and replacing_reading_uuid is null;
```

Для платежей:

```sql
create index ix_payments_account_paid_on
on payments (workspace_uuid, account_uuid, paid_on);
```

## Ограничения Целостности

Предпочитать явные ограничения:

- `not null` для обязательных полей;
- `check` для неотрицательных сумм и показаний;
- `unique` для бизнес-уникальности;
- `foreign key` для связей;
- `exclude` constraints при необходимости запрета пересекающихся периодов.

Примеры:

```sql
check (amount >= 0)
check (reading_value >= 0)
check (valid_to is null or valid_to > valid_from)
```

Для тарифных периодов и правил диапазонов потребления пересечения запрещаются на уровне БД там, где это влияет на однозначность расчета.

## Источники

- PostgreSQL 18: UUID type: https://www.postgresql.org/docs/current/datatype-uuid.html
- PostgreSQL 18: UUID functions: https://www.postgresql.org/docs/current/functions-uuid.html
- PostgreSQL 18: date/time types: https://www.postgresql.org/docs/current/datatype-datetime.html
- PostgreSQL 18: trigger functions: https://www.postgresql.org/docs/18/plpgsql-trigger.html

# Admin Users

Документ фиксирует текущий административный раздел `Пользователи`.

Цель раздела: дать администратору хозяйства рабочий инструмент управления глобальными учетными записями входа и связью этих учетных записей с абонентами текущего хозяйства.

## Текущее Состояние Модели

`User` является глобальной учетной записью входа. Он не привязан к `workspace_uuid`.

Вход по email реализован через:

- `UserEmailProvider` - ищет активный подтвержденный email в `user_email_identities`;
- `UserAccountChecker` - запрещает вход для pending, blocked и deleted users;
- `User::getRoles()` - возвращает `ROLE_ADMIN`, если у пользователя активны `admin_granted_at` и `admin_revoked_at is null`;
- `WorkspaceAccessVoter` - дает доступ в `/admin` глобальному администратору, workspace-admin и workspace-operator текущего workspace;
- `workspace_user_role_assignments` - хранит роли `admin` и `operator` внутри конкретного workspace;
- `user_password_credentials` - хранит текущий password hash;
- `user_password_history` - append-only история установки паролей.

Состояние `User` выводится из дат:

- `pending` - `approved_at is null`, `deleted_at is null`;
- `active` - `approved_at is not null`, `blocked_at is null`, `deleted_at is null`;
- `blocked` - `blocked_at is not null`, `deleted_at is null`;
- `deleted` - `deleted_at is not null`.

`Subscriber` имеет nullable `user_uuid`. В БД есть unique index `ux_subscribers_user_active` по `(workspace_uuid, user_uuid)` для активных абонентов. Это позволяет одному `User` иметь максимум одного активного `Subscriber` внутри workspace.

Связь `User` и `Subscriber` реализована в админке с обеих сторон: из карточки пользователя можно выбрать абонента текущего workspace, а из карточки абонента можно выбрать пользователя без активной связи в этом workspace.

Права раздела разделены:

- просмотр списка и карточки пользователей доступен по `WORKSPACE_ACCESS`, то есть глобальному администратору, администратору хозяйства и оператору хозяйства;
- управление identity-действиями доступно только по `WORKSPACE_ADMIN`: создание пользователя, email identities, lifecycle пользователя, установка пароля, прямое связывание `User` с `Subscriber` из карточки пользователя и управление ролями хозяйства;
- оператор хозяйства может подключать абонентский портал из карточки `Subscriber`, но не управляет учетной записью напрямую из раздела пользователей.

## Границы Реализации

Входит:

- список пользователей;
- карточка пользователя;
- создание пользователя администратором;
- отображение глобального admin-состояния;
- блокировка и разблокировка;
- soft-delete;
- добавление и отвязка email identities;
- установка или сброс локального пароля;
- связь и отвязка `User` с `Subscriber` текущего workspace;
- выдача абонентского доступа к порталу по email из карточки `Subscriber`;
- выдача доступа администратора/оператора текущего workspace по email из списка пользователей;
- выдача и отзыв workspace-ролей `admin` и `operator`.

Не входит в текущий раздел:

- публичная саморегистрация;
- безопасная передача временных паролей и уведомления о новом workspace;
- TOTP;
- внешние провайдеры входа;
- управляемые пользовательские сессии;
- отдельный security audit попыток входа;
- UI управления глобальным admin-признаком платформы.

## Список Пользователей

Маршрут: `GET /admin/users`.

Колонки:

- email - primary active email или UUID, если email нет;
- состояние: pending, active, blocked, deleted;
- глобальный админ: да/нет;
- роли текущего workspace;
- связан ли с абонентом текущего workspace;
- создан;
- действия.

Фильтры:

- email;
- глобальный админ;
- состояние;
- наличие связи с абонентом текущего workspace.

По умолчанию список показывает неудаленных пользователей. Удаленных можно включить фильтром состояния.

## Создание Пользователя

Маршрут: `GET|POST /admin/users/new`.

Поля формы:

- email;
- пароль;
- повтор пароля;
- `approved` checkbox, по умолчанию включен.

Поведение:

- email нормализуется через `UserEmailIdentity::normalizeEmail`;
- активный email должен быть уникален;
- для созданного администратором пользователя email считается verified;
- если `approved` включен, вызывается `User::approve($currentUser)`;
- пароль хешируется через `UserPasswordHasherInterface`;
- текущий hash пишется в `user_password_credentials`;
- тот же hash пишется в `user_password_history` с `changed_by = current user`;
- после submit redirect идет на карточку созданного пользователя.

Минимальная длина пароля должна совпадать с CLI-командой `app:user:create-admin`: 12 символов.

## Карточка Пользователя

Маршрут: `GET /admin/users/{uuid}`.

Блоки:

- lifecycle: UUID, created/updated, approved, blocked, deleted;
- глобальное admin-состояние;
- связанный `Subscriber` текущего workspace, если есть;
- роли текущего workspace с историей выдачи и отзыва;
- active и deleted email identities;
- password credential: `changed_at`, `expires_at`, без password hash;
- действия доступа.

Password hash, TOTP secrets, session ids и токены никогда не выводятся.

## Связь С Абонентом

Маршруты:

- `POST /admin/users/{uuid}/subscriber/link`;
- `POST /admin/users/{uuid}/subscriber/unlink`;
- `POST /admin/subscribers/{uuid}/user/unlink`.

Поведение:

- связь хранится в `subscribers.user_uuid`;
- выбирать можно только активного абонента без связанного пользователя в текущем workspace;
- из карточки пользователя выбирать можно только неудаленного пользователя без активного абонента в текущем workspace;
- из карточки абонента подключение делается через email-сценарий выдачи доступа к порталу;
- отвязка очищает `subscribers.user_uuid`;
- `subscribers.updated_at` и `updated_by` обновляются при связке и отвязке.

Прямая связь и отвязка из карточки пользователя требуют `WORKSPACE_ADMIN`. Подключение портала из карточки абонента остается рабочим действием оператора хозяйства.

## Выдача Доступа К Порталу

Маршрут: `POST /admin/subscribers/{uuid}/portal-access/grant`.

Поведение:

- оператор вводит email в карточке абонента;
- если активный `UserEmailIdentity` уже существует, его `User` привязывается к `Subscriber` текущего workspace;
- если `User` не существует, создается approved `User`, verified email identity, временный пароль и связь с `Subscriber`;
- если существующий `User` не имеет локального пароля, временный пароль создается для него;
- временный пароль получает `expires_at = '1970-01-01 00:00:00+00'`;
- временный пароль показывается оператору один раз во flash-сообщении;
- пароль не записывается в `audit_logs`.

Ограничения:

- нельзя подключить email заблокированного или удаленного пользователя;
- нельзя подключить пользователя, уже связанного с другим активным `Subscriber` текущего workspace;
- один активный `Subscriber` может быть связан только с одним `User`.

## Workspace-Роли

Маршруты:

- `POST /admin/users/workspace-access/grant`;
- `POST /admin/users/{uuid}/workspace-roles/grant`;
- `POST /admin/users/{uuid}/workspace-roles/{assignmentUuid}/revoke`.

Поведение:

- из списка пользователей администратор может ввести email и роль; если `User` не существует, он создается как побочный эффект;
- новому пользователю создается verified email identity и временный протухший пароль;
- если существующий `User` не имеет локального пароля, ему также выдается временный протухший пароль;
- роли хранятся в `workspace_user_role_assignments`;
- доступны роли `admin` и `operator`;
- активная роль определяется как `revoked_at is null`;
- повторно выдать ту же активную роль одному пользователю в том же workspace нельзя;
- отзыв роли не удаляет запись физически, а заполняет `revoked_at`, `revoked_by`, `revoked_reason`;
- выдача и отзыв пишутся в `audit_logs`.

Ограничения:

- управлять workspace-ролями может только глобальный администратор или администратор текущего workspace;
- оператор текущего workspace может работать в админке, но не видит формы назначения ролей и не может вызвать grant/revoke маршруты;
- нельзя отозвать последнюю активную роль `admin` текущего workspace.

## Email Identities

Добавление email:

- маршрут `POST /admin/users/{uuid}/emails/add`;
- email должен быть валиден;
- активный `email_normalized` должен быть уникален;
- для MVP email, добавленный администратором, сразу помечается verified;
- `created_by = current user`.

Отвязка email:

- маршрут `POST /admin/users/{uuid}/emails/{emailNormalized}/delete`;
- запись не удаляется физически, вызывается soft-delete;
- `deleted_by = current user`;
- после отвязки email освобождается для привязки другому пользователю.

Ограничение: нельзя отвязать последний активный email у пользователя, если у него есть локальный пароль и он не deleted.

## Блокировка И Разблокировка

Блокировка:

- маршрут `POST /admin/users/{uuid}/block`;
- обязательная причина;
- вызывает `User::block($reason, $currentUser)`;
- заблокированный пользователь не может войти.

Разблокировка:

- маршрут `POST /admin/users/{uuid}/unblock`;
- вызывает `User::unblock($currentUser)`.

Ограничения безопасности:

- администратор не может заблокировать самого себя;
- нельзя заблокировать последнего login-allowed глобального администратора.

## Soft-Delete

Маршрут: `POST /admin/users/{uuid}/delete`.

Поведение:

- вызывает `User::delete($currentUser)`;
- удаленный пользователь не может войти;
- активные email identities пользователя также soft-delete, чтобы email можно было переиспользовать.

Ограничения безопасности:

- администратор не может удалить самого себя;
- нельзя удалить последнего login-allowed глобального администратора.

## Установка Или Сброс Пароля

Маршрут: `POST /admin/users/{uuid}/password/set`.

Поля формы:

- новый пароль;
- повтор пароля;
- optional `expires_at`.

Поведение:

- пароль хешируется через `UserPasswordHasherInterface`;
- если `user_password_credentials` нет, создается новая запись;
- если запись есть, обновляется `password_hash`, `changed_at`, `expires_at`;
- append-only запись добавляется в `user_password_history`;
- `changed_by = current user`.

Письма со ссылками сброса пароля не входят в первую реализацию. Администратор вручную задает временный пароль и сообщает его пользователю вне системы.

## Следующее Развитие

Следующие самостоятельные улучшения раздела:

- уведомления о доступе к новому workspace;
- безопасный транспорт временных паролей вместо показа оператору;
- UI управления глобальным admin-признаком платформы;
- отдельный экран просмотра `audit_logs`.

## Тестовый Минимум

Functional-тесты:

- anonymous redirect на `/login`;
- список пользователей доступен админу;
- создание пользователя с email и password;
- нельзя создать пользователя с занятым active email;
- карточка пользователя не показывает password hash;
- одобрение pending-пользователя;
- блокировка и разблокировка;
- нельзя заблокировать себя;
- soft-delete пользователя;
- soft-delete освобождает email;
- установка нового пароля пишет `user_password_history`;
- связь и отвязка пользователя с абонентом текущего workspace;
- workspace-admin может входить в админку без глобального admin-признака;
- workspace-operator может входить в админку и смотреть пользователей, но не может управлять identity-действиями и ролями;
- глобальный администратор может выдать и отозвать workspace-роль с записью audit log.

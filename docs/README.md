# Документация

Эта папка содержит рабочую документацию проекта. Документы должны развиваться вместе с пониманием предметной области.

## Разделы

- [decisions.md](decisions.md) - журнал принятых проектных решений.
- [domain.md](domain.md) - доменная модель: роли, сущности, связи.
- [auth-and-access.md](auth-and-access.md) - учетные записи, роли, вход и выдача доступа.
- [admin-permissions.md](admin-permissions.md) - матрица прав административного интерфейса.
- [admin-users.md](admin-users.md) - проект административного раздела пользователей.
- [processes.md](processes.md) - описание текущих ручных процессов и целевого состояния.
- [mvp.md](mvp.md) - что входит и не входит в первую версию.
- [subscriber-portal.md](subscriber-portal.md) - реализованный MVP личного кабинета абонента.
- [demo-data.md](demo-data.md) - demo seed CLI для обучения и демонстраций.
- [account-statements.md](account-statements.md) - модель динамического баланса, snapshot-квитанций, PDF и доставки.
- [zavety-michurina-import.md](zavety-michurina-import.md) - custom-парсер исторических PDF-квитанций СНТ "Заветы Мичурина".
- [technical-stack.md](technical-stack.md) - выбранный стек и политика версий.
- [deployment.md](deployment.md) - стратегия CI/CD и production-развертывания.
- [database-design.md](database-design.md) - правила проектирования PostgreSQL-схемы.
- [database-schema.md](database-schema.md) - согласованная схема таблиц MVP.
- [legal-notes.md](legal-notes.md) - справочные заметки по 217-ФЗ и связи модели с участками.
- [electricity-calculation.md](electricity-calculation.md) - расчет потребления, тарифов, социальных норм и начислений.
- [samples/](samples/) - обезличенные образцы документов.

## Принцип Документирования

Документация должна отделять подтвержденные факты от предположений. Если правило известно только по примеру PDF или по наблюдению за ручным процессом, это нужно явно отмечать.

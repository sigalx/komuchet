<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountGroup;
use App\Entity\AuditLog;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffZone;
use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Entity\Workspace;
use App\Enum\SubscriberAccountAccessRole;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class AdminDomainAuditTest extends FunctionalWebTestCase
{
    public function testAccountSubscriberAccessAndTariffAssignmentActionsWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $nextTariffProfile = $this->createTariffProfile($workspace, 'heating', 'Электроотопление');
        $client->loginUser($admin);

        $client->request('GET', '/admin/accounts/new');
        $client->submitForm('Сохранить', [
            'account[number]' => '9-123',
            'account[notes]' => 'Первичная карточка',
        ]);
        $account = $this->findAccountByNumber('9-123');

        self::assertInstanceOf(Account::class, $account);
        $accountCreatedLog = $this->findAuditLog('account.created', $account->getUuid());

        self::assertInstanceOf(AuditLog::class, $accountCreatedLog);
        self::assertSame('accounts', $accountCreatedLog->getEntityTable());
        self::assertSame('9-123', $accountCreatedLog->getNewValues()['number'] ?? null);

        $client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getUuid()));
        $client->submitForm('Сохранить', [
            'account[number]' => '9-124',
            'account[notes]' => 'После исправления',
        ]);
        $accountUpdatedLog = $this->findAuditLog('account.updated', $account->getUuid());

        self::assertInstanceOf(AuditLog::class, $accountUpdatedLog);
        self::assertSame('9-123', $accountUpdatedLog->getOldValues()['number'] ?? null);
        self::assertSame('9-124', $accountUpdatedLog->getNewValues()['number'] ?? null);

        $client->request('GET', '/admin/subscribers/new');
        $client->submitForm('Сохранить', [
            'subscriber[lastName]' => 'Иванов',
            'subscriber[firstName]' => 'Иван',
            'subscriber[secondName]' => 'Иванович',
            'subscriber[contactEmail]' => 'ivanov@example.test',
            'subscriber[contactPhone]' => '+7 900 000-00-00',
            'subscriber[notes]' => 'Первичная карточка',
        ]);
        $subscriber = $this->findSubscriberByEmail('ivanov@example.test');

        self::assertInstanceOf(Subscriber::class, $subscriber);
        $subscriberCreatedLog = $this->findAuditLog('subscriber.created', $subscriber->getUuid());

        self::assertInstanceOf(AuditLog::class, $subscriberCreatedLog);
        self::assertSame('Иванов Иван Иванович', $subscriberCreatedLog->getNewValues()['display_name'] ?? null);

        $client->request('GET', sprintf('/admin/subscribers/%s/edit', $subscriber->getUuid()));
        $client->submitForm('Сохранить', [
            'subscriber[lastName]' => 'Петров',
            'subscriber[firstName]' => 'Петр',
            'subscriber[secondName]' => 'Петрович',
            'subscriber[contactEmail]' => 'petrov@example.test',
            'subscriber[contactPhone]' => '+7 900 000-00-01',
            'subscriber[notes]' => 'После исправления',
        ]);
        $subscriberUpdatedLog = $this->findAuditLog('subscriber.updated', $subscriber->getUuid());

        self::assertInstanceOf(AuditLog::class, $subscriberUpdatedLog);
        self::assertSame('Иванов', $subscriberUpdatedLog->getOldValues()['last_name'] ?? null);
        self::assertSame('Петров', $subscriberUpdatedLog->getNewValues()['last_name'] ?? null);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $client->submitForm('Добавить абонента', [
            'account_subscriber_access_grant[subscriber]' => $subscriber->getUuid()->toRfc4122(),
            'account_subscriber_access_grant[accessRole]' => SubscriberAccountAccessRole::Owner->value,
            'account_subscriber_access_grant[notes]' => 'Проверены документы',
        ]);
        $accessGrantedLog = $this->findAuditLog('subscriber_account_access.granted');

        self::assertInstanceOf(AuditLog::class, $accessGrantedLog);
        self::assertSame($subscriber->getUuid()->toRfc4122(), $accessGrantedLog->getEntityPk()['subscriber_uuid'] ?? null);
        self::assertSame($account->getUuid()->toRfc4122(), $accessGrantedLog->getEntityPk()['account_uuid'] ?? null);
        self::assertSame(SubscriberAccountAccessRole::Owner->value, $accessGrantedLog->getNewValues()['access_role'] ?? null);

        $client->followRedirect();
        $client->submitForm('Отозвать');
        $accessRevokedLog = $this->findAuditLog('subscriber_account_access.revoked');

        self::assertInstanceOf(AuditLog::class, $accessRevokedLog);
        self::assertSame('Доступ отозван администратором через карточку участка.', $accessRevokedLog->getReason());
        self::assertNotNull($accessRevokedLog->getNewValues()['revoked_at'] ?? null);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $client->submitForm('Назначить профиль', [
            'account_electricity_tariff_profile_assign[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'account_electricity_tariff_profile_assign[validFrom]' => '01.05.2026',
            'account_electricity_tariff_profile_assign[notes]' => 'Основной тариф',
        ]);
        $assignmentCreatedLog = $this->findAuditLog('account_tariff_profile_assignment.created');

        self::assertInstanceOf(AuditLog::class, $assignmentCreatedLog);
        self::assertSame($account->getUuid()->toRfc4122(), $assignmentCreatedLog->getEntityPk()['account_uuid'] ?? null);
        self::assertSame($tariffProfile->getUuid()->toRfc4122(), $assignmentCreatedLog->getNewValues()['tariff_profile_uuid'] ?? null);

        $client->followRedirect();
        $client->submitForm('Назначить профиль', [
            'account_electricity_tariff_profile_assign[tariffProfile]' => $nextTariffProfile->getUuid()->toRfc4122(),
            'account_electricity_tariff_profile_assign[validFrom]' => '01.06.2026',
            'account_electricity_tariff_profile_assign[notes]' => 'Новый тариф',
        ]);
        $assignmentClosedLog = $this->findAuditLog('account_tariff_profile_assignment.closed');

        self::assertInstanceOf(AuditLog::class, $assignmentClosedLog);
        self::assertNull($assignmentClosedLog->getOldValues()['valid_to'] ?? null);
        self::assertSame('2026-06-01', $assignmentClosedLog->getNewValues()['valid_to'] ?? null);

        $client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('account.deleted', $account->getUuid()));

        $client->request('GET', sprintf('/admin/subscribers/%s/edit', $subscriber->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('subscriber.deleted', $subscriber->getUuid()));
        self::assertSame($workspace->getUuid()->toRfc4122(), $accountCreatedLog->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($admin->getUuid()->toRfc4122(), $accountCreatedLog->getActorUser()?->getUuid()->toRfc4122());
    }

    public function testUserSubscriberLinkActionsWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $user = $this->createUser('link-target@example.test');
        $subscriber = $this->createSubscriber($workspace);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));
        $client->submitForm('Связать абонента', [
            'user_subscriber_link[subscriber]' => $subscriber->getUuid()->toRfc4122(),
        ]);
        $linkedLog = $this->findAuditLog('subscriber.user_linked', $subscriber->getUuid());

        self::assertInstanceOf(AuditLog::class, $linkedLog);
        self::assertNull($linkedLog->getOldValues()['user_uuid'] ?? null);
        self::assertSame($user->getUuid()->toRfc4122(), $linkedLog->getNewValues()['user_uuid'] ?? null);

        $client->followRedirect();
        $client->submitForm('Отвязать абонента');
        $unlinkedFromUserLog = $this->findAuditLog('subscriber.user_unlinked', $subscriber->getUuid());

        self::assertInstanceOf(AuditLog::class, $unlinkedFromUserLog);
        self::assertSame($user->getUuid()->toRfc4122(), $unlinkedFromUserLog->getOldValues()['user_uuid'] ?? null);
        self::assertNull($unlinkedFromUserLog->getNewValues()['user_uuid'] ?? null);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));
        $client->submitForm('Связать абонента', [
            'user_subscriber_link[subscriber]' => $subscriber->getUuid()->toRfc4122(),
        ]);
        $client->request('GET', sprintf('/admin/subscribers/%s', $subscriber->getUuid()));
        $client->submitForm('Отвязать пользователя');
        $unlinkedFromSubscriberLog = $this->findAuditLog('subscriber.user_unlinked', $subscriber->getUuid());

        self::assertInstanceOf(AuditLog::class, $unlinkedFromSubscriberLog);
        self::assertSame('Связь с пользователем удалена из карточки абонента.', $unlinkedFromSubscriberLog->getReason());
    }

    public function testAccountGroupActionsWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-groups/new');
        $client->submitForm('Сохранить', [
            'account_group[code]' => 'summer',
            'account_group[name]' => 'Летние участки',
            'account_group[description]' => 'Используются только летом',
        ]);
        $accountGroup = $this->findAccountGroupByCode('summer');

        self::assertInstanceOf(AccountGroup::class, $accountGroup);
        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('account_group.created', $accountGroup->getUuid()));

        $client->request('GET', sprintf('/admin/account-groups/%s/edit', $accountGroup->getUuid()));
        $client->submitForm('Сохранить', [
            'account_group[code]' => 'year_round',
            'account_group[name]' => 'Круглогодичные участки',
            'account_group[description]' => 'После исправления',
        ]);
        $groupUpdatedLog = $this->findAuditLog('account_group.updated', $accountGroup->getUuid());

        self::assertInstanceOf(AuditLog::class, $groupUpdatedLog);
        self::assertSame('summer', $groupUpdatedLog->getOldValues()['code'] ?? null);
        self::assertSame('year_round', $groupUpdatedLog->getNewValues()['code'] ?? null);

        $client->request('GET', sprintf('/admin/account-groups/%s', $accountGroup->getUuid()));
        $client->submitForm('Добавить участок', [
            'account_group_member_add[account]' => $account->getUuid()->toRfc4122(),
            'account_group_member_add[validFrom]' => '09.05.2026',
        ]);
        $memberAddedLog = $this->findAuditLog('account_group_member.added');

        self::assertInstanceOf(AuditLog::class, $memberAddedLog);
        self::assertSame($account->getUuid()->toRfc4122(), $memberAddedLog->getEntityPk()['account_uuid'] ?? null);
        self::assertSame('2026-05-09', $memberAddedLog->getEntityPk()['valid_from'] ?? null);

        $client->followRedirect();
        $client->submitForm('Исключить');
        $memberClosedLog = $this->findAuditLog('account_group_member.closed');

        self::assertInstanceOf(AuditLog::class, $memberClosedLog);
        self::assertNull($memberClosedLog->getOldValues()['valid_to'] ?? null);
        self::assertNotNull($memberClosedLog->getNewValues()['valid_to'] ?? null);

        $client->request('GET', sprintf('/admin/account-groups/%s/edit', $accountGroup->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('account_group.deleted', $accountGroup->getUuid()));
    }

    public function testElectricityMeterActionsWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meters/new');
        $client->submitForm('Сохранить', [
            'electricity_meter[account]' => $account->getUuid()->toRfc4122(),
            'electricity_meter[tariffZones]' => [$tariffZone->getUuid()->toRfc4122()],
            'electricity_meter[serialNumber]' => 'SN-001',
            'electricity_meter[model]' => 'Меркурий 201.5',
            'electricity_meter[installedOn]' => '01.05.2026',
            'electricity_meter[removedOn]' => '',
            'electricity_meter[verifiedOn]' => '20.04.2026',
            'electricity_meter[verificationValidUntil]' => '20.04.2036',
            'electricity_meter[notes]' => 'На опоре',
        ]);
        $meter = $this->findMeterBySerialNumber('SN-001');

        self::assertInstanceOf(ElectricityMeter::class, $meter);
        $meterCreatedLog = $this->findAuditLog('electricity_meter.created', $meter->getUuid());

        self::assertInstanceOf(AuditLog::class, $meterCreatedLog);
        self::assertSame('SN-001', $meterCreatedLog->getNewValues()['serial_number'] ?? null);
        self::assertSame('single', $meterCreatedLog->getNewValues()['tariff_zones'][0]['code'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-meters/%s/edit', $meter->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_meter[serialNumber]' => 'SN-002',
            'electricity_meter[model]' => 'Энергомера CE101',
            'electricity_meter[installedOn]' => '01.05.2026',
            'electricity_meter[removedOn]' => '01.06.2026',
            'electricity_meter[verifiedOn]' => '20.04.2026',
            'electricity_meter[verificationValidUntil]' => '20.04.2036',
            'electricity_meter[notes]' => 'После исправления',
        ]);
        $meterUpdatedLog = $this->findAuditLog('electricity_meter.updated', $meter->getUuid());

        self::assertInstanceOf(AuditLog::class, $meterUpdatedLog);
        self::assertSame('SN-001', $meterUpdatedLog->getOldValues()['serial_number'] ?? null);
        self::assertSame('SN-002', $meterUpdatedLog->getNewValues()['serial_number'] ?? null);
        self::assertSame('2026-06-01', $meterUpdatedLog->getNewValues()['removed_on'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-meters/%s/edit', $meter->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_meter.deleted', $meter->getUuid()));
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createSubscriber(Workspace $workspace): Subscriber
    {
        $subscriber = (new Subscriber($workspace))
            ->setLastName('Иванов')
            ->setFirstName('Иван')
            ->setSecondName('Иванович');

        $this->entityManager()->persist($subscriber);
        $this->entityManager()->flush();

        return $subscriber;
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->approve();

        $identity = new UserEmailIdentity($user, $email);
        $identity->markVerified();
        $user->addEmailIdentity($identity);

        $passwordHash = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, 'test-password-123');
        $credential = new UserPasswordCredential($user, $passwordHash, new DateTimeImmutable());
        $user->setPasswordCredential($credential);

        $this->entityManager()->persist($user);
        $this->entityManager()->persist($identity);
        $this->entityManager()->persist($credential);
        $this->entityManager()->flush();

        return $user;
    }

    private function createTariffProfile(Workspace $workspace, string $code, string $name): ElectricityTariffProfile
    {
        $tariffProfile = (new ElectricityTariffProfile($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($tariffProfile);
        $this->entityManager()->flush();

        return $tariffProfile;
    }

    private function createTariffZone(Workspace $workspace, string $code, string $name): ElectricityTariffZone
    {
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->flush();

        return $tariffZone;
    }

    private function findAccountByNumber(string $number): ?Account
    {
        return $this->entityManager()
            ->getRepository(Account::class)
            ->findOneBy(['number' => $number]);
    }

    private function findSubscriberByEmail(string $email): ?Subscriber
    {
        return $this->entityManager()
            ->getRepository(Subscriber::class)
            ->findOneBy(['contactEmail' => $email]);
    }

    private function findAccountGroupByCode(string $code): ?AccountGroup
    {
        return $this->entityManager()
            ->getRepository(AccountGroup::class)
            ->findOneBy(['code' => $code]);
    }

    private function findMeterBySerialNumber(string $serialNumber): ?ElectricityMeter
    {
        return $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->findOneBy(['serialNumber' => $serialNumber]);
    }

    private function findAuditLog(string $action, ?Uuid $entityUuid = null): ?AuditLog
    {
        $queryBuilder = $this->entityManager()
            ->getRepository(AuditLog::class)
            ->createQueryBuilder('auditLog')
            ->andWhere('auditLog.action = :action')
            ->setParameter('action', $action)
            ->orderBy('auditLog.occurredAt', 'DESC')
            ->addOrderBy('auditLog.uuid', 'DESC')
            ->setMaxResults(1);

        if ($entityUuid !== null) {
            $queryBuilder
                ->andWhere('auditLog.entityUuid = :entityUuid')
                ->setParameter('entityUuid', $entityUuid);
        }

        return $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();
    }
}

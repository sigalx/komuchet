<?php

namespace App\Tests;

use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use Symfony\Component\HttpFoundation\Response;

final class AdminPaymentRequisiteProfileControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/payment-requisite-profiles');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSortAndPaginatePaymentRequisiteProfilesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $profile = (new PaymentRequisiteProfile($workspace))
                ->setCode(sprintf('profile_%02d', $i))
                ->setName(sprintf('Реквизиты %02d', $i))
                ->setRecipientName(sprintf('Получатель %02d', $i))
                ->setRecipientInn('1234567890')
                ->setRecipientKpp('123456789')
                ->setBankName(sprintf('Банк %02d', $i))
                ->setBankBik('044525225')
                ->setBankCorrespondentAccount('30101810400000000225')
                ->setBankAccount(sprintf('40703810900000000%03d', $i));

            $this->entityManager()->persist($profile);
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/payment-requisite-profiles?sort=code&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $profile55Position = strpos($content, 'profile_55');
        $profile54Position = strpos($content, 'profile_54');
        self::assertNotFalse($profile55Position);
        self::assertNotFalse($profile54Position);
        self::assertLessThan($profile54Position, $profile55Position);
        $this->assertSelectorExists('a[href="/admin/payment-requisite-profiles?sort=code&dir=asc&page=1"]');

        $client->request('GET', '/admin/payment-requisite-profiles?sort=code&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'profile_05');
        $this->assertSelectorTextNotContains('body', 'profile_55');
    }

    public function testAdminCanCreateProfileAndAssignItAsDefault(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/payment-requisite-profiles/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'payment_requisite_profile[code]' => 'main',
            'payment_requisite_profile[name]' => 'Основные реквизиты',
            'payment_requisite_profile[recipientName]' => 'ТСН "Ромашка"',
            'payment_requisite_profile[recipientInn]' => '1234567890',
            'payment_requisite_profile[recipientKpp]' => '123456789',
            'payment_requisite_profile[bankName]' => 'ПАО Сбербанк',
            'payment_requisite_profile[bankBik]' => '044525225',
            'payment_requisite_profile[bankCorrespondentAccount]' => '30101810400000000225',
            'payment_requisite_profile[bankAccount]' => '40703810900000000001',
            'payment_requisite_profile[validFrom]' => '01.01.2026',
            'payment_requisite_profile[paymentPurposeTemplate]' => 'Оплата {statement_number}, участок {account_number}',
        ]);

        $profile = $this->entityManager()
            ->getRepository(PaymentRequisiteProfile::class)
            ->findOneBy(['code' => 'main']);

        self::assertInstanceOf(PaymentRequisiteProfile::class, $profile);
        $this->assertResponseRedirects(sprintf('/admin/payment-requisite-profiles/%s', $profile->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $profile->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame('ТСН "Ромашка"', $profile->getRecipientName());
        self::assertSame('40703810900000000001', $profile->getBankAccount());
        self::assertSame($admin->getUuid()->toRfc4122(), $profile->getCreatedBy()?->getUuid()->toRfc4122());

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Платежные реквизиты Основные реквизиты');
        $this->assertSelectorTextContains('body', 'ПАО Сбербанк');

        $client->submitForm('Назначить профиль', [
            'accrual_type' => '',
        ]);

        $assignment = $this->entityManager()
            ->getRepository(PaymentRequisiteAssignment::class)
            ->findOneBy(['paymentRequisiteProfile' => $profile, 'closedAt' => null]);

        self::assertInstanceOf(PaymentRequisiteAssignment::class, $assignment);
        self::assertNull($assignment->getAccrualType());
        self::assertSame($admin->getUuid()->toRfc4122(), $assignment->getAssignedBy()?->getUuid()->toRfc4122());
        $this->assertResponseRedirects(sprintf('/admin/payment-requisite-profiles/%s', $profile->getUuid()), Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Профиль используется в открытых назначениях');

        $client->request('GET', '/admin/payment-requisite-profiles');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Все начисления');
        $this->assertSelectorTextContains('body', 'Основные реквизиты');

        $client->request('GET', '/admin/payment-requisite-profiles/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'payment_requisite_profile[code]' => 'reserve',
            'payment_requisite_profile[name]' => 'Резервные реквизиты',
            'payment_requisite_profile[recipientName]' => 'ТСН "Резерв"',
            'payment_requisite_profile[recipientInn]' => '2234567890',
            'payment_requisite_profile[recipientKpp]' => '223456789',
            'payment_requisite_profile[bankName]' => 'ПАО Банк',
            'payment_requisite_profile[bankBik]' => '044525974',
            'payment_requisite_profile[bankCorrespondentAccount]' => '30101810145250000974',
            'payment_requisite_profile[bankAccount]' => '40703810900000000002',
            'payment_requisite_profile[validFrom]' => '01.01.2026',
        ]);

        $reserveProfile = $this->entityManager()
            ->getRepository(PaymentRequisiteProfile::class)
            ->findOneBy(['code' => 'reserve']);

        self::assertInstanceOf(PaymentRequisiteProfile::class, $reserveProfile);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $client->submitForm('Назначить профиль', [
            'accrual_type' => '',
        ]);

        $closedAssignment = $this->entityManager()
            ->getRepository(PaymentRequisiteAssignment::class)
            ->findOneBy(['paymentRequisiteProfile' => $profile]);
        $newAssignment = $this->entityManager()
            ->getRepository(PaymentRequisiteAssignment::class)
            ->findOneBy(['paymentRequisiteProfile' => $reserveProfile, 'closedAt' => null]);

        self::assertInstanceOf(PaymentRequisiteAssignment::class, $closedAssignment);
        self::assertNotNull($closedAssignment->getClosedAt());
        self::assertInstanceOf(PaymentRequisiteAssignment::class, $newAssignment);
    }

    public function testAdminCanAssignProfileForAccrualTypeAndCloseAssignment(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $electricityProfile = $this->createProfile(
            code: 'electricity',
            name: 'Реквизиты для света',
            recipientName: 'ТСН "Свет"',
            bankAccount: '40703810900000000003',
            workspace: $workspace,
            createdBy: $admin,
        );
        $reserveProfile = $this->createProfile(
            code: 'reserve-electricity',
            name: 'Резерв для света',
            recipientName: 'ТСН "Резерв Свет"',
            bankAccount: '40703810900000000004',
            workspace: $workspace,
            createdBy: $admin,
        );

        $client->request('GET', sprintf('/admin/payment-requisite-profiles/%s', $electricityProfile->getUuid()));
        $this->assertResponseIsSuccessful();
        $client->submitForm('Назначить профиль', [
            'accrual_type' => AccrualType::Electricity->value,
        ]);

        $assignment = $this->entityManager()
            ->getRepository(PaymentRequisiteAssignment::class)
            ->findOneBy(['paymentRequisiteProfile' => $electricityProfile, 'closedAt' => null]);

        self::assertInstanceOf(PaymentRequisiteAssignment::class, $assignment);
        $assignmentUuid = $assignment->getUuid();
        self::assertSame(AccrualType::Electricity, $assignment->getAccrualType());
        self::assertSame($admin->getUuid()->toRfc4122(), $assignment->getAssignedBy()?->getUuid()->toRfc4122());
        $this->assertResponseRedirects(sprintf('/admin/payment-requisite-profiles/%s', $electricityProfile->getUuid()), Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Электроэнергия');

        $client->request('GET', sprintf('/admin/payment-requisite-profiles/%s', $reserveProfile->getUuid()));
        $this->assertResponseIsSuccessful();
        $client->submitForm('Назначить профиль', [
            'accrual_type' => AccrualType::Electricity->value,
        ]);

        $assignment = $this->entityManager()
            ->getRepository(PaymentRequisiteAssignment::class)
            ->find($assignmentUuid);

        self::assertInstanceOf(PaymentRequisiteAssignment::class, $assignment);
        self::assertNotNull($assignment->getClosedAt());
        self::assertSame('replaced', $assignment->getCloseReason());

        $newAssignment = $this->entityManager()
            ->getRepository(PaymentRequisiteAssignment::class)
            ->findOneBy(['paymentRequisiteProfile' => $reserveProfile, 'closedAt' => null]);

        self::assertInstanceOf(PaymentRequisiteAssignment::class, $newAssignment);
        $newAssignmentUuid = $newAssignment->getUuid();
        self::assertSame(AccrualType::Electricity, $newAssignment->getAccrualType());

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $client->submitForm('Снять');

        $newAssignment = $this->entityManager()
            ->getRepository(PaymentRequisiteAssignment::class)
            ->find($newAssignmentUuid);

        self::assertInstanceOf(PaymentRequisiteAssignment::class, $newAssignment);
        self::assertNotNull($newAssignment->getClosedAt());
        self::assertSame('manual_close', $newAssignment->getCloseReason());
        $this->assertResponseRedirects(sprintf('/admin/payment-requisite-profiles/%s', $reserveProfile->getUuid()), Response::HTTP_SEE_OTHER);
    }

    private function createProfile(
        string $code,
        string $name,
        string $recipientName,
        string $bankAccount,
        Workspace $workspace,
        User $createdBy,
    ): PaymentRequisiteProfile {
        $profile = (new PaymentRequisiteProfile($workspace))
            ->setCode($code)
            ->setName($name)
            ->setRecipientName($recipientName)
            ->setRecipientInn('1234567890')
            ->setRecipientKpp('123456789')
            ->setBankName('ПАО Сбербанк')
            ->setBankBik('044525225')
            ->setBankCorrespondentAccount('30101810400000000225')
            ->setBankAccount($bankAccount)
            ->setCreatedBy($createdBy);

        $this->entityManager()->persist($profile);
        $this->entityManager()->flush();

        return $profile;
    }
}

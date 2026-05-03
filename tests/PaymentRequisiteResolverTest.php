<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Service\AccountBalanceSummary;
use App\Service\AccountStatement;
use App\Service\AccountStatementAccrualRow;
use App\Service\PaymentRequisiteResolver;
use DateTimeImmutable;

final class PaymentRequisiteResolverTest extends FunctionalWebTestCase
{
    public function testTypedAssignmentOverridesDefaultAssignment(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        [$workspace, $account, , $electricityProfile] = $this->createFixture();
        $statement = $this->createStatement($workspace, $account, [AccrualType::Electricity]);
        $snapshot = $this->createSnapshot($workspace, $account);

        static::getContainer()
            ->get(PaymentRequisiteResolver::class)
            ->applyToSnapshot($workspace, $statement, $snapshot);

        self::assertSame($electricityProfile->getUuid()->toRfc4122(), $snapshot->getPaymentRequisiteProfile()?->getUuid()->toRfc4122());
        self::assertSame('ТСН "Свет"', $snapshot->getPaymentRecipientName());
        self::assertSame('Электроэнергия 9-123', $snapshot->getPaymentPurpose());
    }

    public function testMixedAccrualStatementUsesDefaultAssignment(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        [$workspace, $account, $defaultProfile] = $this->createFixture();
        $statement = $this->createStatement($workspace, $account, [AccrualType::Electricity, AccrualType::Water]);
        $snapshot = $this->createSnapshot($workspace, $account);

        static::getContainer()
            ->get(PaymentRequisiteResolver::class)
            ->applyToSnapshot($workspace, $statement, $snapshot);

        self::assertSame($defaultProfile->getUuid()->toRfc4122(), $snapshot->getPaymentRequisiteProfile()?->getUuid()->toRfc4122());
        self::assertSame('ТСН "Общие"', $snapshot->getPaymentRecipientName());
        self::assertSame('Общие 9-123', $snapshot->getPaymentPurpose());
    }

    /**
     * @return array{Workspace, Account, PaymentRequisiteProfile, PaymentRequisiteProfile}
     */
    private function createFixture(): array
    {
        $entityManager = $this->entityManager();
        $workspace = $this->createWorkspace();
        $account = (new Account($workspace))->setNumber('9-123');
        $defaultProfile = $this->createProfile(
            workspace: $workspace,
            code: 'default',
            recipientName: 'ТСН "Общие"',
            bankAccount: '40703810900000000001',
            paymentPurposeTemplate: 'Общие {account_number}',
        );
        $electricityProfile = $this->createProfile(
            workspace: $workspace,
            code: 'electricity',
            recipientName: 'ТСН "Свет"',
            bankAccount: '40703810900000000002',
            paymentPurposeTemplate: 'Электроэнергия {account_number}',
        );

        $entityManager->persist($account);
        $entityManager->persist($defaultProfile);
        $entityManager->persist($electricityProfile);
        $entityManager->persist(new PaymentRequisiteAssignment($workspace, $defaultProfile, null, new DateTimeImmutable('2026-01-01')));
        $entityManager->persist(new PaymentRequisiteAssignment($workspace, $electricityProfile, AccrualType::Electricity, new DateTimeImmutable('2026-01-01')));
        $entityManager->flush();

        return [$workspace, $account, $defaultProfile, $electricityProfile];
    }

    private function createProfile(
        Workspace $workspace,
        string $code,
        string $recipientName,
        string $bankAccount,
        string $paymentPurposeTemplate,
    ): PaymentRequisiteProfile {
        return (new PaymentRequisiteProfile($workspace, new DateTimeImmutable('2026-01-01')))
            ->setCode($code)
            ->setName($recipientName)
            ->setRecipientName($recipientName)
            ->setRecipientInn('1234567890')
            ->setRecipientKpp('123456789')
            ->setBankName('ПАО Сбербанк')
            ->setBankBik('044525225')
            ->setBankCorrespondentAccount('30101810400000000225')
            ->setBankAccount($bankAccount)
            ->setPaymentPurposeTemplate($paymentPurposeTemplate);
    }

    /**
     * @param non-empty-list<AccrualType> $types
     */
    private function createStatement(Workspace $workspace, Account $account, array $types): AccountStatement
    {
        $rows = [];

        foreach ($types as $type) {
            $rows[] = new AccountStatementAccrualRow(new Accrual(
                workspace: $workspace,
                account: $account,
                type: $type,
                periodStart: new DateTimeImmutable('2026-04-01'),
                periodEnd: new DateTimeImmutable('2026-05-01'),
                amount: '100.00',
            ));
        }

        return new AccountStatement(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-05'),
            balance: new AccountBalanceSummary('100.00', '0.00', '-100.00', 'debt'),
            amountToPay: '100.00',
            overpaymentAmount: '0.00',
            accrualRows: $rows,
            paymentRows: [],
        );
    }

    private function createSnapshot(Workspace $workspace, Account $account): AccountStatementSnapshot
    {
        return new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-05'),
            activeAccrualTotal: '100.00',
            activePaymentTotal: '0.00',
            balanceAmount: '-100.00',
            amountToPay: '100.00',
            overpaymentAmount: '0.00',
        );
    }
}

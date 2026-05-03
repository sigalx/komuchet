<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountStatementSnapshot;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Workspace;
use App\Service\AccountStatementPaymentQrCode;
use App\Service\AccountStatementPaymentQrCodeGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AccountStatementPaymentQrCodeGeneratorTest extends TestCase
{
    public function testBuildsBankPaymentPayloadFromStatementSnapshot(): void
    {
        $statement = $this->createStatement('1000.00');
        $statement->applyPaymentRequisites($this->createPaymentRequisiteProfile(), 'Оплата | май = 2026');

        $generator = new AccountStatementPaymentQrCodeGenerator();
        $payload = $generator->buildPayload($statement);

        self::assertSame(
            'ST00012|Name=ТСН "Ромашка"|PersonalAcc=40703810900000000001|BankName=ПАО Сбербанк|BIC=044525225|CorrespAcc=30101810400000000225|PayeeINN=1234567890|KPP=123456789|Purpose=Оплата май 2026|Sum=100000',
            $payload,
        );

        $qrCode = $generator->generate($statement);

        self::assertInstanceOf(AccountStatementPaymentQrCode::class, $qrCode);
        self::assertSame($payload, $qrCode->payload);
        self::assertStringStartsWith('data:image/png;base64,', $qrCode->dataUri);
    }

    public function testDoesNotBuildQrCodeWithoutPositiveAmount(): void
    {
        $statement = $this->createStatement('0.00');
        $statement->applyPaymentRequisites($this->createPaymentRequisiteProfile(), 'Оплата');

        $generator = new AccountStatementPaymentQrCodeGenerator();

        self::assertNull($generator->buildPayload($statement));
        self::assertNull($generator->generate($statement));
    }

    public function testDoesNotBuildQrCodeWithoutRequiredRequisites(): void
    {
        $statement = $this->createStatement('1000.00');

        $generator = new AccountStatementPaymentQrCodeGenerator();

        self::assertNull($generator->buildPayload($statement));
        self::assertNull($generator->generate($statement));
    }

    private function createStatement(string $amountToPay): AccountStatementSnapshot
    {
        $workspace = (new Workspace())
            ->setCode('main')
            ->setName('Основное хозяйство');
        $account = (new Account($workspace))
            ->setNumber('9-123');

        return new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-13'),
            activeAccrualTotal: '1500.00',
            activePaymentTotal: '500.00',
            balanceAmount: sprintf('-%s', $amountToPay),
            amountToPay: $amountToPay,
            overpaymentAmount: '0.00',
        );
    }

    private function createPaymentRequisiteProfile(): PaymentRequisiteProfile
    {
        return (new PaymentRequisiteProfile())
            ->setCode('main')
            ->setName('Основные реквизиты')
            ->setRecipientName('ТСН "Ромашка"')
            ->setRecipientInn('1234567890')
            ->setRecipientKpp('123456789')
            ->setBankName('ПАО Сбербанк')
            ->setBankBik('044525225')
            ->setBankCorrespondentAccount('30101810400000000225')
            ->setBankAccount('40703810900000000001');
    }
}

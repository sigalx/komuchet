<?php

namespace App\Entity;

use App\Enum\PaymentSource;
use App\Repository\AccountStatementPaymentSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountStatementPaymentSnapshotRepository::class)]
#[ORM\Table(name: 'account_statement_payments')]
#[ORM\Index(name: 'ix_account_statement_payments_payment', columns: ['workspace_uuid', 'payment_uuid'])]
class AccountStatementPaymentSnapshot
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: AccountStatementSnapshot::class)]
    #[ORM\JoinColumn(name: 'account_statement_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?AccountStatementSnapshot $accountStatement = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Payment $payment = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(name: 'paid_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $paidOn;

    #[ORM\Column(enumType: PaymentSource::class, columnDefinition: 'payment_source')]
    private PaymentSource $source = PaymentSource::Manual;

    #[ORM\Column(name: 'payer_name', type: Types::TEXT, nullable: true)]
    private ?string $payerName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $purpose = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $sortOrder = 1;

    public function __construct(
        ?Workspace $workspace = null,
        ?AccountStatementSnapshot $accountStatement = null,
        ?Payment $payment = null,
        int $sortOrder = 1,
    ) {
        $this->workspace = $workspace;
        $this->accountStatement = $accountStatement;
        $this->payment = $payment;
        $this->sortOrder = $sortOrder;

        if ($payment instanceof Payment) {
            $this->amount = $this->normalizeMoney($payment->getAmount());
            $this->paidOn = $payment->getPaidOn();
            $this->source = $payment->getSource();
            $this->payerName = $payment->getPayerName();
            $this->purpose = $payment->getPurpose();
        } else {
            $this->paidOn = new DateTimeImmutable('today');
        }
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getAccountStatement(): ?AccountStatementSnapshot
    {
        return $this->accountStatement;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getPaidOn(): DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function getSource(): PaymentSource
    {
        return $this->source;
    }

    public function getPayerName(): ?string
    {
        return $this->payerName;
    }

    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    private function normalizeMoney(string $amount): string
    {
        $amount = trim(str_replace(',', '.', $amount));
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;
        $cents = $negative ? -$cents : $cents;
        $sign = $cents < 0 ? '-' : '';
        $absoluteCents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absoluteCents, 100), $absoluteCents % 100);
    }
}

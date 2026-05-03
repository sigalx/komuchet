<?php

namespace App\Entity;

use App\Repository\AccountStatementSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountStatementSnapshotRepository::class)]
#[ORM\Table(name: 'account_statements')]
#[ORM\UniqueConstraint(name: 'ux_account_statements_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_account_statements_number', columns: ['workspace_uuid', 'number'])]
#[ORM\UniqueConstraint(name: 'ux_account_statements_active_billing_run_account', columns: ['workspace_uuid', 'billing_run_uuid', 'account_uuid'], options: ['where' => 'billing_run_uuid IS NOT NULL AND cancelled_at IS NULL'])]
#[ORM\Index(name: 'ix_account_statements_account_generated', columns: ['workspace_uuid', 'account_uuid', 'generated_at'])]
#[ORM\Index(name: 'ix_account_statements_billing_run', columns: ['workspace_uuid', 'billing_run_uuid', 'generated_at'])]
class AccountStatementSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: BillingRun::class)]
    #[ORM\JoinColumn(name: 'billing_run_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?BillingRun $billingRun = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $number;

    #[ORM\Column(name: 'workspace_name', type: Types::TEXT)]
    private string $workspaceName;

    #[ORM\Column(name: 'account_number', type: Types::TEXT)]
    private string $accountNumber;

    #[ORM\Column(name: 'statement_date', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $statementDate;

    #[ORM\Column(name: 'generated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $generatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'generated_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $generatedBy = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'cancelled_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $cancelledBy = null;

    #[ORM\Column(name: 'cancellation_reason', type: Types::TEXT, nullable: true)]
    private ?string $cancellationReason = null;

    #[ORM\Column(name: 'active_accrual_total', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $activeAccrualTotal = '0.00';

    #[ORM\Column(name: 'active_payment_total', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $activePaymentTotal = '0.00';

    #[ORM\Column(name: 'balance_amount', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $balanceAmount = '0.00';

    #[ORM\Column(name: 'amount_to_pay', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $amountToPay = '0.00';

    #[ORM\Column(name: 'overpayment_amount', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $overpaymentAmount = '0.00';

    #[ORM\ManyToOne(targetEntity: PaymentRequisiteProfile::class)]
    #[ORM\JoinColumn(name: 'payment_requisite_profile_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?PaymentRequisiteProfile $paymentRequisiteProfile = null;

    #[ORM\Column(name: 'payment_recipient_name', type: Types::TEXT, nullable: true)]
    private ?string $paymentRecipientName = null;

    #[ORM\Column(name: 'payment_recipient_inn', type: Types::TEXT, nullable: true)]
    private ?string $paymentRecipientInn = null;

    #[ORM\Column(name: 'payment_recipient_kpp', type: Types::TEXT, nullable: true)]
    private ?string $paymentRecipientKpp = null;

    #[ORM\Column(name: 'payment_bank_name', type: Types::TEXT, nullable: true)]
    private ?string $paymentBankName = null;

    #[ORM\Column(name: 'payment_bank_bik', type: Types::TEXT, nullable: true)]
    private ?string $paymentBankBik = null;

    #[ORM\Column(name: 'payment_bank_correspondent_account', type: Types::TEXT, nullable: true)]
    private ?string $paymentBankCorrespondentAccount = null;

    #[ORM\Column(name: 'payment_bank_account', type: Types::TEXT, nullable: true)]
    private ?string $paymentBankAccount = null;

    #[ORM\Column(name: 'payment_purpose', type: Types::TEXT, nullable: true)]
    private ?string $paymentPurpose = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?Account $account = null,
        ?DateTimeImmutable $statementDate = null,
        string $activeAccrualTotal = '0.00',
        string $activePaymentTotal = '0.00',
        string $balanceAmount = '0.00',
        string $amountToPay = '0.00',
        string $overpaymentAmount = '0.00',
        ?User $generatedBy = null,
        ?BillingRun $billingRun = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->account = $account;
        $this->billingRun = $billingRun;
        $this->statementDate = $statementDate ?? new DateTimeImmutable('today');
        $this->generatedAt = new DateTimeImmutable();
        $this->generatedBy = $generatedBy;
        $this->number = $this->buildNumber();
        $this->workspaceName = $workspace?->getName() ?? '';
        $this->accountNumber = $account?->getNumber() ?? '';
        $this->activeAccrualTotal = $this->normalizeMoney($activeAccrualTotal);
        $this->activePaymentTotal = $this->normalizeMoney($activePaymentTotal);
        $this->balanceAmount = $this->normalizeMoney($balanceAmount);
        $this->amountToPay = $this->normalizeMoney($amountToPay);
        $this->overpaymentAmount = $this->normalizeMoney($overpaymentAmount);
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getBillingRun(): ?BillingRun
    {
        return $this->billingRun;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getWorkspaceName(): string
    {
        return $this->workspaceName;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function getStatementDate(): DateTimeImmutable
    {
        return $this->statementDate;
    }

    public function getGeneratedAt(): DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getGeneratedBy(): ?User
    {
        return $this->generatedBy;
    }

    public function getCancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function cancel(string $reason, ?User $cancelledBy = null): static
    {
        $this->cancelledAt = new DateTimeImmutable();
        $this->cancelledBy = $cancelledBy;
        $this->cancellationReason = trim($reason);

        return $this;
    }

    public function getActiveAccrualTotal(): string
    {
        return $this->activeAccrualTotal;
    }

    public function getActivePaymentTotal(): string
    {
        return $this->activePaymentTotal;
    }

    public function getBalanceAmount(): string
    {
        return $this->balanceAmount;
    }

    public function getAmountToPay(): string
    {
        return $this->amountToPay;
    }

    public function getOverpaymentAmount(): string
    {
        return $this->overpaymentAmount;
    }

    public function applyPaymentRequisites(?PaymentRequisiteProfile $profile, ?string $paymentPurpose): static
    {
        $this->paymentRequisiteProfile = $profile;

        if ($profile === null) {
            $this->paymentRecipientName = null;
            $this->paymentRecipientInn = null;
            $this->paymentRecipientKpp = null;
            $this->paymentBankName = null;
            $this->paymentBankBik = null;
            $this->paymentBankCorrespondentAccount = null;
            $this->paymentBankAccount = null;
            $this->paymentPurpose = null;

            return $this;
        }

        $this->paymentRecipientName = $profile->getRecipientName();
        $this->paymentRecipientInn = $profile->getRecipientInn();
        $this->paymentRecipientKpp = $profile->getRecipientKpp();
        $this->paymentBankName = $profile->getBankName();
        $this->paymentBankBik = $profile->getBankBik();
        $this->paymentBankCorrespondentAccount = $profile->getBankCorrespondentAccount();
        $this->paymentBankAccount = $profile->getBankAccount();
        $this->paymentPurpose = $this->normalizeOptionalText($paymentPurpose);

        return $this;
    }

    public function getPaymentRequisiteProfile(): ?PaymentRequisiteProfile
    {
        return $this->paymentRequisiteProfile;
    }

    public function hasPaymentRequisites(): bool
    {
        return $this->paymentRecipientName !== null
            || $this->paymentBankAccount !== null;
    }

    public function getPaymentRecipientName(): ?string
    {
        return $this->paymentRecipientName;
    }

    public function getPaymentRecipientInn(): ?string
    {
        return $this->paymentRecipientInn;
    }

    public function getPaymentRecipientKpp(): ?string
    {
        return $this->paymentRecipientKpp;
    }

    public function getPaymentBankName(): ?string
    {
        return $this->paymentBankName;
    }

    public function getPaymentBankBik(): ?string
    {
        return $this->paymentBankBik;
    }

    public function getPaymentBankCorrespondentAccount(): ?string
    {
        return $this->paymentBankCorrespondentAccount;
    }

    public function getPaymentBankAccount(): ?string
    {
        return $this->paymentBankAccount;
    }

    public function getPaymentPurpose(): ?string
    {
        return $this->paymentPurpose;
    }

    public function hasDebt(): bool
    {
        return $this->toCents($this->balanceAmount) < 0;
    }

    public function hasOverpayment(): bool
    {
        return $this->toCents($this->balanceAmount) > 0;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    private function buildNumber(): string
    {
        $hexUuid = strtoupper(str_replace('-', '', $this->uuid->toRfc4122()));

        return sprintf('ST-%s-%s', $this->statementDate->format('Ymd'), substr($hexUuid, -8));
    }

    private function normalizeMoney(string $amount): string
    {
        return $this->fromCents($this->toCents($amount));
    }

    private function normalizeOptionalText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function toCents(string $amount): int
    {
        $amount = trim(str_replace(',', '.', $amount));
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absoluteCents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absoluteCents, 100), $absoluteCents % 100);
    }
}

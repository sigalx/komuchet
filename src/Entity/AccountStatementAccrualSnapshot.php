<?php

namespace App\Entity;

use App\Enum\AccrualType;
use App\Repository\AccountStatementAccrualSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountStatementAccrualSnapshotRepository::class)]
#[ORM\Table(name: 'account_statement_accruals')]
#[ORM\Index(name: 'ix_account_statement_accruals_accrual', columns: ['workspace_uuid', 'accrual_uuid'])]
class AccountStatementAccrualSnapshot
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
    #[ORM\ManyToOne(targetEntity: Accrual::class)]
    #[ORM\JoinColumn(name: 'accrual_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Accrual $accrual = null;

    #[ORM\Column(enumType: AccrualType::class, columnDefinition: 'accrual_type')]
    private AccrualType $type = AccrualType::Electricity;

    #[ORM\Column(name: 'period_start', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $periodStart;

    #[ORM\Column(name: 'period_end', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $periodEnd;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $sortOrder = 1;

    public function __construct(
        ?Workspace $workspace = null,
        ?AccountStatementSnapshot $accountStatement = null,
        ?Accrual $accrual = null,
        int $sortOrder = 1,
    ) {
        $this->workspace = $workspace;
        $this->accountStatement = $accountStatement;
        $this->accrual = $accrual;
        $this->sortOrder = $sortOrder;

        if ($accrual instanceof Accrual) {
            $this->type = $accrual->getType();
            $this->periodStart = $accrual->getPeriodStart();
            $this->periodEnd = $accrual->getPeriodEnd();
            $this->amount = $this->normalizeMoney($accrual->getAmount());
            $this->notes = $accrual->getNotes();
        } else {
            $this->periodStart = new DateTimeImmutable('today');
            $this->periodEnd = $this->periodStart->modify('+1 day');
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

    public function getAccrual(): ?Accrual
    {
        return $this->accrual;
    }

    public function getType(): AccrualType
    {
        return $this->type;
    }

    public function getPeriodStart(): DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
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

<?php

namespace App\Entity;

use App\Repository\AccountGroupMemberRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountGroupMemberRepository::class)]
#[ORM\Table(name: 'account_group_members')]
#[ORM\Index(name: 'ix_account_group_members_account', columns: ['workspace_uuid', 'account_uuid', 'valid_from', 'valid_to'])]
class AccountGroupMember
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: AccountGroup::class)]
    #[ORM\JoinColumn(name: 'account_group_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?AccountGroup $accountGroup = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Account $account = null;

    #[ORM\Id]
    #[ORM\Column(name: 'valid_from', type: Types::STRING, length: 10, columnDefinition: 'date')]
    private string $validFrom;

    #[ORM\Column(name: 'valid_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validTo = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $createdBy = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?AccountGroup $accountGroup = null,
        ?Account $account = null,
        ?DateTimeImmutable $validFrom = null,
        ?User $createdBy = null,
    ) {
        $this->workspace = $workspace;
        $this->accountGroup = $accountGroup;
        $this->account = $account;
        $this->validFrom = ($validFrom ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $this->createdBy = $createdBy;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getAccountGroup(): ?AccountGroup
    {
        return $this->accountGroup;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getValidFrom(): DateTimeImmutable
    {
        $validFrom = DateTimeImmutable::createFromFormat('!Y-m-d', $this->validFrom);

        if (!$validFrom instanceof DateTimeImmutable) {
            throw new \LogicException('Invalid account group member valid_from value.');
        }

        return $validFrom;
    }

    public function getValidTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }
}

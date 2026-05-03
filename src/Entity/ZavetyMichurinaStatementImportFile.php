<?php

namespace App\Entity;

use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use App\Repository\ZavetyMichurinaStatementImportFileRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ZavetyMichurinaStatementImportFileRepository::class)]
#[ORM\Table(name: 'zavety_michurina_statement_import_files')]
#[ORM\UniqueConstraint(name: 'ux_zm_statement_import_files_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_zm_statement_import_files_batch_hash', columns: ['workspace_uuid', 'batch_uuid', 'source_sha256'])]
#[ORM\Index(name: 'ix_zm_statement_import_files_batch_status', columns: ['workspace_uuid', 'batch_uuid', 'status'])]
#[ORM\Index(name: 'ix_zm_statement_import_files_detected_account', columns: ['workspace_uuid', 'detected_account_number'], options: ['where' => 'detected_account_number IS NOT NULL'])]
#[ORM\Index(name: 'ix_zm_statement_import_files_created', columns: ['workspace_uuid', 'created_at'])]
class ZavetyMichurinaStatementImportFile
{
    public const PARSER_VERSION = 'zavety_michurina_pdf_v1';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: ZavetyMichurinaStatementImportBatch::class)]
    #[ORM\JoinColumn(name: 'batch_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    private ?ZavetyMichurinaStatementImportBatch $batch = null;

    #[ORM\Column(name: 'original_filename', type: Types::TEXT)]
    private string $originalFilename = '';

    #[ORM\Column(name: 'storage_key', type: Types::TEXT, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(name: 'source_sha256', type: Types::TEXT, nullable: true)]
    private ?string $sourceSha256 = null;

    #[ORM\Column(name: 'file_size_bytes', type: Types::INTEGER, nullable: true)]
    private ?int $fileSizeBytes = null;

    #[ORM\Column(name: 'parser_version', type: Types::TEXT)]
    private string $parserVersion = self::PARSER_VERSION;

    #[ORM\Column(enumType: ZavetyMichurinaStatementImportFileStatus::class, options: ['default' => 'pending'], columnDefinition: 'zavety_michurina_statement_import_file_status')]
    private ZavetyMichurinaStatementImportFileStatus $status = ZavetyMichurinaStatementImportFileStatus::Pending;

    #[ORM\Column(name: 'parsed_result', type: Types::JSONB, nullable: true)]
    private ?array $parsedResult = null;

    #[ORM\Column(name: 'parse_error', type: Types::TEXT, nullable: true)]
    private ?string $parseError = null;

    #[ORM\Column(name: 'detected_account_number', type: Types::TEXT, nullable: true)]
    private ?string $detectedAccountNumber = null;

    #[ORM\Column(name: 'detected_subscriber_full_name', type: Types::TEXT, nullable: true)]
    private ?string $detectedSubscriberFullName = null;

    #[ORM\Column(name: 'parsed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $parsedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $updatedBy = null;

    public function __construct(
        ?ZavetyMichurinaStatementImportBatch $batch = null,
        string $originalFilename = '',
        ?string $sourceSha256 = null,
        ?int $fileSizeBytes = null,
        ?User $createdBy = null,
    ) {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->batch = $batch;
        $this->workspace = $batch?->getWorkspace();
        $this->originalFilename = trim($originalFilename);
        $this->sourceSha256 = $this->normalizeOptionalHash($sourceSha256);
        $this->fileSizeBytes = $fileSizeBytes;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->createdBy = $createdBy;
        $this->updatedBy = $createdBy;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getBatch(): ?ZavetyMichurinaStatementImportBatch
    {
        return $this->batch;
    }

    public function setBatch(ZavetyMichurinaStatementImportBatch $batch): static
    {
        $this->batch = $batch;
        $this->workspace = $batch->getWorkspace();

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = trim($originalFilename);

        return $this;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function setStorageKey(?string $storageKey): static
    {
        $this->storageKey = $this->normalizeOptionalText($storageKey);

        return $this;
    }

    public function getSourceSha256(): ?string
    {
        return $this->sourceSha256;
    }

    public function setSourceSha256(?string $sourceSha256): static
    {
        $this->sourceSha256 = $this->normalizeOptionalHash($sourceSha256);

        return $this;
    }

    public function getFileSizeBytes(): ?int
    {
        return $this->fileSizeBytes;
    }

    public function setFileSizeBytes(?int $fileSizeBytes): static
    {
        $this->fileSizeBytes = $fileSizeBytes;

        return $this;
    }

    public function getParserVersion(): string
    {
        return $this->parserVersion;
    }

    public function getStatus(): ZavetyMichurinaStatementImportFileStatus
    {
        return $this->status;
    }

    public function getParsedResult(): ?array
    {
        return $this->parsedResult;
    }

    public function getParseError(): ?string
    {
        return $this->parseError;
    }

    public function getDetectedAccountNumber(): ?string
    {
        return $this->detectedAccountNumber;
    }

    public function getDetectedSubscriberFullName(): ?string
    {
        return $this->detectedSubscriberFullName;
    }

    public function getParsedAt(): ?DateTimeImmutable
    {
        return $this->parsedAt;
    }

    public function markParsed(array $parsedResult, ?User $updatedBy = null): static
    {
        $this->status = ZavetyMichurinaStatementImportFileStatus::Parsed;
        $this->parsedResult = $parsedResult;
        $this->parseError = null;
        $this->parsedAt = new DateTimeImmutable();
        $this->detectedAccountNumber = $this->normalizeOptionalText($parsedResult['account']['number'] ?? null);
        $this->detectedSubscriberFullName = $this->normalizeOptionalText($parsedResult['subscriber']['full_name'] ?? null);

        return $this->touch($updatedBy);
    }

    public function markFailed(string $parseError, ?User $updatedBy = null): static
    {
        $this->status = ZavetyMichurinaStatementImportFileStatus::Failed;
        $this->parsedResult = null;
        $this->parseError = trim($parseError);
        $this->parsedAt = null;
        $this->detectedAccountNumber = null;
        $this->detectedSubscriberFullName = null;

        return $this->touch($updatedBy);
    }

    public function markApplied(?User $updatedBy = null): static
    {
        $this->status = ZavetyMichurinaStatementImportFileStatus::Applied;

        return $this->touch($updatedBy);
    }

    public function canBeDeleted(): bool
    {
        return $this->status !== ZavetyMichurinaStatementImportFileStatus::Applied;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function touch(?User $updatedBy = null): static
    {
        $this->updatedAt = new DateTimeImmutable();
        $this->updatedBy = $updatedBy;

        return $this;
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeOptionalHash(?string $value): ?string
    {
        $value = $this->normalizeOptionalText($value);

        return $value === null ? null : strtolower($value);
    }
}

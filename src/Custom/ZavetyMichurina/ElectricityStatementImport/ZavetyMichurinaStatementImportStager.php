<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

use App\Entity\User;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use App\Entity\ZavetyMichurinaStatementImportFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class ZavetyMichurinaStatementImportStager
{
    public function __construct(
        private readonly PdfLayoutTextExtractor $textExtractor,
        private readonly ZavetyMichurinaElectricityStatementParser $parser,
    ) {
    }

    public function stageUploadedFile(
        ZavetyMichurinaStatementImportBatch $batch,
        UploadedFile $uploadedFile,
        ?User $createdBy = null,
    ): ZavetyMichurinaStatementImportFile {
        return $this->stagePath(
            batch: $batch,
            filePath: $uploadedFile->getPathname(),
            originalFilename: $uploadedFile->getClientOriginalName(),
            fileSizeBytes: $uploadedFile->getSize(),
            createdBy: $createdBy,
        );
    }

    public function stagePath(
        ZavetyMichurinaStatementImportBatch $batch,
        string $filePath,
        ?string $originalFilename = null,
        ?int $fileSizeBytes = null,
        ?User $createdBy = null,
        bool $fromText = false,
    ): ZavetyMichurinaStatementImportFile {
        $sourceHash = is_file($filePath) ? hash_file('sha256', $filePath) : false;
        $fileSize = $fileSizeBytes ?? (is_file($filePath) ? filesize($filePath) : false);
        $importFile = new ZavetyMichurinaStatementImportFile(
            batch: $batch,
            originalFilename: $originalFilename ?? basename($filePath),
            sourceSha256: $sourceHash === false ? null : $sourceHash,
            fileSizeBytes: $fileSize === false ? null : $fileSize,
            createdBy: $createdBy,
        );

        if (!is_file($filePath) || !is_readable($filePath)) {
            return $importFile->markFailed(sprintf('File "%s" is not readable.', $filePath), $createdBy);
        }

        try {
            $text = $fromText
                ? (string) file_get_contents($filePath)
                : $this->textExtractor->extract($filePath);
            $parsed = $this->parser->parse($text);

            return $importFile->markParsed($parsed->toArray(), $createdBy);
        } catch (Throwable $exception) {
            return $importFile->markFailed($exception->getMessage(), $createdBy);
        }
    }
}

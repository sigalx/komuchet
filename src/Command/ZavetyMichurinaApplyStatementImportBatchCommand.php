<?php

namespace App\Command;

use App\Custom\ZavetyMichurina\ElectricityStatementImport\ZavetyMichurinaStatementImportApplier;
use App\Entity\Workspace;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use App\Repository\WorkspaceRepository;
use App\Repository\ZavetyMichurinaStatementImportBatchRepository;
use App\Repository\ZavetyMichurinaStatementImportFileRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:zm:apply-statement-import-batch',
    description: 'Apply parsed Zavety Michurina statement import files from a staging batch.',
)]
final class ZavetyMichurinaApplyStatementImportBatchCommand extends Command
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly ZavetyMichurinaStatementImportBatchRepository $batchRepository,
        private readonly ZavetyMichurinaStatementImportFileRepository $fileRepository,
        private readonly ZavetyMichurinaStatementImportApplier $applier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('batch', InputArgument::REQUIRED, 'Import batch UUID.')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace code or UUID.')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Continue after technical apply errors.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workspaceReference = (string) ($input->getOption('workspace') ?? '');

        if (trim($workspaceReference) === '') {
            $io->error('Option --workspace is required.');

            return Command::INVALID;
        }

        $workspace = $this->findWorkspace($workspaceReference);

        if (!$workspace instanceof Workspace) {
            $io->error(sprintf('Workspace "%s" was not found.', $workspaceReference));

            return Command::FAILURE;
        }

        $batchReference = (string) $input->getArgument('batch');

        if (!Uuid::isValid($batchReference)) {
            $io->error(sprintf('Import batch UUID "%s" is invalid.', $batchReference));

            return Command::INVALID;
        }

        $batch = $this->batchRepository->findOneByWorkspaceAndUuid($workspace, Uuid::fromString($batchReference));

        if (!$batch instanceof ZavetyMichurinaStatementImportBatch) {
            $io->error(sprintf('Import batch "%s" was not found in workspace "%s".', $batchReference, $workspaceReference));

            return Command::FAILURE;
        }

        $files = $this->fileRepository->findByBatchAndStatus($workspace, $batch, ZavetyMichurinaStatementImportFileStatus::Parsed);

        if ($files === []) {
            $io->info('No parsed files to apply in this batch.');

            return Command::SUCCESS;
        }

        $continueOnError = $input->getOption('continue-on-error') === true;
        $applied = 0;
        $partial = 0;
        $failed = 0;
        $created = 0;
        $reused = 0;
        $skipped = 0;
        $rows = [];

        foreach ($files as $file) {
            try {
                $result = $this->applier->apply($file);
                $resultData = $result->toArray();
                $created += $result->createdTotal();
                $reused += $result->reusedTotal();
                $skipped += $result->skippedTotal();
                $message = $resultData['warnings'][0] ?? '';

                if ($result->skippedTotal() > 0) {
                    ++$partial;
                    $status = 'partial';
                } else {
                    ++$applied;
                    $status = 'applied';
                }

                $rows[] = [
                    $file->getOriginalFilename(),
                    $status,
                    (string) $result->createdTotal(),
                    (string) $result->reusedTotal(),
                    (string) $result->skippedTotal(),
                    $message,
                ];
            } catch (\Throwable $exception) {
                ++$failed;
                $rows[] = [
                    $file->getOriginalFilename(),
                    'failed',
                    '0',
                    '0',
                    '0',
                    $exception->getMessage(),
                ];

                if (!$continueOnError) {
                    break;
                }
            }
        }

        (new Table($output))
            ->setHeaders(['Файл', 'Итог', 'Создано', 'Переисп.', 'Пропущено', 'Сообщение'])
            ->setRows($rows)
            ->render();

        if ($failed > 0) {
            $io->error(sprintf(
                'Batch apply finished with errors. Applied: %d, partial: %d, failed: %d.',
                $applied,
                $partial,
                $failed,
            ));

            return Command::FAILURE;
        }

        if ($partial > 0) {
            $io->warning(sprintf(
                'Batch apply finished partially. Applied: %d, partial: %d. Created: %d, reused: %d, skipped: %d.',
                $applied,
                $partial,
                $created,
                $reused,
                $skipped,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Batch applied. Files: %d. Created: %d, reused: %d.',
            $applied,
            $created,
            $reused,
        ));

        return Command::SUCCESS;
    }

    private function findWorkspace(string $reference): ?Workspace
    {
        $reference = trim($reference);

        if (Uuid::isValid($reference)) {
            $workspace = $this->workspaceRepository->find(Uuid::fromString($reference));

            return $workspace instanceof Workspace ? $workspace : null;
        }

        $workspace = $this->workspaceRepository->findOneBy(['code' => $reference]);

        return $workspace instanceof Workspace ? $workspace : null;
    }
}

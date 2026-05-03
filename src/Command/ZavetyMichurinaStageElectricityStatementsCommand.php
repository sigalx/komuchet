<?php

namespace App\Command;

use App\Custom\ZavetyMichurina\ElectricityStatementImport\ZavetyMichurinaStatementImportStager;
use App\Entity\Workspace;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
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
    name: 'app:zm:stage-electricity-statements',
    description: 'Parse Zavety Michurina electricity statement files into import staging tables.',
)]
final class ZavetyMichurinaStageElectricityStatementsCommand extends Command
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ZavetyMichurinaStatementImportStager $stager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'PDF files or directories to stage.')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace code or UUID.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Optional import batch name.')
            ->addOption('from-text', null, InputOption::VALUE_NONE, 'Treat files as already extracted pdftotext -layout text files.')
            ->addOption('pattern', null, InputOption::VALUE_REQUIRED, 'Filename pattern for directory scan. Defaults to *.pdf or *.txt with --from-text.')
            ->addOption('recursive', null, InputOption::VALUE_NONE, 'Scan directories recursively.')
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

        $fromText = $input->getOption('from-text') === true;
        $pattern = $input->getOption('pattern');
        $files = $this->collectFilePaths(
            paths: array_map('strval', (array) $input->getArgument('files')),
            fromText: $fromText,
            recursive: $input->getOption('recursive') === true,
            pattern: $pattern === null ? null : (string) $pattern,
        );

        if ($files === []) {
            $io->error('No matching files were found.');

            return Command::FAILURE;
        }

        $batch = new ZavetyMichurinaStatementImportBatch(
            workspace: $workspace,
            name: (string) ($input->getOption('name') ?? ''),
        );

        $this->entityManager->persist($batch);

        $rows = [];
        $hasFailures = false;

        foreach ($files as $filePath) {
            $importFile = $this->stager->stagePath($batch, $filePath, fromText: $fromText);
            $this->entityManager->persist($importFile);

            if ($importFile->getStatus() === ZavetyMichurinaStatementImportFileStatus::Failed) {
                $hasFailures = true;
            }

            $rows[] = [
                basename($filePath),
                $importFile->getStatus()->value,
                $importFile->getDetectedAccountNumber() ?? '',
                $importFile->getDetectedSubscriberFullName() ?? '',
                $importFile->getParseError() ?? '',
            ];
        }

        $this->entityManager->flush();

        $io->success(sprintf('Import batch staged: %s', $batch->getUuid()->toRfc4122()));

        (new Table($output))
            ->setHeaders(['Файл', 'Статус', 'Участок', 'ФИО', 'Ошибка'])
            ->setRows($rows)
            ->render();

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function collectFilePaths(array $paths, bool $fromText, bool $recursive, ?string $pattern): array
    {
        $pattern = trim((string) $pattern) === '' ? ($fromText ? '*.txt' : '*.pdf') : trim((string) $pattern);
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[$this->fileKey($path)] = $path;
                continue;
            }

            if (!is_dir($path)) {
                $files[$this->fileKey($path)] = $path;
                continue;
            }

            foreach ($this->scanDirectory($path, $pattern, $recursive) as $filePath) {
                $files[$this->fileKey($filePath)] = $filePath;
            }
        }

        $files = array_values($files);
        usort($files, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return $files;
    }

    /**
     * @return list<string>
     */
    private function scanDirectory(string $directory, string $pattern, bool $recursive): array
    {
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS))
            : new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        $files = [];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $filename = $fileInfo->getFilename();

            if (!fnmatch(mb_strtolower($pattern), mb_strtolower($filename))) {
                continue;
            }

            $files[] = $fileInfo->getPathname();
        }

        return $files;
    }

    private function fileKey(string $path): string
    {
        $realPath = realpath($path);

        return $realPath === false ? $path : $realPath;
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

<?php

namespace App\Command;

use App\Custom\ZavetyMichurina\ElectricityStatementImport\PdfLayoutTextExtractor;
use App\Custom\ZavetyMichurina\ElectricityStatementImport\ZavetyMichurinaElectricityStatementParser;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:zm:parse-electricity-statement',
    description: 'Parse Zavety Michurina electricity statement PDF and print dry-run JSON.',
)]
final class ZavetyMichurinaParseElectricityStatementCommand extends Command
{
    public function __construct(
        private readonly PdfLayoutTextExtractor $textExtractor,
        private readonly ZavetyMichurinaElectricityStatementParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'PDF file to parse.')
            ->addOption('from-text', null, InputOption::VALUE_NONE, 'Treat file as an already extracted pdftotext -layout text file.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');

        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('File "%s" is not readable.', $file));

            return Command::INVALID;
        }

        try {
            $text = $input->getOption('from-text') === true
                ? (string) file_get_contents($file)
                : $this->textExtractor->extract($file);
            $parsed = $this->parser->parse($text);
            $json = json_encode($parsed->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } catch (\JsonException $exception) {
            $io->error(sprintf('Failed to encode parse result as JSON: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln($json);

        return Command::SUCCESS;
    }
}

<?php

namespace App\Command;

use App\Entity\BillingSettings;
use App\Entity\Workspace;
use App\Repository\BillingSettingsRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workspace:bootstrap',
    description: 'Создать или обновить хозяйство и настройки биллинга.',
)]
class WorkspaceBootstrapCommand extends Command
{
    private const DEFAULT_CODE = 'main';
    private const DEFAULT_NAME = 'Основное хозяйство';
    private const DEFAULT_TIMEZONE = 'Europe/Moscow';
    private const DEFAULT_INVOICE_GENERATION_DAY = 5;
    private const DEFAULT_READING_FRESHNESS_WINDOW_DAYS = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BillingSettingsRepository $billingSettingsRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::OPTIONAL, 'Код хозяйства.', self::DEFAULT_CODE)
            ->addArgument('name', InputArgument::OPTIONAL, 'Название хозяйства.', self::DEFAULT_NAME)
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Описание хозяйства.')
            ->addOption('association-name', null, InputOption::VALUE_REQUIRED, 'Название хозяйства для настроек биллинга.')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Часовой пояс хозяйства.', self::DEFAULT_TIMEZONE)
            ->addOption('invoice-generation-day', null, InputOption::VALUE_REQUIRED, 'Monthly invoice generation day, 1..28.', (string) self::DEFAULT_INVOICE_GENERATION_DAY)
            ->addOption('reading-freshness-window-days', null, InputOption::VALUE_REQUIRED, 'Allowed reading freshness window, 1..60 days.', (string) self::DEFAULT_READING_FRESHNESS_WINDOW_DAYS)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code = $this->stringArgument($input, 'code');
        $name = $this->stringArgument($input, 'name');
        $description = $this->nullableStringOption($input, 'description');
        $associationName = $this->nullableStringOption($input, 'association-name') ?? $name;
        $timezone = $this->stringOption($input, 'timezone');
        $invoiceGenerationDay = $this->intOption($input, 'invoice-generation-day');
        $readingFreshnessWindowDays = $this->intOption($input, 'reading-freshness-window-days');

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $code)) {
            $io->error('Код хозяйства должен соответствовать /^[a-z0-9][a-z0-9_-]*$/.');

            return Command::INVALID;
        }

        if ($name === '') {
            $io->error('Название хозяйства не должно быть пустым.');

            return Command::INVALID;
        }

        if ($associationName === '') {
            $io->error('Association name must not be empty.');

            return Command::INVALID;
        }

        if ($timezone === '' || @timezone_open($timezone) === false) {
            $io->error(sprintf('Invalid timezone "%s".', $timezone));

            return Command::INVALID;
        }

        if ($invoiceGenerationDay < 1 || $invoiceGenerationDay > 28) {
            $io->error('Invoice generation day must be between 1 and 28.');

            return Command::INVALID;
        }

        if ($readingFreshnessWindowDays < 1 || $readingFreshnessWindowDays > 60) {
            $io->error('Reading freshness window must be between 1 and 60 days.');

            return Command::INVALID;
        }

        $workspace = $this->workspaceRepository->findOneBy(['code' => $code]);
        $created = false;

        if (!$workspace instanceof Workspace) {
            $workspace = (new Workspace())
                ->setCode($code)
                ->setName($name)
                ->setDescription($description)
                ->setTimezone($timezone);

            $this->entityManager->persist($workspace);
            $created = true;
        } else {
            $workspace
                ->setName($name)
                ->setDescription($description)
                ->setTimezone($timezone)
                ->touch();
        }

        $settings = $this->billingSettingsRepository->findOneBy(['workspace' => $workspace]);
        $settingsCreated = false;

        if (!$settings instanceof BillingSettings) {
            $settings = new BillingSettings($workspace, $associationName);
            $this->entityManager->persist($settings);
            $settingsCreated = true;
        }

        $settings
            ->setAssociationName($associationName)
            ->setInvoiceGenerationDay($invoiceGenerationDay)
            ->setReadingFreshnessWindowDays($readingFreshnessWindowDays)
            ->touch();

        $this->entityManager->flush();

        $io->success(sprintf(
            '%s хозяйство "%s" (%s), настройки биллинга %s.',
            $created ? 'Создано' : 'Обновлено',
            $workspace->getName(),
            $workspace->getCode(),
            $settingsCreated ? 'созданы' : 'обновлены',
        ));

        $io->definitionList(
            ['UUID хозяйства' => $workspace->getUuid()->toRfc4122()],
            ['День формирования квитанций' => (string) $settings->getInvoiceGenerationDay()],
            ['Окно актуальности показаний, дней' => (string) $settings->getReadingFreshnessWindowDays()],
            ['Часовой пояс' => $workspace->getTimezone()],
        );

        return Command::SUCCESS;
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return trim(is_scalar($value) ? (string) $value : '');
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return trim(is_scalar($value) ? (string) $value : '');
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $this->stringOption($input, $name);

        return $value === '' ? null : $value;
    }

    private function intOption(InputInterface $input, string $name): int
    {
        $value = $this->stringOption($input, $name);

        return filter_var($value, FILTER_VALIDATE_INT) === false ? 0 : (int) $value;
    }
}

<?php

namespace App\Command;

use App\Repository\AccountStatementDeliveryAttemptRepository;
use App\Service\AccountStatementDeliveryMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:account-statement-deliveries:send',
    description: 'Send queued account statement deliveries.',
)]
final class AccountStatementDeliveriesSendCommand extends Command
{
    public function __construct(
        private readonly AccountStatementDeliveryAttemptRepository $attemptRepository,
        private readonly AccountStatementDeliveryMailer $deliveryMailer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum queued attempts to process.', 50)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        if ($limit < 1) {
            $io->error('Option --limit must be greater than zero.');

            return Command::FAILURE;
        }

        $attempts = $this->attemptRepository->findQueued($limit);

        if ($attempts === []) {
            $io->note('Queued deliveries not found.');

            return Command::SUCCESS;
        }

        $sentCount = 0;
        $failedCount = 0;

        foreach ($attempts as $attempt) {
            $delivery = $attempt->getDelivery();
            $recipient = $delivery?->getRecipientEmail() ?? 'unknown recipient';

            $attempt->markStarted();
            $this->entityManager->flush();

            try {
                $this->deliveryMailer->send($attempt);
                $attempt->markSucceeded();
                ++$sentCount;
                $io->writeln(sprintf('<info>sent</info> %s', $recipient));
            } catch (Throwable $exception) {
                $attempt->markFailed($this->normalizeFailureReason($exception));
                ++$failedCount;
                $io->writeln(sprintf('<error>failed</error> %s: %s', $recipient, $exception->getMessage()));
            }

            $this->entityManager->flush();
        }

        $io->success(sprintf('Processing finished. Sent: %d. Failed: %d.', $sentCount, $failedCount));

        return Command::SUCCESS;
    }

    private function normalizeFailureReason(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            $message = $exception::class;
        }

        return substr($message, 0, 2000);
    }
}

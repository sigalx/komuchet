<?php

namespace App\Command;

use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Repository\UserEmailIdentityRepository;
use App\Repository\UserPasswordHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create-admin',
    description: 'Create the first approved admin user with email and password.',
)]
class UserCreateAdminCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 12;
    private const IF_EXISTS_FAIL = 'fail';
    private const IF_EXISTS_SKIP = 'skip';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserEmailIdentityRepository $emailIdentityRepository,
        private readonly UserPasswordHistoryRepository $passwordHistoryRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email.')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Plain password. Prefer interactive input to keep shell history clean.')
            ->addOption('if-exists', null, InputOption::VALUE_REQUIRED, 'What to do when active email already exists: fail or skip.', self::IF_EXISTS_FAIL)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $this->normalizeInputEmail($input->getArgument('email'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('Invalid email "%s".', $email));

            return Command::INVALID;
        }

        $ifExists = $this->resolveIfExistsMode($input, $io);

        if ($ifExists === null) {
            return Command::INVALID;
        }

        $existingIdentity = $this->findActiveEmailIdentity(UserEmailIdentity::normalizeEmail($email));

        if ($existingIdentity instanceof UserEmailIdentity) {
            if ($ifExists === self::IF_EXISTS_SKIP) {
                $io->success(sprintf(
                    'Active email "%s" is already bound to user %s; skipped.',
                    $existingIdentity->getEmail(),
                    $existingIdentity->getUser()?->getUuid()->toRfc4122() ?? '<unknown>',
                ));

                return Command::SUCCESS;
            }

            $io->error(sprintf(
                'Active email "%s" is already bound to user %s.',
                $existingIdentity->getEmail(),
                $existingIdentity->getUser()?->getUuid()->toRfc4122() ?? '<unknown>',
            ));

            return Command::FAILURE;
        }

        $plainPassword = $this->resolvePassword($input, $io);

        if ($plainPassword === null) {
            return Command::INVALID;
        }

        $user = new User();
        $user->approve();
        $user->grantAdmin();

        $emailIdentity = new UserEmailIdentity($user, $email);
        $emailIdentity->markVerified();
        $user->addEmailIdentity($emailIdentity);

        $passwordHash = $this->passwordHasher->hashPassword($user, $plainPassword);
        $passwordCredential = new UserPasswordCredential($user, $passwordHash);
        $user->setPasswordCredential($passwordCredential);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->entityManager->persist($user);
            $this->entityManager->persist($emailIdentity);
            $this->entityManager->persist($passwordCredential);
            $this->entityManager->flush();

            $this->passwordHistoryRepository->append($user, $passwordHash, $passwordCredential->getChangedAt());

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $io->success(sprintf('Created admin user %s.', $email));
        $io->definitionList(
            ['User UUID' => $user->getUuid()->toRfc4122()],
            ['Global admin' => $user->isAdmin() ? 'yes' : 'no'],
            ['Email verified' => 'yes'],
            ['Approved' => 'yes'],
        );

        return Command::SUCCESS;
    }

    private function resolveIfExistsMode(InputInterface $input, SymfonyStyle $io): ?string
    {
        $ifExists = $input->getOption('if-exists');
        $ifExists = is_scalar($ifExists) ? trim((string) $ifExists) : '';

        if (in_array($ifExists, [self::IF_EXISTS_FAIL, self::IF_EXISTS_SKIP], true)) {
            return $ifExists;
        }

        $io->error(sprintf(
            'Invalid --if-exists value "%s". Allowed values: %s, %s.',
            $ifExists,
            self::IF_EXISTS_FAIL,
            self::IF_EXISTS_SKIP,
        ));

        return null;
    }

    private function normalizeInputEmail(mixed $email): string
    {
        return trim(is_scalar($email) ? (string) $email : '');
    }

    private function findActiveEmailIdentity(string $emailNormalized): ?UserEmailIdentity
    {
        return $this->emailIdentityRepository
            ->createQueryBuilder('identity')
            ->andWhere('identity.emailNormalized = :email')
            ->andWhere('identity.deletedAt IS NULL')
            ->setParameter('email', $emailNormalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function resolvePassword(InputInterface $input, SymfonyStyle $io): ?string
    {
        $password = $input->getOption('password');

        if (is_scalar($password) && trim((string) $password) !== '') {
            return $this->validatePassword((string) $password, $io) ? (string) $password : null;
        }

        if (!$input->isInteractive()) {
            $io->error('Password is required in non-interactive mode. Pass --password or run interactively.');

            return null;
        }

        $first = $io->askHidden('Password', function (?string $value): string {
            if ($value === null || $value === '') {
                throw new \RuntimeException('Password must not be empty.');
            }

            return $value;
        });

        $second = $io->askHidden('Repeat password', function (?string $value): string {
            if ($value === null || $value === '') {
                throw new \RuntimeException('Password must not be empty.');
            }

            return $value;
        });

        if ($first !== $second) {
            $io->error('Passwords do not match.');

            return null;
        }

        return $this->validatePassword($first, $io) ? $first : null;
    }

    private function validatePassword(string $password, SymfonyStyle $io): bool
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Password must contain at least %d characters.', self::MIN_PASSWORD_LENGTH));

            return false;
        }

        return true;
    }
}

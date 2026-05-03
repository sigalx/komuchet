<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Repository\UserEmailIdentityRepository;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads users by active verified email identity for form login.
 *
 * @implements UserProviderInterface<User>
 */
class UserEmailProvider implements UserProviderInterface
{
    public function __construct(
        private readonly UserEmailIdentityRepository $emailIdentityRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $emailNormalized = UserEmailIdentity::normalizeEmail($identifier);

        $identity = $this->emailIdentityRepository
            ->createQueryBuilder('identity')
            ->addSelect('user', 'passwordCredential')
            ->innerJoin('identity.user', 'user')
            ->leftJoin('user.passwordCredential', 'passwordCredential')
            ->andWhere('identity.emailNormalized = :email')
            ->andWhere('identity.deletedAt IS NULL')
            ->andWhere('identity.verifiedAt IS NOT NULL')
            ->setParameter('email', $emailNormalized)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        if (!$identity instanceof UserEmailIdentity || !$identity->getUser() instanceof User) {
            throw new UserNotFoundException(sprintf('Email "%s" was not found.', $identifier));
        }

        return $identity->getUser();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $reloadedUser = $this->userRepository
            ->createQueryBuilder('user')
            ->addSelect('passwordCredential')
            ->leftJoin('user.passwordCredential', 'passwordCredential')
            ->andWhere('user.uuid = :uuid')
            ->setParameter('uuid', $user->getUuid()->toRfc4122())
            ->getQuery()
            ->getOneOrNullResult()
        ;

        if (!$reloadedUser instanceof User) {
            throw new UserNotFoundException(sprintf('User "%s" was not found.', $user->getUserIdentifier()));
        }

        return $reloadedUser;
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }
}

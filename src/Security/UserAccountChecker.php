<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserAccountChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        $this->checkUser($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->checkUser($user);
    }

    private function checkUser(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->getDeletedAt() !== null) {
            throw new CustomUserMessageAccountStatusException('Учетная запись удалена.');
        }

        if ($user->getBlockedAt() !== null) {
            throw new CustomUserMessageAccountStatusException('Учетная запись заблокирована.');
        }

        if ($user->getApprovedAt() === null) {
            throw new CustomUserMessageAccountStatusException('Учетная запись ожидает подтверждения администратором.');
        }
    }
}

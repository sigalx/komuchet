<?php

namespace App\Service;

use App\Entity\User;
use DateTimeImmutable;

final class PasswordExpirationChecker
{
    public function isPasswordExpired(User $user, ?DateTimeImmutable $now = null): bool
    {
        $expiresAt = $user->getPasswordCredential()?->getExpiresAt();

        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt <= ($now ?? new DateTimeImmutable());
    }
}

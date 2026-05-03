<?php

namespace App\Demo;

final readonly class DemoPerson
{
    public const GENDER_MALE = 'male';
    public const GENDER_FEMALE = 'female';

    public function __construct(
        public int $number,
        public string $gender,
        public string $lastName,
        public string $firstName,
        public string $secondName,
        public string $email,
    ) {
    }

    public function fullName(): string
    {
        return sprintf('%s %s %s', $this->lastName, $this->firstName, $this->secondName);
    }

    public function isMale(): bool
    {
        return $this->gender === self::GENDER_MALE;
    }

    public function isFemale(): bool
    {
        return $this->gender === self::GENDER_FEMALE;
    }
}

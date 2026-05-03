<?php

namespace App\Service;

final readonly class ElectricityMeterReadingValidationViolation
{
    public function __construct(
        private string $code,
        private string $message,
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}

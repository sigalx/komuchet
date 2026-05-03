<?php

namespace App\Service;

final readonly class AccountStatementPaymentQrCode
{
    public function __construct(
        public string $payload,
        public string $dataUri,
    ) {
    }
}

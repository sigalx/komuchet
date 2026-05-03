<?php

namespace App\Service;

final class BillingRunIssueGenerationResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $closed = 0,
        public int $ignored = 0,
    ) {
    }
}

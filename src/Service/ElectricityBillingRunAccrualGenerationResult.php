<?php

namespace App\Service;

final class ElectricityBillingRunAccrualGenerationResult
{
    public function __construct(
        public int $created = 0,
        public int $skippedOpenIssues = 0,
        public int $skippedExisting = 0,
        public int $reusedPosted = 0,
        public int $failed = 0,
        public int $skippedIgnoredCalculationErrors = 0,
    ) {
    }
}

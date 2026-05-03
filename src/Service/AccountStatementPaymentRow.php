<?php

namespace App\Service;

use App\Entity\Payment;

final readonly class AccountStatementPaymentRow
{
    public function __construct(public Payment $payment)
    {
    }
}

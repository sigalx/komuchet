<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

final readonly class ParsedPaymentRequisites
{
    public function __construct(
        public ?string $recipientName,
        public ?string $recipientInn,
        public ?string $recipientKpp,
        public ?string $bankAccount,
        public ?string $bankBik,
        public ?string $bankName,
        public ?string $payerName,
        public ?string $paymentPurpose,
        public ?string $amountToPay,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'recipient_inn' => $this->recipientInn,
            'recipient_kpp' => $this->recipientKpp,
            'bank_account' => $this->bankAccount,
            'bank_bik' => $this->bankBik,
            'bank_name' => $this->bankName,
            'payer_name' => $this->payerName,
            'payment_purpose' => $this->paymentPurpose,
            'amount_to_pay' => $this->amountToPay,
        ];
    }
}

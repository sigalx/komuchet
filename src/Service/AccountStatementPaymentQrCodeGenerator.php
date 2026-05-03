<?php

namespace App\Service;

use App\Entity\AccountStatementSnapshot;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

final readonly class AccountStatementPaymentQrCodeGenerator
{
    public function generate(AccountStatementSnapshot $statement): ?AccountStatementPaymentQrCode
    {
        $payload = $this->buildPayload($statement);

        if ($payload === null) {
            return null;
        }

        $result = (new Builder(
            writer: new PngWriter(),
            validateResult: false,
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 360,
            margin: 16,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();

        return new AccountStatementPaymentQrCode($payload, $result->getDataUri());
    }

    public function buildPayload(AccountStatementSnapshot $statement): ?string
    {
        if (!$this->hasRequiredRequisites($statement)) {
            return null;
        }

        $sumKopecks = $this->moneyToKopecks($statement->getAmountToPay());

        if ($sumKopecks <= 0) {
            return null;
        }

        $fields = [
            'Name' => $statement->getPaymentRecipientName(),
            'PersonalAcc' => $statement->getPaymentBankAccount(),
            'BankName' => $statement->getPaymentBankName(),
            'BIC' => $statement->getPaymentBankBik(),
            'CorrespAcc' => $statement->getPaymentBankCorrespondentAccount(),
            'PayeeINN' => $statement->getPaymentRecipientInn(),
            'KPP' => $statement->getPaymentRecipientKpp(),
            'Purpose' => $statement->getPaymentPurpose(),
            'Sum' => (string) $sumKopecks,
        ];

        $parts = ['ST00012'];

        foreach ($fields as $name => $value) {
            $value = $this->normalizeQrValue($value);

            if ($value === null) {
                continue;
            }

            $parts[] = $name.'='.$value;
        }

        return implode('|', $parts);
    }

    private function hasRequiredRequisites(AccountStatementSnapshot $statement): bool
    {
        return $this->normalizeQrValue($statement->getPaymentRecipientName()) !== null
            && $this->normalizeQrValue($statement->getPaymentBankAccount()) !== null
            && $this->normalizeQrValue($statement->getPaymentBankName()) !== null
            && $this->normalizeQrValue($statement->getPaymentBankBik()) !== null;
    }

    private function normalizeQrValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = str_replace(['|', '='], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $value === '' ? null : $value;
    }

    private function moneyToKopecks(string $amount): int
    {
        $amount = trim(str_replace(',', '.', $amount));

        if (!preg_match('/^(?<whole>\d+)(?:\.(?<fraction>\d{0,2}))?$/', $amount, $matches)) {
            return 0;
        }

        $fraction = str_pad(substr($matches['fraction'] ?? '', 0, 2), 2, '0');

        return ((int) $matches['whole'] * 100) + (int) $fraction;
    }
}

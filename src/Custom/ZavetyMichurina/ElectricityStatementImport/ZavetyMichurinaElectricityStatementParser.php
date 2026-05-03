<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

final class ZavetyMichurinaElectricityStatementParser
{
    private const MONTHS = [
        'январь' => 1,
        'февраль' => 2,
        'март' => 3,
        'апрель' => 4,
        'май' => 5,
        'июнь' => 6,
        'июль' => 7,
        'август' => 8,
        'сентябрь' => 9,
        'октябрь' => 10,
        'ноябрь' => 11,
        'декабрь' => 12,
    ];

    public function parse(string $text): ParsedElectricityStatement
    {
        $text = $this->normalizeText($text);
        $firstPage = explode("\f", $text, 2)[0];
        $rows = $this->parseRows($firstPage);
        $warnings = [];

        if ($rows === []) {
            $warnings[] = 'Не найдены строки помесячной расчетной таблицы.';
        }

        $accountNumber = $this->extractAccountNumber($text);

        if ($accountNumber === null) {
            $warnings[] = 'Не найден номер участка.';
        }

        $subscriberFullName = $this->extractSubscriberFullName($text);

        if ($subscriberFullName === null) {
            $warnings[] = 'Не найдено ФИО владельца участка.';
        }

        return new ParsedElectricityStatement(
            accountNumber: $accountNumber,
            subscriberFullName: $subscriberFullName,
            meterInstalledOn: $this->extractMeterInstalledOn($text),
            meterSerialNumber: $this->extractMeterSerialNumber($text),
            meterInitialReading: $this->extractMeterInitialReading($text),
            rows: $rows,
            totals: $this->extractTotals($firstPage),
            paymentRequisites: $this->extractPaymentRequisites($text),
            warnings: $warnings,
        );
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    }

    private function extractAccountNumber(string $text): ?string
    {
        if (preg_match('/владельца участка №\s+(\d+\s*-\s*\d+)/ui', $text, $matches)) {
            return preg_replace('/\s+/u', '', $matches[1]) ?: null;
        }

        if (preg_match('/Назначение:\s*Сад\s+(\d+)\s+участок\s+(\d+)/ui', $text, $matches)) {
            return sprintf('%s-%s', $matches[1], $matches[2]);
        }

        return null;
    }

    private function extractSubscriberFullName(string $text): ?string
    {
        if (preg_match('/ФИО:\s*([^;]+);/u', $text, $matches)) {
            return ZavetyMichurinaPersonNameNormalizer::normalizeFullName($matches[1]);
        }

        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $candidate = trim((string) preg_replace('/\s+СИП\s*$/u', '', $line));

            if (preg_match('/^\p{L}[\p{L}-]*(?:\s+\p{L}[\p{L}-]*){2}$/u', $candidate)) {
                return ZavetyMichurinaPersonNameNormalizer::normalizeFullName($candidate);
            }
        }

        return null;
    }

    private function extractMeterInstalledOn(string $text): ?string
    {
        if (!preg_match('/Новый сч[её]тчик\s+(\d{2}\.\d{2}\.\d{2})\./ui', $text, $matches)) {
            return null;
        }

        return $this->normalizeShortDate($matches[1]);
    }

    private function extractMeterSerialNumber(string $text): ?string
    {
        if (!preg_match('/\bНомер\s+([0-9A-Za-zА-Яа-яЁё-]+)/u', $text, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function extractMeterInitialReading(string $text): ?string
    {
        if (!preg_match('/\bПоказания\s+(\d+)/u', $text, $matches)) {
            return null;
        }

        return $this->normalizeInteger($matches[1]);
    }

    /**
     * @return list<ParsedElectricityStatementRow>
     */
    private function parseRows(string $text): array
    {
        $monthPattern = implode('|', array_keys(self::MONTHS));
        $rows = [];

        foreach (explode("\n", $text) as $line) {
            if (!preg_match(
                '/^\s*(20\d{2})\s+('.$monthPattern.')\s+(\d+)\s+(\d+)\s+(\d+)\s+([\d,]+)\s+(\d+)\s+([\d,]+)\s+([\d,]+)(?:\s+(\d{2}\.\d{2}\.\d{2})\.)?(?:\s+([\d,]+))?\s*$/u',
                $line,
                $matches
            )) {
                continue;
            }

            $year = (int) $matches[1];
            $monthName = $matches[2];
            $month = self::MONTHS[$monthName];

            $rows[] = new ParsedElectricityStatementRow(
                year: $year,
                month: $month,
                monthName: $monthName,
                periodStart: sprintf('%04d-%02d-01', $year, $month),
                readingValueKwh: $this->normalizeInteger($matches[3]),
                consumptionKwh: $this->normalizeInteger($matches[4]),
                socialNormKwh: $this->normalizeInteger($matches[5]),
                socialNormRate: $this->normalizeDecimal($matches[6]),
                aboveNormKwh: $this->normalizeInteger($matches[7]),
                aboveNormRate: $this->normalizeDecimal($matches[8]),
                accruedAmount: $this->normalizeDecimal($matches[9]),
                paidOn: isset($matches[10]) && $matches[10] !== '' ? $this->normalizeShortDate($matches[10]) : null,
                paidAmount: isset($matches[11]) && $matches[11] !== '' ? $this->normalizeDecimal($matches[11]) : null,
            );
        }

        return $rows;
    }

    /**
     * @return array{total_accrued: string|null, total_paid: string|null, balance: string|null}
     */
    private function extractTotals(string $text): array
    {
        $lines = array_reverse(explode("\n", $text));

        foreach ($lines as $line) {
            if (preg_match('/^\s*([\d,]+)\s+([\d,]+)\s+([\d,]+)\s*$/u', $line, $matches)) {
                return [
                    'total_accrued' => $this->normalizeDecimal($matches[1]),
                    'total_paid' => $this->normalizeDecimal($matches[2]),
                    'balance' => $this->normalizeDecimal($matches[3]),
                ];
            }
        }

        return [
            'total_accrued' => null,
            'total_paid' => null,
            'balance' => null,
        ];
    }

    private function extractPaymentRequisites(string $text): ParsedPaymentRequisites
    {
        $recipientName = null;

        if (preg_match('/(Садоводческое Некоммерческое Товарищество\s+"Заветы Мичурина")/u', $text, $matches)) {
            $recipientName = trim($matches[1]);
        }

        $recipientInn = null;
        $recipientKpp = null;
        $bankAccount = null;

        if (preg_match('/ИНН\s+(\d+)\s+КПП\s+(\d+)\s+(\d{20})/u', $text, $matches)) {
            $recipientInn = $matches[1];
            $recipientKpp = $matches[2];
            $bankAccount = $matches[3];
        } elseif (preg_match('/ИНН\s+(\d+)\s+КПП\s+(\d+)/u', $text, $matches)) {
            $recipientInn = $matches[1];
            $recipientKpp = $matches[2];
        }

        if ($bankAccount === null && preg_match('/\n\s*(\d{20})\s*\n/u', $text, $matches)) {
            $bankAccount = $matches[1];
        }

        $bankBik = null;
        $bankName = null;

        if (preg_match('/БИК\s+(\d{9})\s+\(([^)]+)\)/u', $text, $matches)) {
            $bankBik = $matches[1];
            $bankName = trim($matches[2]);
        }

        $payerName = null;
        $paymentPurpose = null;

        if (preg_match('/ФИО:\s*([^;]+);\s*Назначение:\s*(.+)$/mu', $text, $matches)) {
            $payerName = ZavetyMichurinaPersonNameNormalizer::normalizeFullName($matches[1]);
            $paymentPurpose = trim($matches[2]);
        }

        $amountToPay = null;

        if (preg_match('/Сумма:\s*(\d+)\s+руб\.\s+(\d+)\s+коп/ui', $text, $matches)) {
            $amountToPay = sprintf('%d.%02d', (int) $matches[1], (int) $matches[2]);
        }

        return new ParsedPaymentRequisites(
            recipientName: $recipientName,
            recipientInn: $recipientInn,
            recipientKpp: $recipientKpp,
            bankAccount: $bankAccount,
            bankBik: $bankBik,
            bankName: $bankName,
            payerName: $payerName,
            paymentPurpose: $paymentPurpose,
            amountToPay: $amountToPay,
        );
    }

    private function normalizeDecimal(string $value): string
    {
        return str_replace(',', '.', str_replace(' ', '', trim($value)));
    }

    private function normalizeInteger(string $value): string
    {
        $normalized = ltrim(trim($value), '0');

        return $normalized === '' ? '0' : $normalized;
    }

    private function normalizeShortDate(string $value): string
    {
        [$day, $month, $year] = array_map('intval', explode('.', $value));
        $year += $year >= 70 ? 1900 : 2000;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

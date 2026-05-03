<?php

namespace App\Tests;

use App\Command\ZavetyMichurinaParseElectricityStatementCommand;
use App\Custom\ZavetyMichurina\ElectricityStatementImport\PdfLayoutTextExtractor;
use App\Custom\ZavetyMichurina\ElectricityStatementImport\ZavetyMichurinaElectricityStatementParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ZavetyMichurinaElectricityStatementParserTest extends TestCase
{
    public function testParserExtractsCoreStatementData(): void
    {
        $parser = new ZavetyMichurinaElectricityStatementParser();
        $statement = $parser->parse($this->fixtureText());
        $data = $statement->toArray();

        self::assertSame([], $data['warnings']);
        self::assertSame('9-123', $data['account']['number']);
        self::assertSame('Иванов Иван Иванович', $data['subscriber']['full_name']);
        self::assertSame('2022-11-25', $data['electricity_meter']['installed_on']);
        self::assertSame('47371730', $data['electricity_meter']['serial_number']);
        self::assertSame('1', $data['electricity_meter']['initial_reading_kwh']);
        self::assertCount(6, $data['rows']);

        self::assertSame([
            'year' => 2019,
            'month' => 11,
            'month_name' => 'ноябрь',
            'period_start' => '2019-11-01',
            'reading_value_kwh' => '351',
            'consumption_kwh' => '350',
            'social_norm_kwh' => '350',
            'social_norm_rate' => '3.84',
            'above_norm_kwh' => '0',
            'above_norm_rate' => '6.45',
            'accrued_amount' => '1344',
            'paid_on' => '2019-07-24',
            'paid_amount' => '1506',
        ], $data['rows'][0]);

        self::assertSame('2023-05-01', $data['rows'][4]['period_start']);
        self::assertSame('3875.02', $data['rows'][4]['accrued_amount']);
        self::assertNull($data['rows'][4]['paid_on']);
        self::assertNull($data['rows'][4]['paid_amount']);
        self::assertSame('445791.3', $data['totals']['total_accrued']);
        self::assertSame('430559', $data['totals']['total_paid']);
        self::assertSame('15232.53', $data['totals']['balance']);
        self::assertSame('Садоводческое Некоммерческое Товарищество "Заветы Мичурина"', $data['payment_requisites']['recipient_name']);
        self::assertSame('5262083483', $data['payment_requisites']['recipient_inn']);
        self::assertSame('526201001', $data['payment_requisites']['recipient_kpp']);
        self::assertSame('40703810842050000900', $data['payment_requisites']['bank_account']);
        self::assertSame('042202603', $data['payment_requisites']['bank_bik']);
        self::assertSame('Волго-Вятский банк ПАО Сбербанк', $data['payment_requisites']['bank_name']);
        self::assertSame('Иванов Иван Иванович', $data['payment_requisites']['payer_name']);
        self::assertSame('Сад 9 участок 123 взносы свет', $data['payment_requisites']['payment_purpose']);
        self::assertSame('15232.53', $data['payment_requisites']['amount_to_pay']);
    }

    public function testParserNormalizesUppercasePersonNameFromStatement(): void
    {
        $parser = new ZavetyMichurinaElectricityStatementParser();
        $text = str_replace('Иванов Иван Иванович', 'ЩЕГЛОВ Андрей Владимирович', $this->fixtureText());
        $data = $parser->parse($text)->toArray();

        self::assertSame('Щеглов Андрей Владимирович', $data['subscriber']['full_name']);
        self::assertSame('Щеглов Андрей Владимирович', $data['payment_requisites']['payer_name']);
    }

    public function testDryRunCommandPrintsJsonFromExtractedText(): void
    {
        $command = new ZavetyMichurinaParseElectricityStatementCommand(
            new PdfLayoutTextExtractor(),
            new ZavetyMichurinaElectricityStatementParser(),
        );
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            'file' => $this->fixturePath(),
            '--from-text' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $data = json_decode($commandTester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('zavety_michurina_electricity_statement', $data['source']['format']);
        self::assertSame('dry_run', $data['source']['mode']);
        self::assertSame('9-123', $data['account']['number']);
        self::assertCount(6, $data['rows']);
    }

    private function fixtureText(): string
    {
        return (string) file_get_contents($this->fixturePath());
    }

    private function fixturePath(): string
    {
        return __DIR__.'/Fixtures/zavety_michurina/electricity-statement-layout.txt';
    }
}

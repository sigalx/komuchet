<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\Accrual;
use App\Entity\AuditLog;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use App\Entity\ZavetyMichurinaStatementImportFile;
use App\Enum\AccrualType;
use App\Enum\AuditLogSource;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminZavetyMichurinaStatementImportControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/zavety-michurina/statement-imports');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanBrowseImportBatchesAndFiles(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Исторические PDF', $admin);
        $file = new ZavetyMichurinaStatementImportFile(
            batch: $batch,
            originalFilename: 'statement-9-123.pdf',
            sourceSha256: str_repeat('a', 64),
            fileSizeBytes: 123456,
            createdBy: $admin,
        );
        $file->markParsed([
            'source' => [
                'format' => 'zavety_michurina_electricity_statement',
                'mode' => 'dry_run',
            ],
            'account' => [
                'number' => '9-123',
            ],
            'subscriber' => [
                'full_name' => 'Иванов Иван Иванович',
            ],
            'electricity_meter' => [
                'installed_on' => '2022-11-25',
                'serial_number' => '47371730',
                'initial_reading_kwh' => '1',
            ],
            'rows' => [
                [
                    'period_start' => '2026-03-01',
                    'reading_value_kwh' => '58468',
                    'accrued_amount' => '15232.53',
                ],
            ],
            'totals' => [
                'balance' => '15232.53',
            ],
            'payment_requisites' => [
                'recipient_name' => 'СНТ "Заветы Мичурина"',
            ],
            'warnings' => [],
        ], $admin);

        $this->entityManager()->persist($batch);
        $this->entityManager()->persist($file);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/zavety-michurina/statement-imports');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Импорт PDF-квитанций ЗМ');
        $this->assertSelectorExists('a[href="/admin/zavety-michurina/statement-imports"].active');
        $this->assertSelectorTextContains('body', 'Исторические PDF');
        $this->assertSelectorTextContains('body', 'Распознана: 1');

        $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Исторические PDF');
        $this->assertSelectorTextContains('body', 'statement-9-123.pdf');
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorTextContains('body', 'Распознана');

        $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/files/%s', $file->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'statement-9-123.pdf');
        $this->assertSelectorTextContains('body', '№ 47371730');
        $this->assertSelectorTextContains('body', '15232.53');
        $this->assertSelectorTextContains('body', 'Пересчитанный остаток к оплате по строкам импорта: 15232.53.');
        $this->assertSelectorTextContains('body', 'JSON результата');
        $this->assertSelectorTextContains('body', 'zavety_michurina_electricity_statement');
        $this->assertSelectorTextContains('body', 'Предпросмотр применения');
        $this->assertSelectorTextContains('body', 'Будет создано');
        $this->assertSelectorTextContains('body', 'Переиспользуется');
        $this->assertSelectorTextContains('body', 'Блокеры');
        $this->assertSelectorTextContains('body', 'Подробный список решений');
        $this->assertSelectorTextContains('body', 'Будет создан участок 9-123.');
        $this->assertSelectorTextContains('body', 'Будет создан абонент Иванов Иван Иванович.');

        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Parsed, $file->getStatus());
    }

    public function testAdminCanSortAndPaginateImportBatches(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');

        for ($i = 1; $i <= 55; ++$i) {
            $this->entityManager()->persist(new ZavetyMichurinaStatementImportBatch($workspace, sprintf('Пачка %03d', $i), $admin));
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/zavety-michurina/statement-imports?sort=name&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $batch55Position = strpos($content, 'Пачка 055');
        $batch54Position = strpos($content, 'Пачка 054');
        self::assertNotFalse($batch55Position);
        self::assertNotFalse($batch54Position);
        self::assertLessThan($batch54Position, $batch55Position);
        $this->assertSelectorExists('a[href="/admin/zavety-michurina/statement-imports?sort=name&dir=asc&page=1"]');

        $client->request('GET', '/admin/zavety-michurina/statement-imports?sort=name&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'Пачка 005');
        $this->assertSelectorTextNotContains('body', 'Пачка 055');
    }

    public function testAdminCanSortAndPaginateImportFiles(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Большая пачка', $admin);
        $this->entityManager()->persist($batch);

        for ($i = 1; $i <= 55; ++$i) {
            $this->entityManager()->persist(new ZavetyMichurinaStatementImportFile(
                batch: $batch,
                originalFilename: sprintf('statement-%03d.pdf', $i),
                sourceSha256: hash('sha256', sprintf('statement-%03d.pdf', $i)),
                fileSizeBytes: 123456,
                createdBy: $admin,
            ));
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s?sort=original_filename&dir=desc', $batch->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $file55Position = strpos($content, 'statement-055.pdf');
        $file54Position = strpos($content, 'statement-054.pdf');
        self::assertNotFalse($file55Position);
        self::assertNotFalse($file54Position);
        self::assertLessThan($file54Position, $file55Position);
        $this->assertSelectorExists(sprintf(
            'a[href="/admin/zavety-michurina/statement-imports/%s?sort=original_filename&dir=asc&page=1"]',
            $batch->getUuid()->toRfc4122(),
        ));

        $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s?sort=original_filename&dir=desc&page=2', $batch->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'statement-005.pdf');
        $this->assertSelectorTextNotContains('body', 'statement-055.pdf');
    }

    public function testAdminCanUploadFilesIntoNewBatch(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/zavety-michurina/statement-imports/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Загрузка PDF-квитанций ЗМ');

        $token = $crawler
            ->filter('input[name="zavety_michurina_statement_import_upload[_token]"]')
            ->attr('value');
        $fixturePath = __DIR__.'/Fixtures/zavety_michurina/electricity-statement-layout.txt';
        $uploadedFile = new UploadedFile(
            $fixturePath,
            'statement-9-123.pdf',
            null,
            null,
            true,
        );

        $client->request(
            'POST',
            '/admin/zavety-michurina/statement-imports/new',
            [
                'zavety_michurina_statement_import_upload' => [
                    'name' => 'Загрузка из UI',
                    '_token' => $token,
                ],
            ],
            [
                'zavety_michurina_statement_import_upload' => [
                    'files' => [$uploadedFile],
                ],
            ],
        );

        $batch = $this->entityManager()
            ->getRepository(ZavetyMichurinaStatementImportBatch::class)
            ->findOneBy(['workspace' => $workspace, 'name' => 'Загрузка из UI']);

        self::assertInstanceOf(ZavetyMichurinaStatementImportBatch::class, $batch);
        $this->assertResponseRedirects(sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));

        $importFile = $this->entityManager()
            ->getRepository(ZavetyMichurinaStatementImportFile::class)
            ->findOneBy(['batch' => $batch]);

        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $importFile);
        self::assertSame('statement-9-123.pdf', $importFile->getOriginalFilename());
        self::assertSame($workspace->getUuid()->toRfc4122(), $importFile->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame(hash_file('sha256', $fixturePath), $importFile->getSourceSha256());
        self::assertSame(filesize($fixturePath), $importFile->getFileSizeBytes());
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Failed, $importFile->getStatus());
        self::assertNotNull($importFile->getParseError());
        self::assertNull($importFile->getParsedResult());
    }

    public function testAdminCanUploadRealRedactedPdfIntoNewBatch(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/zavety-michurina/statement-imports/new');

        $this->assertResponseIsSuccessful();

        $token = $crawler
            ->filter('input[name="zavety_michurina_statement_import_upload[_token]"]')
            ->attr('value');
        $fixturePath = dirname(__DIR__).'/docs/samples/electricity-statement-redacted.pdf';
        $uploadedFile = new UploadedFile(
            $fixturePath,
            'electricity-statement-redacted.pdf',
            'application/pdf',
            null,
            true,
        );

        $client->request(
            'POST',
            '/admin/zavety-michurina/statement-imports/new',
            [
                'zavety_michurina_statement_import_upload' => [
                    'name' => 'Реальная обезличенная PDF',
                    '_token' => $token,
                ],
            ],
            [
                'zavety_michurina_statement_import_upload' => [
                    'files' => [$uploadedFile],
                ],
            ],
        );

        $batch = $this->entityManager()
            ->getRepository(ZavetyMichurinaStatementImportBatch::class)
            ->findOneBy(['workspace' => $workspace, 'name' => 'Реальная обезличенная PDF']);

        self::assertInstanceOf(ZavetyMichurinaStatementImportBatch::class, $batch);
        $this->assertResponseRedirects(sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));

        $importFile = $this->entityManager()
            ->getRepository(ZavetyMichurinaStatementImportFile::class)
            ->findOneBy(['batch' => $batch]);

        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $importFile);
        self::assertSame('electricity-statement-redacted.pdf', $importFile->getOriginalFilename());
        self::assertSame(hash_file('sha256', $fixturePath), $importFile->getSourceSha256());
        self::assertSame(filesize($fixturePath), $importFile->getFileSizeBytes());
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Parsed, $importFile->getStatus());
        self::assertSame('9-123', $importFile->getDetectedAccountNumber());
        self::assertSame('Иванов Иван Иванович', $importFile->getDetectedSubscriberFullName());
        self::assertNull($importFile->getParseError());

        $parsedResult = $importFile->getParsedResult();
        self::assertIsArray($parsedResult);
        self::assertSame('9-123', $parsedResult['account']['number']);
        self::assertSame('Иванов Иван Иванович', $parsedResult['subscriber']['full_name']);
        self::assertSame('15232.53', $parsedResult['totals']['balance']);
        self::assertCount(44, $parsedResult['rows']);
        self::assertSame([], $parsedResult['warnings']);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'electricity-statement-redacted.pdf');
        $this->assertSelectorTextContains('body', 'Распознана');
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');

        $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/files/%s', $importFile->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Предпросмотр применения');
        $this->assertSelectorTextContains('body', 'Будет создано');
        $this->assertSelectorTextContains('body', 'Проверить');
        $this->assertSelectorTextContains('body', 'Подробный список решений');
        $this->assertSelectorTextContains('body', 'В PDF указана замена счетчика: 2 сегмента; новый счетчик № 47371730 будет импортирован.');
        $this->assertSelectorTextContains('body', 'К импорту подготовлено строк показаний: 44.');
        $this->assertSelectorTextContains('body', 'Уникальных непрерывных наборов ставок: 8.');
        $this->assertSelectorTextContains('body', 'Ставки из PDF будут сопоставляться по уникальным наборам и не должны дублироваться.');
        $this->assertSelectorTextContains('body', 'Нормы сопоставляются по расчетному периоду: год и месяц.');
        $this->assertSelectorTextContains('body', '12.2022: 500 кВт*ч');
        $responseContent = $client->getResponse()->getContent();
        self::assertIsString($responseContent);
        self::assertStringNotContainsString('встречается с разными нормами', $responseContent);
    }

    public function testApplyPreviewReusesExistingDomainData(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $client->loginUser($admin);

        $account = (new Account($workspace))->setNumber('9-123');
        $subscriber = (new Subscriber($workspace))
            ->setLastName('Иванов')
            ->setFirstName('Иван')
            ->setSecondName('Иванович');
        $access = new SubscriberAccountAccess($workspace, $subscriber, $account);
        $meter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2022-11-25')))
            ->setSerialNumber('47371730');
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode('single')
            ->setName('Однотарифная')
            ->setSortOrder(10);
        $meterRegister = new ElectricityMeterRegister($workspace, $meter, $tariffZone);
        $tariffProfile = (new ElectricityTariffProfile($workspace))
            ->setCode('main')
            ->setName('Основной');
        $socialBand = (new ElectricityConsumptionBand($workspace))
            ->setCode('social_norm')
            ->setName('Социальная норма')
            ->setSortOrder(10);
        $aboveBand = (new ElectricityConsumptionBand($workspace))
            ->setCode('above_social_norm')
            ->setName('Сверх социальной нормы')
            ->setSortOrder(20);
        $reading = new ElectricityMeterReading(
            workspace: $workspace,
            electricityMeter: $meter,
            tariffZone: $tariffZone,
            readingValue: '58468',
            takenOn: new DateTimeImmutable('2026-04-01'),
        );
        $payment = new Payment(
            workspace: $workspace,
            account: $account,
            amount: '15232.53',
            paidOn: new DateTimeImmutable('2026-03-13'),
        );
        $requisites = (new PaymentRequisiteProfile($workspace, new DateTimeImmutable('2026-01-01')))
            ->setCode('electricity')
            ->setName('Электроэнергия')
            ->setRecipientName('Садоводческое Некоммерческое Товарищество "Заветы Мичурина"')
            ->setRecipientInn('5262083483')
            ->setRecipientKpp('526201001')
            ->setBankName('Волго-Вятский банк ПАО Сбербанк')
            ->setBankBik('042202603')
            ->setBankAccount('40703810842050000900');

        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Повторная загрузка', $admin);
        $file = new ZavetyMichurinaStatementImportFile(
            batch: $batch,
            originalFilename: 'statement-9-123.pdf',
            sourceSha256: str_repeat('b', 64),
            fileSizeBytes: 123456,
            createdBy: $admin,
        );
        $file->markParsed([
            'source' => [
                'format' => 'zavety_michurina_electricity_statement',
                'mode' => 'dry_run',
            ],
            'account' => [
                'number' => '9-123',
            ],
            'subscriber' => [
                'full_name' => 'Иванов Иван Иванович',
            ],
            'electricity_meter' => [
                'installed_on' => '2022-11-25',
                'serial_number' => '47371730',
                'initial_reading_kwh' => '1',
            ],
            'rows' => [
                [
                    'year' => 2026,
                    'month' => 3,
                    'period_start' => '2026-03-01',
                    'reading_value_kwh' => '58468',
                    'consumption_kwh' => '1847',
                    'social_norm_kwh' => '400',
                    'social_norm_rate' => '5.56',
                    'above_norm_kwh' => '1447',
                    'above_norm_rate' => '8.99',
                    'accrued_amount' => '15232.53',
                    'paid_on' => '2026-03-13',
                    'paid_amount' => '15232.53',
                ],
            ],
            'totals' => [
                'total_accrued' => '15232.53',
                'total_paid' => '15232.53',
                'balance' => '0',
            ],
            'payment_requisites' => [
                'recipient_name' => 'Садоводческое Некоммерческое Товарищество "Заветы Мичурина"',
                'recipient_inn' => '5262083483',
                'recipient_kpp' => '526201001',
                'bank_account' => '40703810842050000900',
                'bank_bik' => '042202603',
                'bank_name' => 'Волго-Вятский банк ПАО Сбербанк',
            ],
            'warnings' => [],
        ], $admin);

        $this->entityManager()->persist($account);
        $this->entityManager()->persist($subscriber);
        $this->entityManager()->persist($access);
        $this->entityManager()->persist($meter);
        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->persist($meterRegister);
        $this->entityManager()->persist($tariffProfile);
        $this->entityManager()->persist($socialBand);
        $this->entityManager()->persist($aboveBand);
        $this->entityManager()->persist($reading);
        $this->entityManager()->persist($payment);
        $this->entityManager()->persist($requisites);
        $this->entityManager()->persist($batch);
        $this->entityManager()->persist($file);
        $this->entityManager()->flush();

        $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/files/%s', $file->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Найден существующий участок 9-123.');
        $this->assertSelectorTextContains('body', 'Найден существующий абонент Иванов Иван Иванович.');
        $this->assertSelectorTextContains('body', 'Активная связь абонента с участком уже существует.');
        $this->assertSelectorTextContains('body', 'Найден активный электросчетчик участка.');
        $this->assertSelectorTextContains('body', 'Строк: 1, новых: 0, дублей: 1, конфликтов: 0.');
        $this->assertSelectorTextContains('body', 'Строк оплат: 1, новых: 0, возможных дублей: 1.');
        $this->assertSelectorTextContains('body', 'Найден профиль реквизитов Электроэнергия.');
        $this->assertSelectorTextContains('body', 'Базовые справочники тарифов найдены; ставки будут сопоставляться по периоду и значениям.');
    }

    public function testAdminCanApplyParsedImportFile(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Apply test', $admin);
        $file = new ZavetyMichurinaStatementImportFile(
            batch: $batch,
            originalFilename: 'statement-9-123.pdf',
            sourceSha256: str_repeat('c', 64),
            fileSizeBytes: 123456,
            createdBy: $admin,
        );
        $file->markParsed([
            'source' => [
                'format' => 'zavety_michurina_electricity_statement',
                'mode' => 'dry_run',
            ],
            'account' => [
                'number' => '9-123',
            ],
            'subscriber' => [
                'full_name' => 'Иванов Иван Иванович',
            ],
            'electricity_meter' => [
                'installed_on' => '2022-11-25',
                'serial_number' => '47371730',
                'initial_reading_kwh' => '1',
            ],
            'rows' => [
                [
                    'year' => 2022,
                    'month' => 11,
                    'period_start' => '2022-11-01',
                    'reading_value_kwh' => '2273',
                    'consumption_kwh' => '1412',
                    'social_norm_kwh' => '500',
                    'social_norm_rate' => '4.12',
                    'above_norm_kwh' => '912',
                    'above_norm_rate' => '7.23',
                    'accrued_amount' => '8653.76',
                    'paid_on' => '2022-12-06',
                    'paid_amount' => '8653.76',
                ],
                [
                    'year' => 2022,
                    'month' => 12,
                    'period_start' => '2022-12-01',
                    'reading_value_kwh' => '718',
                    'consumption_kwh' => '717',
                    'social_norm_kwh' => '500',
                    'social_norm_rate' => '4.48',
                    'above_norm_kwh' => '217',
                    'above_norm_rate' => '7.23',
                    'accrued_amount' => '3808.91',
                    'paid_on' => '2023-01-08',
                    'paid_amount' => '3808.91',
                ],
            ],
            'totals' => [
                'total_accrued' => '12462.67',
                'total_paid' => '12462.67',
                'balance' => '0',
            ],
            'payment_requisites' => [
                'recipient_name' => 'Садоводческое Некоммерческое Товарищество "Заветы Мичурина"',
                'recipient_inn' => '5262083483',
                'recipient_kpp' => '526201001',
                'bank_account' => '40703810842050000900',
                'bank_bik' => '042202603',
                'bank_name' => 'Волго-Вятский банк ПАО Сбербанк',
                'payer_name' => 'Иванов Иван Иванович',
                'payment_purpose' => 'Сад 9 участок 123 взносы свет',
            ],
            'warnings' => [],
        ], $admin);

        $this->entityManager()->persist($batch);
        $this->entityManager()->persist($file);
        $this->entityManager()->flush();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/files/%s', $file->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Применить');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/zavety-michurina/statement-imports/files/%s/apply"] input[name="_token"]', $file->getUuid()))
            ->attr('value');

        $client->request('POST', sprintf('/admin/zavety-michurina/statement-imports/files/%s/apply', $file->getUuid()), [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/zavety-michurina/statement-imports/files/%s', $file->getUuid()));
        $this->entityManager()->clear();

        $appliedFile = $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->find($file->getUuid());
        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $appliedFile);
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Applied, $appliedFile->getStatus());

        self::assertSame(1, $this->entityManager()->getRepository(Account::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(Subscriber::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(SubscriberAccountAccess::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(AccountElectricityTariffProfileAssignment::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityTariffZone::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityTariffProfile::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityTariffPeriod::class)->count([]));
        self::assertSame(4, $this->entityManager()->getRepository(ElectricityTariffRate::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityConsumptionBand::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityConsumptionBandRule::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleAllScope::class)->count([]));
        self::assertSame(4, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleRange::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityMeter::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityMeterRegister::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityMeterReading::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(Payment::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(Accrual::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(PaymentRequisiteProfile::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(PaymentRequisiteAssignment::class)->count([]));

        $paymentRequisiteAssignment = $this->entityManager()->getRepository(PaymentRequisiteAssignment::class)->findOneBy([]);
        self::assertInstanceOf(PaymentRequisiteAssignment::class, $paymentRequisiteAssignment);
        self::assertSame(AccrualType::Electricity, $paymentRequisiteAssignment->getAccrualType());
        self::assertSame('2022-11-01', $paymentRequisiteAssignment->getValidFrom()->format('Y-m-d'));

        $activeMeter = $this->entityManager()->getRepository(ElectricityMeter::class)->findOneBy(['serialNumber' => '47371730']);
        self::assertInstanceOf(ElectricityMeter::class, $activeMeter);
        self::assertNull($activeMeter->getRemovedOn());

        $novemberReading = $this->entityManager()->getRepository(ElectricityMeterReading::class)->findOneBy(['readingValue' => '2273.000']);
        $decemberReading = $this->entityManager()->getRepository(ElectricityMeterReading::class)->findOneBy(['readingValue' => '718.000']);
        self::assertInstanceOf(ElectricityMeterReading::class, $novemberReading);
        self::assertInstanceOf(ElectricityMeterReading::class, $decemberReading);
        self::assertSame('2022-12-01', $novemberReading->getTakenOn()->format('Y-m-d'));
        self::assertSame('2023-01-01', $decemberReading->getTakenOn()->format('Y-m-d'));

        $auditLog = $this->entityManager()->getRepository(AuditLog::class)->findOneBy([
            'action' => 'zavety_michurina_statement_import.applied',
            'source' => AuditLogSource::Import,
        ]);
        self::assertInstanceOf(AuditLog::class, $auditLog);
        self::assertSame('applied', $auditLog->getNewValues()['status'] ?? null);

        $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/files/%s', $file->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(sprintf('form[action="/admin/zavety-michurina/statement-imports/files/%s/delete"]', $file->getUuid()));
    }

    public function testApplyFillsMissingSerialNumberOnExistingActiveMeterFromPdf(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $account = (new Account($workspace))->setNumber('9-123');
        $activeMeter = new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-03-01'));
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Fill serial test', $admin);
        $file = $this->createParsedImportFile($batch, $admin, 'statement-9-123.pdf', '1', '9-123', 'ЩЕГЛОВ Андрей Владимирович', '47371730');

        $this->entityManager()->persist($account);
        $this->entityManager()->persist($activeMeter);
        $this->entityManager()->persist($batch);
        $this->entityManager()->persist($file);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/files/%s', $file->getUuid()));
        $this->assertResponseIsSuccessful();
        $token = $crawler
            ->filter(sprintf('form[action="/admin/zavety-michurina/statement-imports/files/%s/apply"] input[name="_token"]', $file->getUuid()))
            ->attr('value');

        $client->request('POST', sprintf('/admin/zavety-michurina/statement-imports/files/%s/apply', $file->getUuid()), [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/zavety-michurina/statement-imports/files/%s', $file->getUuid()));
        $this->entityManager()->clear();

        $meters = $this->entityManager()->getRepository(ElectricityMeter::class)->findAll();
        self::assertCount(1, $meters);
        self::assertSame('47371730', $meters[0]->getSerialNumber());

        $subscriber = $this->entityManager()->getRepository(Subscriber::class)->findOneBy(['lastName' => 'Щеглов']);
        self::assertInstanceOf(Subscriber::class, $subscriber);
        self::assertSame('Андрей', $subscriber->getFirstName());
        self::assertSame('Владимирович', $subscriber->getSecondName());

        $payment = $this->entityManager()->getRepository(Payment::class)->findOneBy(['payerName' => 'Щеглов Андрей Владимирович']);
        self::assertInstanceOf(Payment::class, $payment);

        $auditLog = $this->entityManager()->getRepository(AuditLog::class)->findOneBy([
            'action' => 'electricity_meter.updated',
            'source' => AuditLogSource::Import,
        ]);
        self::assertInstanceOf(AuditLog::class, $auditLog);
        self::assertNull($auditLog->getOldValues()['serial_number'] ?? null);
        self::assertSame('47371730', $auditLog->getNewValues()['serial_number'] ?? null);
    }

    public function testAdminCanApplyParsedImportFilesInBatch(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Batch apply test', $admin);

        $fileOne = $this->createParsedImportFile($batch, $admin, 'statement-9-123.pdf', 'd', '9-123', 'Иванов Иван Иванович', '47371730');
        $fileTwo = $this->createParsedImportFile($batch, $admin, 'statement-9-124.pdf', 'e', '9-124', 'Петров Петр Петрович', '47371731');

        $this->entityManager()->persist($batch);
        $this->entityManager()->persist($fileOne);
        $this->entityManager()->persist($fileTwo);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Готово к применению');
        $this->assertSelectorTextContains('body', 'Требует исправления');
        $this->assertSelectorTextContains('body', 'Уже применено');
        $this->assertSelectorTextContains('body', 'Применить готовые');
        $this->assertSelectorTextContains('body', 'Показать готовые файлы');
        $this->assertSelectorTextContains('body', 'Применятся: 2');
        $this->assertSelectorTextContains('body', 'заблокированы предпросмотром: 0');
        self::assertStringContainsString(
            'Применить распознанные файлы этой пачки: 2 шт.',
            (string) $crawler->filter(sprintf('form[action="/admin/zavety-michurina/statement-imports/%s/apply"]', $batch->getUuid()))->attr('onsubmit'),
        );
        $token = $crawler
            ->filter(sprintf('form[action="/admin/zavety-michurina/statement-imports/%s/apply"] input[name="_token"]', $batch->getUuid()))
            ->attr('value');

        $client->request('POST', sprintf('/admin/zavety-michurina/statement-imports/%s/apply', $batch->getUuid()), [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));
        $this->entityManager()->clear();

        $appliedFileOne = $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->find($fileOne->getUuid());
        $appliedFileTwo = $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->find($fileTwo->getUuid());

        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $appliedFileOne);
        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $appliedFileTwo);
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Applied, $appliedFileOne->getStatus());
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Applied, $appliedFileTwo->getStatus());
        self::assertSame(2, $this->entityManager()->getRepository(Account::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(Subscriber::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(SubscriberAccountAccess::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(AccountElectricityTariffProfileAssignment::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityTariffProfile::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityTariffZone::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityTariffPeriod::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityTariffRate::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityConsumptionBandRule::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleAllScope::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleRange::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityMeter::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityMeterReading::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(Payment::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(Accrual::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(PaymentRequisiteProfile::class)->count([]));

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Применена: 2');
        $this->assertSelectorTextContains('body', 'Создано:');
        $this->assertSelectorTextContains('body', 'переисп.:');
    }

    public function testBatchApplyReadinessShowsBlockersAndPreventsMassApply(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Batch readiness test', $admin);

        $readyFile = $this->createParsedImportFile($batch, $admin, 'statement-ready.pdf', '2', '9-123', 'Иванов Иван Иванович', '47371730');
        $this->entityManager()->persist($batch);
        $this->entityManager()->persist($readyFile);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));
        $this->assertResponseIsSuccessful();
        $csrfToken = $crawler
            ->filter(sprintf('form[action="/admin/zavety-michurina/statement-imports/%s/apply"] input[name="_token"]', $batch->getUuid()))
            ->attr('value');

        $blockedFile = new ZavetyMichurinaStatementImportFile(
            batch: $batch,
            originalFilename: 'statement-blocked.pdf',
            sourceSha256: str_repeat('3', 64),
            fileSizeBytes: 123456,
            createdBy: $admin,
        );
        $blockedFile->markParsed([
            'source' => [
                'format' => 'zavety_michurina_electricity_statement',
                'mode' => 'dry_run',
            ],
            'account' => [
                'number' => null,
            ],
            'subscriber' => [
                'full_name' => 'Петров Петр Петрович',
            ],
            'electricity_meter' => [
                'installed_on' => '2026-03-01',
                'serial_number' => '47371731',
                'initial_reading_kwh' => '0',
            ],
            'rows' => [
                [
                    'year' => 2026,
                    'month' => 3,
                    'period_start' => '2026-03-01',
                    'reading_value_kwh' => '1000',
                    'consumption_kwh' => '1000',
                    'social_norm_kwh' => '400',
                    'social_norm_rate' => '5.56',
                    'above_norm_kwh' => '600',
                    'above_norm_rate' => '8.99',
                    'accrued_amount' => '7618.00',
                ],
            ],
            'totals' => [
                'total_accrued' => '7618.00',
                'total_paid' => '0',
                'balance' => '7618.00',
            ],
            'payment_requisites' => [],
            'warnings' => [],
        ], $admin);

        $this->entityManager()->persist($blockedFile);
        $this->entityManager()->flush();

        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Готово к применению');
        $this->assertSelectorTextContains('body', 'Требует исправления');
        $this->assertSelectorTextContains('body', 'Уже применено');
        $this->assertSelectorTextContains('body', 'Применятся: 1');
        $this->assertSelectorTextContains('body', 'заблокированы предпросмотром: 1');
        $this->assertSelectorTextContains('body', 'Показать готовые файлы');
        $this->assertSelectorTextContains('body', 'Блокеры предпросмотра: 1');
        $this->assertSelectorTextContains('body', 'statement-blocked.pdf');
        $this->assertSelectorTextContains('body', 'Участок: В PDF не найден номер участка.');
        $this->assertSelectorExists('select[name="status"]');
        $this->assertSelectorExists('select[name="readiness"]');
        $this->assertSelectorExists('select[name="readiness"] option[value="ready"]');
        $this->assertSelectorTextContains('table tbody', 'Блокер предпросмотра');
        $this->assertSelectorExists('button[disabled]');
        $this->assertSelectorNotExists(sprintf('form[action="/admin/zavety-michurina/statement-imports/%s/apply"] input[name="_token"]', $batch->getUuid()));

        $tableText = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('statement-ready.pdf', $tableText);
        self::assertStringContainsString('statement-blocked.pdf', $tableText);

        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s?readiness=blocked', $batch->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="readiness"] option[value="blocked"][selected]');
        $tableText = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('statement-blocked.pdf', $tableText);
        self::assertStringNotContainsString('statement-ready.pdf', $tableText);
        self::assertStringContainsString('Блокер предпросмотра', $tableText);

        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s?readiness=ready', $batch->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="readiness"] option[value="ready"][selected]');
        $tableText = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('statement-ready.pdf', $tableText);
        self::assertStringNotContainsString('statement-blocked.pdf', $tableText);
        self::assertStringContainsString('Готов к применению', $tableText);

        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s?status=parsed', $batch->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="status"] option[value="parsed"][selected]');
        $tableText = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('statement-ready.pdf', $tableText);
        self::assertStringContainsString('statement-blocked.pdf', $tableText);

        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/%s?status=failed&readiness=blocked', $batch->getUuid()));

        $this->assertResponseIsSuccessful();
        $tableText = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('Файлы не найдены по заданным фильтрам.', $tableText);

        $client->request('POST', sprintf('/admin/zavety-michurina/statement-imports/%s/apply', $batch->getUuid()), [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));
        $this->entityManager()->clear();
        self::assertSame(0, $this->entityManager()->getRepository(Account::class)->count([]));

        $readyFileAfterPost = $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->find($readyFile->getUuid());
        $blockedFileAfterPost = $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->find($blockedFile->getUuid());
        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $readyFileAfterPost);
        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $blockedFileAfterPost);
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Parsed, $readyFileAfterPost->getStatus());
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Parsed, $blockedFileAfterPost->getStatus());
    }

    public function testAdminCanDeleteNotAppliedImportFile(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Delete test', $admin);
        $file = $this->createParsedImportFile($batch, $admin, 'statement-9-123.pdf', 'f', '9-123', 'Иванов Иван Иванович', '47371730');

        $this->entityManager()->persist($batch);
        $this->entityManager()->persist($file);
        $this->entityManager()->flush();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/zavety-michurina/statement-imports/files/%s', $file->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Удалить файл');
        $token = $crawler
            ->filter(sprintf('form[action="/admin/zavety-michurina/statement-imports/files/%s/delete"] input[name="_token"]', $file->getUuid()))
            ->attr('value');

        $client->request('POST', sprintf('/admin/zavety-michurina/statement-imports/files/%s/delete', $file->getUuid()), [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/zavety-michurina/statement-imports/%s', $batch->getUuid()));
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->find($file->getUuid()));

        $auditLog = $this->entityManager()->getRepository(AuditLog::class)->findOneBy([
            'action' => 'zavety_michurina_statement_import_file.deleted',
        ]);
        self::assertInstanceOf(AuditLog::class, $auditLog);
        self::assertSame('statement-9-123.pdf', $auditLog->getOldValues()['original_filename'] ?? null);
    }

    private function createParsedImportFile(
        ZavetyMichurinaStatementImportBatch $batch,
        User $admin,
        string $filename,
        string $hashCharacter,
        string $accountNumber,
        string $fullName,
        string $serialNumber,
    ): ZavetyMichurinaStatementImportFile {
        $file = new ZavetyMichurinaStatementImportFile(
            batch: $batch,
            originalFilename: $filename,
            sourceSha256: str_repeat($hashCharacter, 64),
            fileSizeBytes: 123456,
            createdBy: $admin,
        );
        $file->markParsed([
            'source' => [
                'format' => 'zavety_michurina_electricity_statement',
                'mode' => 'dry_run',
            ],
            'account' => [
                'number' => $accountNumber,
            ],
            'subscriber' => [
                'full_name' => $fullName,
            ],
            'electricity_meter' => [
                'installed_on' => '2026-03-01',
                'serial_number' => $serialNumber,
                'initial_reading_kwh' => '0',
            ],
            'rows' => [
                [
                    'year' => 2026,
                    'month' => 3,
                    'period_start' => '2026-03-01',
                    'reading_value_kwh' => '1000',
                    'consumption_kwh' => '1000',
                    'social_norm_kwh' => '400',
                    'social_norm_rate' => '5.56',
                    'above_norm_kwh' => '600',
                    'above_norm_rate' => '8.99',
                    'accrued_amount' => '7618.00',
                    'paid_on' => '2026-03-13',
                    'paid_amount' => '7618.00',
                ],
            ],
            'totals' => [
                'total_accrued' => '7618.00',
                'total_paid' => '7618.00',
                'balance' => '0',
            ],
            'payment_requisites' => [
                'recipient_name' => 'Садоводческое Некоммерческое Товарищество "Заветы Мичурина"',
                'recipient_inn' => '5262083483',
                'recipient_kpp' => '526201001',
                'bank_account' => '40703810842050000900',
                'bank_bik' => '042202603',
                'bank_name' => 'Волго-Вятский банк ПАО Сбербанк',
                'payer_name' => $fullName,
                'payment_purpose' => sprintf('Участок %s взносы свет', $accountNumber),
            ],
            'warnings' => [],
        ], $admin);

        return $file;
    }
}

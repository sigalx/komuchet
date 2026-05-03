<?php

namespace App\Tests;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityTariffProfile;
use App\Entity\Workspace;
use App\Enum\ElectricityConsumptionBandAllocationMethod;
use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminElectricityConsumptionBandRuleControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/electricity-consumption-band-rules');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyRulesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-band-rules');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Правила диапазонов потребления');
        $this->assertSelectorTextContains('td', 'Правила диапазонов потребления пока не созданы.');
    }

    public function testAdminCanSortAndPaginateRulesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $baseDate = new DateTimeImmutable('2026-01-01');

        for ($i = 0; $i < 55; ++$i) {
            $rule = new ElectricityConsumptionBandRule($workspace, $tariffProfile, $baseDate->modify(sprintf('+%d days', $i)), 1);

            $this->entityManager()->persist($rule);
            $this->entityManager()->persist(new ElectricityConsumptionBandRuleAllScope($workspace, $rule, ElectricityConsumptionBandRuleScopeMode::Include));
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-band-rules?sort=valid_from&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $latestPosition = strpos($content, '24.02.2026');
        $previousPosition = strpos($content, '23.02.2026');
        self::assertNotFalse($latestPosition);
        self::assertNotFalse($previousPosition);
        self::assertLessThan($previousPosition, $latestPosition);
        $this->assertSelectorExists('a[href="/admin/electricity-consumption-band-rules?sort=valid_from&dir=asc&page=1"]');

        $client->request('GET', '/admin/electricity-consumption-band-rules?sort=valid_from&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '05.01.2026');
        $this->assertSelectorTextNotContains('body', '24.02.2026');
    }

    public function testAdminCanCreateRuleWithAllScope(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-band-rules/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="electricity_consumption_band_rule[tariffProfile]"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="electricity_consumption_band_rule[validFrom]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'electricity_consumption_band_rule[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule[month]' => '1',
            'electricity_consumption_band_rule[validFrom]' => '01.01.2026',
            'electricity_consumption_band_rule[validTo]' => '01.02.2026',
            'electricity_consumption_band_rule[allocationMethod]' => ElectricityConsumptionBandAllocationMethod::TotalProportional->value,
            'electricity_consumption_band_rule[priority]' => '100',
            'electricity_consumption_band_rule[sourceDocument]' => 'Протокол от 01.01.2026',
            'electricity_consumption_band_rule[notes]' => 'Зимняя норма',
        ]);

        $rule = $this->findRuleByProfileAndMonth($tariffProfile, 1);

        self::assertInstanceOf(ElectricityConsumptionBandRule::class, $rule);
        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $rule->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($tariffProfile->getUuid()->toRfc4122(), $rule->getTariffProfile()?->getUuid()->toRfc4122());
        self::assertSame('2026-01-01', $rule->getValidFrom()->format('Y-m-d'));
        self::assertSame('2026-02-01', $rule->getValidTo()?->format('Y-m-d'));
        self::assertSame(1, $rule->getMonth());
        self::assertSame(ElectricityConsumptionBandAllocationMethod::TotalProportional, $rule->getAllocationMethod());
        self::assertSame(100, $rule->getPriority());
        self::assertSame('Протокол от 01.01.2026', $rule->getSourceDocument());
        self::assertSame('Зимняя норма', $rule->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $rule->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($rule->getDeletedAt());

        $scope = $this->findAllScope($rule);

        self::assertInstanceOf(ElectricityConsumptionBandRuleAllScope::class, $scope);
        self::assertSame(ElectricityConsumptionBandRuleScopeMode::Include, $scope->getMode());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Правило диапазонов потребления');
        $this->assertSelectorTextContains('body', 'Все участки');
        $this->assertSelectorTextContains('body', 'Диапазоны для правила пока не заданы.');
    }

    public function testAdminCannotCreateOverlappingRuleWithSamePriority(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createRule($workspace, $tariffProfile, 1, '2026-01-01', '2026-02-01', 100);
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-band-rules/new');
        $client->submitForm('Сохранить', [
            'electricity_consumption_band_rule[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule[month]' => '1',
            'electricity_consumption_band_rule[validFrom]' => '15.01.2026',
            'electricity_consumption_band_rule[validTo]' => '01.03.2026',
            'electricity_consumption_band_rule[allocationMethod]' => ElectricityConsumptionBandAllocationMethod::TotalProportional->value,
            'electricity_consumption_band_rule[priority]' => '100',
            'electricity_consumption_band_rule[sourceDocument]' => '',
            'electricity_consumption_band_rule[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Для этого профиля и месяца уже есть пересекающееся правило с таким приоритетом.');
        self::assertSame(1, $this->countRules($tariffProfile));
    }

    public function testAdminCanEditRule(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $rule = $this->createRule($workspace, $tariffProfile, 1, '2026-01-01', '2026-02-01', 100, 'До исправления');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-consumption-band-rules/%s/edit', $rule->getUuid()));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_consumption_band_rule[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule[month]' => '2',
            'electricity_consumption_band_rule[validFrom]' => '01.02.2026',
            'electricity_consumption_band_rule[validTo]' => '',
            'electricity_consumption_band_rule[allocationMethod]' => ElectricityConsumptionBandAllocationMethod::PerTariffZone->value,
            'electricity_consumption_band_rule[priority]' => '50',
            'electricity_consumption_band_rule[sourceDocument]' => 'Решение от 01.02.2026',
            'electricity_consumption_band_rule[notes]' => 'После исправления',
        ]);

        $updatedRule = $this->findRuleByUuid($rule->getUuid());

        self::assertInstanceOf(ElectricityConsumptionBandRule::class, $updatedRule);
        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-band-rules/%s', $updatedRule->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(2, $updatedRule->getMonth());
        self::assertSame('2026-02-01', $updatedRule->getValidFrom()->format('Y-m-d'));
        self::assertNull($updatedRule->getValidTo());
        self::assertSame(ElectricityConsumptionBandAllocationMethod::PerTariffZone, $updatedRule->getAllocationMethod());
        self::assertSame(50, $updatedRule->getPriority());
        self::assertSame('Решение от 01.02.2026', $updatedRule->getSourceDocument());
        self::assertSame('После исправления', $updatedRule->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedRule->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanSoftDeleteRule(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $rule = $this->createRule($workspace, $tariffProfile, 1, '2026-01-01');
        $ruleUuid = $rule->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-consumption-band-rules/%s/edit', $ruleUuid));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects('/admin/electricity-consumption-band-rules', Response::HTTP_SEE_OTHER);

        $deletedRule = $this->findRuleByUuid($ruleUuid);

        self::assertInstanceOf(ElectricityConsumptionBandRule::class, $deletedRule);
        self::assertNotNull($deletedRule->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedRule->getDeletedBy()?->getUuid()->toRfc4122());

        $client->request('GET', '/admin/electricity-consumption-band-rules');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Правила диапазонов потребления пока не созданы.');
    }

    public function testAdminCanAddUpdateAndDeleteRuleRange(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $rule = $this->createRule($workspace, $tariffProfile, 1, '2026-01-01');
        $band = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма', 10);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="electricity_consumption_band_rule_range[consumptionBand]"]');

        $client->submitForm('OK', [
            'electricity_consumption_band_rule_range[consumptionBand]' => $band->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule_range[lowerBoundKwh]' => '0',
            'electricity_consumption_band_rule_range[upperBoundKwh]' => '150,000',
        ]);

        $range = $this->findRange($workspace, $rule, $band);

        self::assertInstanceOf(ElectricityConsumptionBandRuleRange::class, $range);
        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('0', $range->getLowerBoundKwh());
        self::assertSame('150.000', $range->getUpperBoundKwh());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'social_norm');

        $client->submitForm('OK', [
            'electricity_consumption_band_rule_range[consumptionBand]' => $band->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule_range[lowerBoundKwh]' => '0',
            'electricity_consumption_band_rule_range[upperBoundKwh]' => '200',
        ]);

        $updatedRange = $this->findRange($workspace, $rule, $band);

        self::assertInstanceOf(ElectricityConsumptionBandRuleRange::class, $updatedRange);
        self::assertSame(1, $this->countRanges($rule));
        self::assertSame('200', $updatedRange->getUpperBoundKwh());

        $client->followRedirect();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(0, $this->countRanges($rule));
    }

    public function testAdminCannotAddOverlappingRuleRange(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $rule = $this->createRule($workspace, $tariffProfile, 1, '2026-01-01');
        $socialBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма', 10);
        $aboveBand = $this->createConsumptionBand($workspace, 'above_social_norm', 'Сверх социальной нормы', 20);
        $this->createRange($workspace, $rule, $socialBand, '0', '150');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()));
        $client->submitForm('OK', [
            'electricity_consumption_band_rule_range[consumptionBand]' => $aboveBand->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule_range[lowerBoundKwh]' => '100',
            'electricity_consumption_band_rule_range[upperBoundKwh]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Диапазон пересекается с уже заданным диапазоном этого правила.');
        self::assertSame(1, $this->countRanges($rule));
    }

    private function createTariffProfile(Workspace $workspace, string $code, string $name): ElectricityTariffProfile
    {
        $tariffProfile = (new ElectricityTariffProfile($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($tariffProfile);
        $this->entityManager()->flush();

        return $tariffProfile;
    }

    private function createConsumptionBand(Workspace $workspace, string $code, string $name, int $sortOrder): ElectricityConsumptionBand
    {
        $band = (new ElectricityConsumptionBand($workspace))
            ->setCode($code)
            ->setName($name)
            ->setSortOrder($sortOrder);

        $this->entityManager()->persist($band);
        $this->entityManager()->flush();

        return $band;
    }

    private function createRule(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        int $month,
        string $validFrom,
        ?string $validTo = null,
        int $priority = 100,
        ?string $sourceDocument = null,
    ): ElectricityConsumptionBandRule {
        $rule = (new ElectricityConsumptionBandRule($workspace, $tariffProfile, new DateTimeImmutable($validFrom), $month))
            ->setPriority($priority)
            ->setSourceDocument($sourceDocument);

        if ($validTo !== null) {
            $rule->setValidTo(new DateTimeImmutable($validTo));
        }

        $this->entityManager()->persist($rule);
        $this->entityManager()->persist(new ElectricityConsumptionBandRuleAllScope($workspace, $rule, ElectricityConsumptionBandRuleScopeMode::Include));
        $this->entityManager()->flush();

        return $rule;
    }

    private function createRange(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBand $band,
        string $lowerBoundKwh,
        ?string $upperBoundKwh,
    ): ElectricityConsumptionBandRuleRange {
        $range = new ElectricityConsumptionBandRuleRange($workspace, $rule, $band, $lowerBoundKwh, $upperBoundKwh);

        $this->entityManager()->persist($range);
        $this->entityManager()->flush();

        return $range;
    }

    private function findRuleByUuid(Uuid $uuid): ?ElectricityConsumptionBandRule
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRule::class)
            ->find($uuid);
    }

    private function findRuleByProfileAndMonth(ElectricityTariffProfile $tariffProfile, int $month): ?ElectricityConsumptionBandRule
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRule::class)
            ->findOneBy([
                'tariffProfile' => $tariffProfile,
                'month' => $month,
            ]);
    }

    private function countRules(ElectricityTariffProfile $tariffProfile): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRule::class)
            ->createQueryBuilder('rule')
            ->select('COUNT(rule.uuid)')
            ->andWhere('rule.tariffProfile = :tariffProfile')
            ->setParameter('tariffProfile', $tariffProfile)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findAllScope(ElectricityConsumptionBandRule $rule): ?ElectricityConsumptionBandRuleAllScope
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRuleAllScope::class)
            ->findOneBy(['rule' => $rule]);
    }

    private function findRange(Workspace $workspace, ElectricityConsumptionBandRule $rule, ElectricityConsumptionBand $band): ?ElectricityConsumptionBandRuleRange
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRuleRange::class)
            ->findOneBy([
                'workspace' => $workspace,
                'rule' => $rule,
                'consumptionBand' => $band,
            ]);
    }

    private function countRanges(ElectricityConsumptionBandRule $rule): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRuleRange::class)
            ->createQueryBuilder('range')
            ->select('COUNT(range.lowerBoundKwh)')
            ->andWhere('range.rule = :rule')
            ->setParameter('rule', $rule)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

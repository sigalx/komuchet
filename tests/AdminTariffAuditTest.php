<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use App\Enum\ElectricityConsumptionBandAllocationMethod;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminTariffAuditTest extends FunctionalWebTestCase
{
    public function testTariffDirectoryActionsWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-zones/new');
        $client->submitForm('Сохранить', [
            'electricity_tariff_zone[code]' => 'single',
            'electricity_tariff_zone[name]' => 'Однотарифная зона',
            'electricity_tariff_zone[description]' => 'Общий регистр',
            'electricity_tariff_zone[sortOrder]' => '10',
        ]);
        $tariffZone = $this->findTariffZoneByCode('single');

        self::assertInstanceOf(ElectricityTariffZone::class, $tariffZone);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-zones/%s', $tariffZone->getUuid()), Response::HTTP_SEE_OTHER);
        $zoneCreatedLog = $this->findAuditLog('electricity_tariff_zone.created', $tariffZone->getUuid());

        self::assertInstanceOf(AuditLog::class, $zoneCreatedLog);
        self::assertSame('electricity_tariff_zones', $zoneCreatedLog->getEntityTable());
        self::assertSame('single', $zoneCreatedLog->getNewValues()['code'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-tariff-zones/%s/edit', $tariffZone->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_tariff_zone[code]' => 'day',
            'electricity_tariff_zone[name]' => 'Дневная зона',
            'electricity_tariff_zone[description]' => 'После исправления',
            'electricity_tariff_zone[sortOrder]' => '20',
        ]);
        $zoneUpdatedLog = $this->findAuditLog('electricity_tariff_zone.updated', $tariffZone->getUuid());

        self::assertInstanceOf(AuditLog::class, $zoneUpdatedLog);
        self::assertSame('single', $zoneUpdatedLog->getOldValues()['code'] ?? null);
        self::assertSame('day', $zoneUpdatedLog->getNewValues()['code'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-tariff-zones/%s/edit', $tariffZone->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_tariff_zone.deleted', $tariffZone->getUuid()));

        $client->request('GET', '/admin/electricity-tariff-profiles/new');
        $client->submitForm('Сохранить', [
            'electricity_tariff_profile[code]' => 'snt',
            'electricity_tariff_profile[name]' => 'СНТ',
            'electricity_tariff_profile[description]' => 'Основной профиль',
        ]);
        $tariffProfile = $this->findTariffProfileByCode('snt');

        self::assertInstanceOf(ElectricityTariffProfile::class, $tariffProfile);
        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_tariff_profile.created', $tariffProfile->getUuid()));

        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s/edit', $tariffProfile->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_tariff_profile[code]' => 'heating',
            'electricity_tariff_profile[name]' => 'Электроотопление',
            'electricity_tariff_profile[description]' => 'После исправления',
        ]);
        $profileUpdatedLog = $this->findAuditLog('electricity_tariff_profile.updated', $tariffProfile->getUuid());

        self::assertInstanceOf(AuditLog::class, $profileUpdatedLog);
        self::assertSame('snt', $profileUpdatedLog->getOldValues()['code'] ?? null);
        self::assertSame('heating', $profileUpdatedLog->getNewValues()['code'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s/edit', $tariffProfile->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_tariff_profile.deleted', $tariffProfile->getUuid()));

        $client->request('GET', '/admin/electricity-consumption-bands/new');
        $client->submitForm('Сохранить', [
            'electricity_consumption_band[code]' => 'social_norm',
            'electricity_consumption_band[name]' => 'Социальная норма',
            'electricity_consumption_band[description]' => 'Льготный диапазон',
            'electricity_consumption_band[sortOrder]' => '10',
        ]);
        $consumptionBand = $this->findConsumptionBandByCode('social_norm');

        self::assertInstanceOf(ElectricityConsumptionBand::class, $consumptionBand);
        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_consumption_band.created', $consumptionBand->getUuid()));

        $client->request('GET', sprintf('/admin/electricity-consumption-bands/%s/edit', $consumptionBand->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_consumption_band[code]' => 'above_social_norm',
            'electricity_consumption_band[name]' => 'Сверх социальной нормы',
            'electricity_consumption_band[description]' => 'После исправления',
            'electricity_consumption_band[sortOrder]' => '20',
        ]);
        $bandUpdatedLog = $this->findAuditLog('electricity_consumption_band.updated', $consumptionBand->getUuid());

        self::assertInstanceOf(AuditLog::class, $bandUpdatedLog);
        self::assertSame('social_norm', $bandUpdatedLog->getOldValues()['code'] ?? null);
        self::assertSame('above_social_norm', $bandUpdatedLog->getNewValues()['code'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-consumption-bands/%s/edit', $consumptionBand->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_consumption_band.deleted', $consumptionBand->getUuid()));

        self::assertSame($workspace->getUuid()->toRfc4122(), $zoneCreatedLog->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($admin->getUuid()->toRfc4122(), $zoneCreatedLog->getActorUser()?->getUuid()->toRfc4122());
    }

    public function testTariffPeriodRatesAndConsumptionRulesWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $socialBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма', 10);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s/periods/new', $tariffProfile->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_tariff_period[validFrom]' => '01.05.2026',
            'electricity_tariff_period[validTo]' => '01.06.2026',
            'electricity_tariff_period[sourceDocument]' => 'Протокол от 30.04.2026',
            'electricity_tariff_period[notes]' => 'Весенний тариф',
        ]);
        $tariffPeriod = $this->findTariffPeriodByValidFrom($tariffProfile, '2026-05-01');

        self::assertInstanceOf(ElectricityTariffPeriod::class, $tariffPeriod);
        $periodCreatedLog = $this->findAuditLog('electricity_tariff_period.created', $tariffPeriod->getUuid());

        self::assertInstanceOf(AuditLog::class, $periodCreatedLog);
        self::assertSame($tariffProfile->getUuid()->toRfc4122(), $periodCreatedLog->getNewValues()['tariff_profile_uuid'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-tariff-periods/%s/edit', $tariffPeriod->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_tariff_period[validFrom]' => '01.05.2026',
            'electricity_tariff_period[validTo]' => '',
            'electricity_tariff_period[sourceDocument]' => 'Решение от 01.05.2026',
            'electricity_tariff_period[notes]' => 'После исправления',
        ]);
        $periodUpdatedLog = $this->findAuditLog('electricity_tariff_period.updated', $tariffPeriod->getUuid());

        self::assertInstanceOf(AuditLog::class, $periodUpdatedLog);
        self::assertSame('Протокол от 30.04.2026', $periodUpdatedLog->getOldValues()['source_document'] ?? null);
        self::assertSame('Решение от 01.05.2026', $periodUpdatedLog->getNewValues()['source_document'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-tariff-periods/%s', $tariffPeriod->getUuid()));
        $client->submitForm('OK', [
            'electricity_tariff_rate[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_tariff_rate[consumptionBand]' => $socialBand->getUuid()->toRfc4122(),
            'electricity_tariff_rate[rate]' => '5,123456',
        ]);
        $tariffRate = $this->findTariffRate($workspace, $tariffPeriod, $tariffZone, $socialBand);
        $rateCreatedLog = $this->findAuditLog('electricity_tariff_rate.created');

        self::assertInstanceOf(ElectricityTariffRate::class, $tariffRate);
        self::assertInstanceOf(AuditLog::class, $rateCreatedLog);
        self::assertSame($tariffPeriod->getUuid()->toRfc4122(), $rateCreatedLog->getEntityPk()['tariff_period_uuid'] ?? null);
        self::assertSame('5.123456', $rateCreatedLog->getNewValues()['rate'] ?? null);

        $client->followRedirect();
        $client->submitForm('OK', [
            'electricity_tariff_rate[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_tariff_rate[consumptionBand]' => $socialBand->getUuid()->toRfc4122(),
            'electricity_tariff_rate[rate]' => '6.25',
        ]);
        $rateUpdatedLog = $this->findAuditLog('electricity_tariff_rate.updated');

        self::assertInstanceOf(AuditLog::class, $rateUpdatedLog);
        self::assertSame('5.123456', $rateUpdatedLog->getOldValues()['rate'] ?? null);
        self::assertSame('6.25', $rateUpdatedLog->getNewValues()['rate'] ?? null);

        $client->request('GET', '/admin/electricity-consumption-band-rules/new');
        $client->submitForm('Сохранить', [
            'electricity_consumption_band_rule[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule[month]' => '5',
            'electricity_consumption_band_rule[validFrom]' => '01.05.2026',
            'electricity_consumption_band_rule[validTo]' => '01.06.2026',
            'electricity_consumption_band_rule[allocationMethod]' => ElectricityConsumptionBandAllocationMethod::TotalProportional->value,
            'electricity_consumption_band_rule[priority]' => '100',
            'electricity_consumption_band_rule[sourceDocument]' => 'Протокол нормы',
            'electricity_consumption_band_rule[notes]' => 'Майская норма',
        ]);
        $rule = $this->findRuleByProfileAndMonth($tariffProfile, 5);

        self::assertInstanceOf(ElectricityConsumptionBandRule::class, $rule);
        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_consumption_band_rule.created', $rule->getUuid()));

        $client->request('GET', sprintf('/admin/electricity-consumption-band-rules/%s/edit', $rule->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_consumption_band_rule[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule[month]' => '6',
            'electricity_consumption_band_rule[validFrom]' => '01.06.2026',
            'electricity_consumption_band_rule[validTo]' => '',
            'electricity_consumption_band_rule[allocationMethod]' => ElectricityConsumptionBandAllocationMethod::PerTariffZone->value,
            'electricity_consumption_band_rule[priority]' => '50',
            'electricity_consumption_band_rule[sourceDocument]' => 'Решение нормы',
            'electricity_consumption_band_rule[notes]' => 'После исправления',
        ]);
        $ruleUpdatedLog = $this->findAuditLog('electricity_consumption_band_rule.updated', $rule->getUuid());

        self::assertInstanceOf(AuditLog::class, $ruleUpdatedLog);
        self::assertSame(5, $ruleUpdatedLog->getOldValues()['month'] ?? null);
        self::assertSame(6, $ruleUpdatedLog->getNewValues()['month'] ?? null);

        $client->request('GET', sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()));
        $client->submitForm('OK', [
            'electricity_consumption_band_rule_range[consumptionBand]' => $socialBand->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule_range[lowerBoundKwh]' => '0',
            'electricity_consumption_band_rule_range[upperBoundKwh]' => '150,000',
        ]);
        $range = $this->findRange($workspace, $rule, $socialBand);
        $rangeCreatedLog = $this->findAuditLog('electricity_consumption_band_rule_range.created');

        self::assertInstanceOf(ElectricityConsumptionBandRuleRange::class, $range);
        self::assertInstanceOf(AuditLog::class, $rangeCreatedLog);
        self::assertSame($rule->getUuid()->toRfc4122(), $rangeCreatedLog->getEntityPk()['rule_uuid'] ?? null);
        self::assertSame('150.000', $rangeCreatedLog->getNewValues()['upper_bound_kwh'] ?? null);

        $client->followRedirect();
        $client->submitForm('OK', [
            'electricity_consumption_band_rule_range[consumptionBand]' => $socialBand->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule_range[lowerBoundKwh]' => '0',
            'electricity_consumption_band_rule_range[upperBoundKwh]' => '200',
        ]);
        $rangeUpdatedLog = $this->findAuditLog('electricity_consumption_band_rule_range.updated');

        self::assertInstanceOf(AuditLog::class, $rangeUpdatedLog);
        self::assertSame('150.000', $rangeUpdatedLog->getOldValues()['upper_bound_kwh'] ?? null);
        self::assertSame('200', $rangeUpdatedLog->getNewValues()['upper_bound_kwh'] ?? null);

        $client->followRedirect();
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_consumption_band_rule_range.deleted'));

        $client->request('GET', sprintf('/admin/electricity-consumption-band-rules/%s/edit', $rule->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_consumption_band_rule.deleted', $rule->getUuid()));

        $client->request('GET', sprintf('/admin/electricity-tariff-periods/%s/edit', $tariffPeriod->getUuid()));
        $client->submitForm('Удалить');

        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('electricity_tariff_period.deleted', $tariffPeriod->getUuid()));
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

    private function createTariffZone(Workspace $workspace, string $code, string $name): ElectricityTariffZone
    {
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->flush();

        return $tariffZone;
    }

    private function createConsumptionBand(Workspace $workspace, string $code, string $name, int $sortOrder): ElectricityConsumptionBand
    {
        $consumptionBand = (new ElectricityConsumptionBand($workspace))
            ->setCode($code)
            ->setName($name)
            ->setSortOrder($sortOrder);

        $this->entityManager()->persist($consumptionBand);
        $this->entityManager()->flush();

        return $consumptionBand;
    }

    private function findTariffZoneByCode(string $code): ?ElectricityTariffZone
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffZone::class)
            ->findOneBy(['code' => $code]);
    }

    private function findTariffProfileByCode(string $code): ?ElectricityTariffProfile
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffProfile::class)
            ->findOneBy(['code' => $code]);
    }

    private function findConsumptionBandByCode(string $code): ?ElectricityConsumptionBand
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBand::class)
            ->findOneBy(['code' => $code]);
    }

    private function findTariffPeriodByValidFrom(ElectricityTariffProfile $tariffProfile, string $validFrom): ?ElectricityTariffPeriod
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffPeriod::class)
            ->findOneBy([
                'tariffProfile' => $tariffProfile,
                'validFrom' => new DateTimeImmutable($validFrom),
            ]);
    }

    private function findTariffRate(
        Workspace $workspace,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $consumptionBand,
    ): ?ElectricityTariffRate {
        return $this->entityManager()
            ->getRepository(ElectricityTariffRate::class)
            ->findOneBy([
                'workspace' => $workspace,
                'tariffPeriod' => $tariffPeriod,
                'tariffZone' => $tariffZone,
                'consumptionBand' => $consumptionBand,
            ]);
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

    private function findAuditLog(string $action, ?Uuid $entityUuid = null): ?AuditLog
    {
        $queryBuilder = $this->entityManager()
            ->getRepository(AuditLog::class)
            ->createQueryBuilder('auditLog')
            ->andWhere('auditLog.action = :action')
            ->setParameter('action', $action)
            ->orderBy('auditLog.occurredAt', 'DESC')
            ->addOrderBy('auditLog.uuid', 'DESC')
            ->setMaxResults(1);

        if ($entityUuid !== null) {
            $queryBuilder
                ->andWhere('auditLog.entityUuid = :entityUuid')
                ->setParameter('entityUuid', $entityUuid);
        }

        return $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();
    }
}

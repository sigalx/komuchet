<?php

namespace App\Repository;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityTariffRate>
 */
class ElectricityTariffRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityTariffRate::class);
    }

    /**
     * @return list<ElectricityTariffRate>
     */
    public function findByPeriod(Workspace $workspace, ElectricityTariffPeriod $tariffPeriod): array
    {
        return $this->createQueryBuilder('tariffRate')
            ->addSelect('tariffZone', 'consumptionBand')
            ->innerJoin('tariffRate.tariffZone', 'tariffZone')
            ->innerJoin('tariffRate.consumptionBand', 'consumptionBand')
            ->andWhere('tariffRate.workspace = :workspace')
            ->andWhere('tariffRate.tariffPeriod = :tariffPeriod')
            ->setParameter('workspace', $workspace)
            ->setParameter('tariffPeriod', $tariffPeriod)
            ->orderBy('tariffZone.sortOrder', 'ASC')
            ->addOrderBy('tariffZone.name', 'ASC')
            ->addOrderBy('consumptionBand.sortOrder', 'ASC')
            ->addOrderBy('consumptionBand.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByPeriodZoneAndBand(
        Workspace $workspace,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $consumptionBand,
    ): ?ElectricityTariffRate {
        return $this->createQueryBuilder('tariffRate')
            ->andWhere('tariffRate.workspace = :workspace')
            ->andWhere('tariffRate.tariffPeriod = :tariffPeriod')
            ->andWhere('tariffRate.tariffZone = :tariffZone')
            ->andWhere('tariffRate.consumptionBand = :consumptionBand')
            ->setParameter('workspace', $workspace)
            ->setParameter('tariffPeriod', $tariffPeriod)
            ->setParameter('tariffZone', $tariffZone)
            ->setParameter('consumptionBand', $consumptionBand)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

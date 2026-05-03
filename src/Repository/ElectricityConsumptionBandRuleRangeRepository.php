<?php

namespace App\Repository;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityConsumptionBandRuleRange>
 */
class ElectricityConsumptionBandRuleRangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityConsumptionBandRuleRange::class);
    }

    /**
     * @return list<ElectricityConsumptionBandRuleRange>
     */
    public function findByRule(Workspace $workspace, ElectricityConsumptionBandRule $rule): array
    {
        return $this->createQueryBuilder('range')
            ->addSelect('consumptionBand')
            ->innerJoin('range.consumptionBand', 'consumptionBand')
            ->andWhere('range.workspace = :workspace')
            ->andWhere('range.rule = :rule')
            ->setParameter('workspace', $workspace)
            ->setParameter('rule', $rule)
            ->orderBy('range.lowerBoundKwh', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByRuleAndBand(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBand $consumptionBand,
    ): ?ElectricityConsumptionBandRuleRange {
        return $this->createQueryBuilder('range')
            ->andWhere('range.workspace = :workspace')
            ->andWhere('range.rule = :rule')
            ->andWhere('range.consumptionBand = :consumptionBand')
            ->setParameter('workspace', $workspace)
            ->setParameter('rule', $rule)
            ->setParameter('consumptionBand', $consumptionBand)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOverlappingRange(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        string $lowerBoundKwh,
        ?string $upperBoundKwh,
        ?ElectricityConsumptionBand $excludeConsumptionBand = null,
    ): ?ElectricityConsumptionBandRuleRange {
        $queryBuilder = $this->createQueryBuilder('range')
            ->andWhere('range.workspace = :workspace')
            ->andWhere('range.rule = :rule')
            ->andWhere('range.upperBoundKwh IS NULL OR range.upperBoundKwh > :lowerBoundKwh')
            ->setParameter('workspace', $workspace)
            ->setParameter('rule', $rule)
            ->setParameter('lowerBoundKwh', $lowerBoundKwh)
            ->setMaxResults(1);

        if ($upperBoundKwh !== null) {
            $queryBuilder
                ->andWhere('range.lowerBoundKwh < :upperBoundKwh')
                ->setParameter('upperBoundKwh', $upperBoundKwh);
        }

        if ($excludeConsumptionBand !== null) {
            $queryBuilder
                ->andWhere('range.consumptionBand != :excludeConsumptionBand')
                ->setParameter('excludeConsumptionBand', $excludeConsumptionBand);
        }

        return $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();
    }
}

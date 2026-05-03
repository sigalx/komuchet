<?php

namespace App\Repository;

use App\Entity\Accrual;
use App\Entity\ElectricityAccrualContext;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityAccrualContext>
 */
class ElectricityAccrualContextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityAccrualContext::class);
    }

    public function findOneByAccrual(Workspace $workspace, Accrual $accrual): ?ElectricityAccrualContext
    {
        return $this->createQueryBuilder('context')
            ->addSelect('electricityMeter', 'tariffProfile', 'tariffPeriod', 'consumptionBandRule')
            ->innerJoin('context.electricityMeter', 'electricityMeter')
            ->innerJoin('context.tariffProfile', 'tariffProfile')
            ->innerJoin('context.tariffPeriod', 'tariffPeriod')
            ->innerJoin('context.consumptionBandRule', 'consumptionBandRule')
            ->andWhere('context.workspace = :workspace')
            ->andWhere('context.accrual = :accrual')
            ->setParameter('workspace', $workspace)
            ->setParameter('accrual', $accrual)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

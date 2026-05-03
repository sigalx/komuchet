<?php

namespace App\Repository;

use App\Entity\Accrual;
use App\Entity\ElectricityAccrualLine;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityAccrualLine>
 */
class ElectricityAccrualLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityAccrualLine::class);
    }

    /**
     * @return list<ElectricityAccrualLine>
     */
    public function findByAccrual(Workspace $workspace, Accrual $accrual): array
    {
        return $this->createQueryBuilder('line')
            ->addSelect('tariffZone', 'consumptionBand')
            ->innerJoin('line.tariffZone', 'tariffZone')
            ->innerJoin('line.consumptionBand', 'consumptionBand')
            ->andWhere('line.workspace = :workspace')
            ->andWhere('line.accrual = :accrual')
            ->setParameter('workspace', $workspace)
            ->setParameter('accrual', $accrual)
            ->orderBy('tariffZone.sortOrder', 'ASC')
            ->addOrderBy('tariffZone.name', 'ASC')
            ->addOrderBy('consumptionBand.sortOrder', 'ASC')
            ->addOrderBy('consumptionBand.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

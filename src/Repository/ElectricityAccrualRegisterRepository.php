<?php

namespace App\Repository;

use App\Entity\Accrual;
use App\Entity\ElectricityAccrualRegister;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityAccrualRegister>
 */
class ElectricityAccrualRegisterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityAccrualRegister::class);
    }

    /**
     * @return list<ElectricityAccrualRegister>
     */
    public function findByAccrual(Workspace $workspace, Accrual $accrual): array
    {
        return $this->createQueryBuilder('register')
            ->addSelect('electricityMeter', 'tariffZone', 'previousReading', 'currentReading')
            ->innerJoin('register.electricityMeter', 'electricityMeter')
            ->innerJoin('register.tariffZone', 'tariffZone')
            ->leftJoin('register.previousReading', 'previousReading')
            ->innerJoin('register.currentReading', 'currentReading')
            ->andWhere('register.workspace = :workspace')
            ->andWhere('register.accrual = :accrual')
            ->setParameter('workspace', $workspace)
            ->setParameter('accrual', $accrual)
            ->orderBy('tariffZone.sortOrder', 'ASC')
            ->addOrderBy('tariffZone.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

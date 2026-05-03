<?php

namespace App\Repository;

use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityMeterRegister>
 */
class ElectricityMeterRegisterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityMeterRegister::class);
    }

    /**
     * @return list<ElectricityMeterRegister>
     */
    public function findByMeter(Workspace $workspace, ElectricityMeter $electricityMeter): array
    {
        return $this->createQueryBuilder('register')
            ->addSelect('tariffZone')
            ->innerJoin('register.tariffZone', 'tariffZone')
            ->andWhere('register.workspace = :workspace')
            ->andWhere('register.electricityMeter = :electricityMeter')
            ->setParameter('workspace', $workspace)
            ->setParameter('electricityMeter', $electricityMeter)
            ->orderBy('tariffZone.sortOrder', 'ASC')
            ->addOrderBy('tariffZone.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByMeterAndTariffZone(Workspace $workspace, ElectricityMeter $electricityMeter, ElectricityTariffZone $tariffZone): ?ElectricityMeterRegister
    {
        return $this->createQueryBuilder('register')
            ->andWhere('register.workspace = :workspace')
            ->andWhere('register.electricityMeter = :electricityMeter')
            ->andWhere('register.tariffZone = :tariffZone')
            ->setParameter('workspace', $workspace)
            ->setParameter('electricityMeter', $electricityMeter)
            ->setParameter('tariffZone', $tariffZone)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

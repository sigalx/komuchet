<?php

namespace App\Repository;

use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\Workspace;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ElectricityTariffPeriod>
 */
class ElectricityTariffPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityTariffPeriod::class);
    }

    /**
     * @return list<ElectricityTariffPeriod>
     */
    public function findActiveByProfile(Workspace $workspace, ElectricityTariffProfile $tariffProfile): array
    {
        return $this->createQueryBuilder('tariffPeriod')
            ->andWhere('tariffPeriod.workspace = :workspace')
            ->andWhere('tariffPeriod.tariffProfile = :tariffProfile')
            ->andWhere('tariffPeriod.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('tariffProfile', $tariffProfile)
            ->orderBy('tariffPeriod.validFrom', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ElectricityTariffPeriod
    {
        return $this->createQueryBuilder('tariffPeriod')
            ->addSelect('tariffProfile')
            ->innerJoin('tariffPeriod.tariffProfile', 'tariffProfile')
            ->andWhere('tariffPeriod.workspace = :workspace')
            ->andWhere('tariffPeriod.uuid = :uuid')
            ->andWhere('tariffPeriod.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByProfileAt(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        DateTimeImmutable $date,
    ): ?ElectricityTariffPeriod {
        return $this->createQueryBuilder('tariffPeriod')
            ->andWhere('tariffPeriod.workspace = :workspace')
            ->andWhere('tariffPeriod.tariffProfile = :tariffProfile')
            ->andWhere('tariffPeriod.validFrom <= :date')
            ->andWhere('tariffPeriod.validTo IS NULL OR tariffPeriod.validTo > :date')
            ->andWhere('tariffPeriod.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('tariffProfile', $tariffProfile)
            ->setParameter('date', $date)
            ->orderBy('tariffPeriod.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOverlappingActivePeriod(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        ?Uuid $excludeUuid = null,
    ): ?ElectricityTariffPeriod {
        $queryBuilder = $this->createQueryBuilder('tariffPeriod')
            ->andWhere('tariffPeriod.workspace = :workspace')
            ->andWhere('tariffPeriod.tariffProfile = :tariffProfile')
            ->andWhere('tariffPeriod.deletedAt IS NULL')
            ->andWhere('tariffPeriod.validTo IS NULL OR tariffPeriod.validTo > :validFrom')
            ->setParameter('workspace', $workspace)
            ->setParameter('tariffProfile', $tariffProfile)
            ->setParameter('validFrom', $validFrom)
            ->setMaxResults(1);

        if ($validTo !== null) {
            $queryBuilder
                ->andWhere('tariffPeriod.validFrom < :validTo')
                ->setParameter('validTo', $validTo);
        }

        if ($excludeUuid !== null) {
            $queryBuilder
                ->andWhere('tariffPeriod.uuid != :excludeUuid')
                ->setParameter('excludeUuid', $excludeUuid);
        }

        return $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();
    }
}

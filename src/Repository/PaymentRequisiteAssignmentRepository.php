<?php

namespace App\Repository;

use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PaymentRequisiteAssignment>
 */
class PaymentRequisiteAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentRequisiteAssignment::class);
    }

    /**
     * @return list<PaymentRequisiteAssignment>
     */
    public function findOpenByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('assignment')
            ->addSelect('profile')
            ->innerJoin('assignment.paymentRequisiteProfile', 'profile')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.closedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->orderBy('assignment.accrualType', 'ASC')
            ->addOrderBy('assignment.validFrom', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<PaymentRequisiteAssignment>
     */
    public function findOpenByProfile(PaymentRequisiteProfile $profile): array
    {
        return $this->createQueryBuilder('assignment')
            ->andWhere('assignment.paymentRequisiteProfile = :profile')
            ->andWhere('assignment.closedAt IS NULL')
            ->setParameter('profile', $profile)
            ->orderBy('assignment.accrualType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOpenByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?PaymentRequisiteAssignment
    {
        return $this->createQueryBuilder('assignment')
            ->addSelect('profile')
            ->innerJoin('assignment.paymentRequisiteProfile', 'profile')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.uuid = :uuid')
            ->andWhere('assignment.closedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findCurrentForType(Workspace $workspace, ?AccrualType $accrualType, DateTimeImmutable $date): ?PaymentRequisiteAssignment
    {
        if ($accrualType instanceof AccrualType) {
            $typedAssignment = $this->findCurrentByScope($workspace, $accrualType, $date);

            if ($typedAssignment instanceof PaymentRequisiteAssignment) {
                return $typedAssignment;
            }
        }

        return $this->findCurrentByScope($workspace, null, $date);
    }

    public function findCurrentByScope(Workspace $workspace, ?AccrualType $accrualType, DateTimeImmutable $date): ?PaymentRequisiteAssignment
    {
        $queryBuilder = $this->createQueryBuilder('assignment')
            ->addSelect('profile')
            ->innerJoin('assignment.paymentRequisiteProfile', 'profile')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.closedAt IS NULL')
            ->andWhere('assignment.validFrom <= :date')
            ->andWhere('assignment.validTo IS NULL OR assignment.validTo > :date')
            ->andWhere('profile.deletedAt IS NULL')
            ->andWhere('profile.validFrom <= :date')
            ->andWhere('profile.validTo IS NULL OR profile.validTo > :date')
            ->setParameter('workspace', $workspace)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->setMaxResults(1);

        if ($accrualType instanceof AccrualType) {
            $queryBuilder
                ->andWhere('assignment.accrualType = :accrualType')
                ->setParameter('accrualType', $accrualType);
        } else {
            $queryBuilder->andWhere('assignment.accrualType IS NULL');
        }

        return $queryBuilder
            ->orderBy('assignment.validFrom', 'DESC')
            ->getQuery()
            ->getOneOrNullResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityConsumptionBandRuleAllScope>
 */
class ElectricityConsumptionBandRuleAllScopeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityConsumptionBandRuleAllScope::class);
    }

    public function findOneByRule(Workspace $workspace, ElectricityConsumptionBandRule $rule): ?ElectricityConsumptionBandRuleAllScope
    {
        return $this->createQueryBuilder('scope')
            ->andWhere('scope.workspace = :workspace')
            ->andWhere('scope.rule = :rule')
            ->setParameter('workspace', $workspace)
            ->setParameter('rule', $rule)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

<?php

namespace App\Repository;

use App\Entity\ElectricityConsumptionBandRuleGroupScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityConsumptionBandRuleGroupScope>
 */
class ElectricityConsumptionBandRuleGroupScopeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityConsumptionBandRuleGroupScope::class);
    }
}

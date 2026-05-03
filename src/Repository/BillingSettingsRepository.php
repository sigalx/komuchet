<?php

namespace App\Repository;

use App\Entity\BillingSettings;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingSettings>
 */
class BillingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingSettings::class);
    }

    public function findOneByWorkspace(Workspace $workspace): ?BillingSettings
    {
        return $this->find($workspace);
    }
}

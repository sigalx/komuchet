<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AuditLogSource;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    /**
     * @param list<string>|null $changedFields
     */
    public function record(
        string $action,
        ?Workspace $workspace = null,
        ?string $entityTable = null,
        ?Uuid $entityUuid = null,
        ?array $entityPk = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $changedFields = null,
        ?string $reason = null,
        AuditLogSource $source = AuditLogSource::App,
    ): AuditLog {
        $auditLog = (new AuditLog($action, $source))
            ->setWorkspace($workspace)
            ->setActorUser($this->getCurrentUser())
            ->setEntityTable($entityTable)
            ->setEntityUuid($entityUuid)
            ->setEntityPk($entityPk)
            ->setOldValues($oldValues)
            ->setNewValues($newValues)
            ->setChangedFields($changedFields)
            ->setReason($reason);

        $this->enrichFromRequest($auditLog);
        $this->enrichFromDatabase($auditLog);

        $this->entityManager->persist($auditLog);

        return $auditLog;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function enrichFromRequest(AuditLog $auditLog): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return;
        }

        $requestId = $request->headers->get('X-Request-Id')
            ?? $request->headers->get('X-Correlation-Id');

        $auditLog
            ->setRequestId($requestId)
            ->setIpAddress($request->getClientIp())
            ->setUserAgent($request->headers->get('User-Agent'));
    }

    private function enrichFromDatabase(AuditLog $auditLog): void
    {
        try {
            $auditLog->setDbUser((string) $this->connection->fetchOne('SELECT CURRENT_USER'));
        } catch (\Throwable) {
            // Audit logging must not fail the business action only because db_user enrichment failed.
        }
    }
}

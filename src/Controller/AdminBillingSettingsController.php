<?php

namespace App\Controller;

use App\Entity\BillingSettings;
use App\Entity\User;
use App\Entity\Workspace;
use App\Form\BillingSettingsType;
use App\Repository\BillingSettingsRepository;
use App\Service\AuditLogger;
use App\Service\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('WORKSPACE_ACCESS')]
final class AdminBillingSettingsController extends AbstractController
{
    #[Route('/admin/billing-settings', name: 'app_admin_billing_settings', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        BillingSettingsRepository $billingSettingsRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $billingSettings = $billingSettingsRepository->findOneByWorkspace($workspace);
        $isNew = false;

        if (!$billingSettings instanceof BillingSettings) {
            $billingSettings = new BillingSettings($workspace, $workspace->getName(), $this->getCurrentUser());
            $isNew = true;
        }
        $oldValues = $isNew ? null : $this->billingSettingsAuditValues($billingSettings, $workspace);

        $form = $this->createForm(BillingSettingsType::class, $billingSettings, [
            'workspace_timezone' => $workspace->getTimezone(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $workspace->setTimezone((string) $form->get('timezone')->getData());
                $workspace->touch($this->getCurrentUser());
                $billingSettings->touch($this->getCurrentUser());

                if ($isNew) {
                    $entityManager->persist($billingSettings);
                }

                $auditLogger->record(
                    action: $isNew ? 'billing_settings.created' : 'billing_settings.updated',
                    workspace: $workspace,
                    entityTable: 'billing_settings',
                    entityPk: ['workspace_uuid' => $workspace->getUuid()->toRfc4122()],
                    oldValues: $oldValues,
                    newValues: $this->billingSettingsAuditValues($billingSettings, $workspace),
                    changedFields: ['association_name', 'timezone', 'invoice_generation_day', 'reading_freshness_window_days'],
                    reason: 'Настройки расчетов изменены из админки.',
                );

                $entityManager->flush();
                $this->addFlash('success', 'Настройки расчетов сохранены.');

                return $this->redirectToRoute('app_admin_billing_settings', [], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_billing_settings/index.html.twig', [
                'billing_settings' => $billingSettings,
                'workspace' => $workspace,
                'form' => $form,
                'is_new' => $isNew,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_billing_settings/index.html.twig', [
            'billing_settings' => $billingSettings,
            'workspace' => $workspace,
            'form' => $form,
            'is_new' => $isNew,
        ]);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function billingSettingsAuditValues(BillingSettings $billingSettings, Workspace $workspace): array
    {
        return [
            'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
            'association_name' => $billingSettings->getAssociationName(),
            'timezone' => $workspace->getTimezone(),
            'invoice_generation_day' => $billingSettings->getInvoiceGenerationDay(),
            'reading_freshness_window_days' => $billingSettings->getReadingFreshnessWindowDays(),
        ];
    }
}

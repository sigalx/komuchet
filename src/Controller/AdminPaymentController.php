<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\PaymentSource;
use App\Form\PaymentCancelType;
use App\Form\PaymentType;
use App\Repository\AccountRepository;
use App\Repository\PaymentRepository;
use App\Pagination\AdminPaginator;
use App\Service\AuditLogger;
use App\Service\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('WORKSPACE_ACCESS')]
final class AdminPaymentController extends AbstractController
{
    #[Route('/admin/payments', name: 'app_admin_payment_index', methods: ['GET'])]
    public function index(Request $request, PaymentRepository $paymentRepository, WorkspaceContext $workspaceContext, AdminPaginator $paginator): Response
    {
        $search = trim($request->query->getString('q'));
        $statusFilter = $paymentRepository->normalizeStatusFilter($request->query->getString('status', PaymentRepository::STATUS_FILTER_ALL));
        $sourceFilter = trim($request->query->getString('source'));
        $source = $paymentRepository->normalizeSourceFilter($sourceFilter);
        $paidOnFrom = trim($request->query->getString('paid_on_from'));
        $paidOnTo = trim($request->query->getString('paid_on_to'));
        $sort = $paymentRepository->normalizeSort($request->query->getString('sort', PaymentRepository::SORT_PAID_ON));
        $direction = $paymentRepository->normalizeSortDirection($request->query->getString('dir', PaymentRepository::SORT_DESC));
        $pagination = $paginator->paginate(
            $paymentRepository->createByWorkspaceForAdminListQuery(
                $workspaceContext->requireCurrentWorkspace(),
                $search,
                $statusFilter,
                $source,
                $this->parseDate($paidOnFrom),
                $this->parseDate($paidOnTo),
                $sort,
                $direction,
            ),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_payment/index.html.twig', [
            'payments' => $pagination->getItems(),
            'pagination' => $pagination,
            'filters' => [
                'q' => $search,
                'status' => $statusFilter,
                'source' => $source?->value ?? '',
                'paid_on_from' => $paidOnFrom,
                'paid_on_to' => $paidOnTo,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/admin/accounts/{accountUuid}/payments/new', name: 'app_admin_payment_new', methods: ['GET', 'POST'])]
    public function new(
        string $accountUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($accountUuid, $accountRepository, $workspaceContext);
        $payment = new Payment($workspace, $account, '0', null, PaymentSource::Manual, $this->getCurrentUser());
        $form = $this->createForm(PaymentType::class, $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $entityManager->persist($payment);
                $auditLogger->record(
                    action: 'payment.created',
                    workspace: $workspace,
                    entityTable: 'payments',
                    entityUuid: $payment->getUuid(),
                    newValues: [
                        'account_uuid' => $account->getUuid()->toRfc4122(),
                        'account_number' => $account->getNumber(),
                        'amount' => $payment->getAmount(),
                        'paid_on' => $payment->getPaidOn()->format('Y-m-d'),
                        'source' => $payment->getSource()->value,
                        'payer_name' => $payment->getPayerName(),
                        'purpose' => $payment->getPurpose(),
                        'external_reference' => $payment->getExternalReference(),
                    ],
                    changedFields: ['amount', 'paid_on', 'source', 'payer_name', 'purpose', 'external_reference'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Оплата %.2f руб. внесена по участку %s.', (float) $payment->getAmount(), $account->getNumber()));

                return $this->redirectToRoute('app_admin_payment_show', ['uuid' => $payment->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_payment/new.html.twig', [
                'payment' => $payment,
                'account' => $account,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_payment/new.html.twig', [
            'payment' => $payment,
            'account' => $account,
            'form' => $form,
        ]);
    }

    #[Route('/admin/payments/{uuid}', name: 'app_admin_payment_show', methods: ['GET'])]
    public function show(string $uuid, PaymentRepository $paymentRepository, WorkspaceContext $workspaceContext): Response
    {
        $payment = $this->findPayment($uuid, $paymentRepository, $workspaceContext);

        return $this->render('admin_payment/show.html.twig', [
            'payment' => $payment,
            'cancel_form' => $this->createCancelForm($payment),
        ]);
    }

    #[Route('/admin/payments/{uuid}/cancel', name: 'app_admin_payment_cancel', methods: ['POST'])]
    public function cancel(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        PaymentRepository $paymentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $payment = $this->findPayment($uuid, $paymentRepository, $workspaceContext);
        $form = $this->createCancelForm($payment);
        $form->handleRequest($request);

        if (!$payment->isActive()) {
            $this->addFlash('warning', 'Эта оплата уже не является активной.');

            return $this->redirectToRoute('app_admin_payment_show', ['uuid' => $payment->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $reason = (string) $form->get('reason')->getData();
            $oldValues = [
                'amount' => $payment->getAmount(),
                'paid_on' => $payment->getPaidOn()->format('Y-m-d'),
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ];
            $payment->cancel($reason, $this->getCurrentUser());
            $auditLogger->record(
                action: 'payment.cancelled',
                workspace: $payment->getWorkspace(),
                entityTable: 'payments',
                entityUuid: $payment->getUuid(),
                oldValues: $oldValues,
                newValues: [
                    'cancelled_at' => $payment->getCancelledAt()?->format(DATE_ATOM),
                    'cancellation_reason' => $payment->getCancellationReason(),
                ],
                changedFields: ['cancelled_at', 'cancelled_by', 'cancellation_reason'],
                reason: $reason,
            );
            $entityManager->flush();
            $this->addFlash('success', 'Оплата отменена.');

            return $this->redirectToRoute('app_admin_payment_show', ['uuid' => $payment->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_payment/show.html.twig', [
            'payment' => $payment,
            'cancel_form' => $form,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function createCancelForm(Payment $payment): FormInterface
    {
        return $this->createForm(PaymentCancelType::class, null, [
            'action' => $this->generateUrl('app_admin_payment_cancel', ['uuid' => $payment->getUuid()]),
        ]);
    }

    private function findPayment(string $uuid, PaymentRepository $paymentRepository, WorkspaceContext $workspaceContext): Payment
    {
        try {
            $paymentUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Payment was not found.');
        }

        $payment = $paymentRepository->findOneByWorkspaceAndUuid($workspaceContext->requireCurrentWorkspace(), $paymentUuid);

        if (!$payment instanceof Payment) {
            throw new NotFoundHttpException('Payment was not found.');
        }

        return $payment;
    }

    private function findActiveAccount(string $uuid, AccountRepository $accountRepository, WorkspaceContext $workspaceContext): Account
    {
        try {
            $accountUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Account was not found.');
        }

        $account = $accountRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $accountUuid,
        );

        if (!$account instanceof Account) {
            throw new NotFoundHttpException('Account was not found.');
        }

        return $account;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value, new DateTimeZone('Europe/Moscow'));

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}

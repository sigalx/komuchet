<?php

namespace App\Controller;

use App\Repository\AccountStatementDeliveryRepository;
use App\Pagination\AdminPaginator;
use App\Service\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('WORKSPACE_ACCESS')]
final class AdminAccountStatementDeliveryController extends AbstractController
{
    #[Route('/admin/account-statement-deliveries', name: 'app_admin_account_statement_delivery_index', methods: ['GET'])]
    public function index(
        Request $request,
        AccountStatementDeliveryRepository $deliveryRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $search = trim($request->query->getString('q'));
        $statusFilter = $deliveryRepository->normalizeStatusFilter($request->query->getString('status', AccountStatementDeliveryRepository::STATUS_FILTER_ALL));
        $sort = $deliveryRepository->normalizeSort($request->query->getString('sort', AccountStatementDeliveryRepository::SORT_CREATED_AT));
        $direction = $deliveryRepository->normalizeSortDirection($request->query->getString('dir', AccountStatementDeliveryRepository::SORT_DESC));
        $pagination = $paginator->paginate(
            $deliveryRepository->createByWorkspaceForAdminListQuery($workspace, $search, $statusFilter, $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_account_statement_delivery/index.html.twig', [
            'deliveries' => $pagination->getItems(),
            'pagination' => $pagination,
            'status_choices' => AccountStatementDeliveryRepository::statusFilterChoices(),
            'filters' => [
                'q' => $search,
                'status' => $statusFilter,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }
}

<?php

namespace App\Controller;

use App\Service\AccountBalanceListProvider;
use App\Service\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/account-balances')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminAccountBalanceController extends AbstractController
{
    private const STATE_FILTERS = [
        AccountBalanceListProvider::STATE_FILTER_ALL => 'Все',
        AccountBalanceListProvider::STATE_FILTER_DEBT => 'Должники',
        AccountBalanceListProvider::STATE_FILTER_OVERPAYMENT => 'Переплата',
        AccountBalanceListProvider::STATE_FILTER_SETTLED => 'Нулевой баланс',
    ];

    private const SORTS = [
        AccountBalanceListProvider::SORT_NUMBER => 'По номеру участка',
        AccountBalanceListProvider::SORT_DEBT_DESC => 'Сначала большой долг',
        AccountBalanceListProvider::SORT_OVERPAYMENT_DESC => 'Сначала большая переплата',
    ];

    #[Route(name: 'app_admin_account_balance_index', methods: ['GET'])]
    public function index(
        Request $request,
        AccountBalanceListProvider $balanceListProvider,
        WorkspaceContext $workspaceContext,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $stateFilter = $balanceListProvider->normalizeStateFilter($request->query->getString('state', AccountBalanceListProvider::STATE_FILTER_ALL));
        $sort = $balanceListProvider->normalizeSort($request->query->getString('sort', AccountBalanceListProvider::SORT_NUMBER));
        $pagination = $balanceListProvider->paginateByWorkspace($workspace, $stateFilter, $sort, $request->query->getInt('page', 1));

        return $this->render('admin_account_balance/index.html.twig', [
            'balance_rows' => $pagination->getItems(),
            'pagination' => $pagination,
            'state_filters' => self::STATE_FILTERS,
            'sorts' => self::SORTS,
            'filters' => [
                'state' => $stateFilter,
                'sort' => $sort,
            ],
        ]);
    }
}

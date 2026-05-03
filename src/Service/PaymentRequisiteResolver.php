<?php

namespace App\Service;

use App\Entity\AccountStatementSnapshot;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Repository\PaymentRequisiteAssignmentRepository;

final readonly class PaymentRequisiteResolver
{
    public function __construct(
        private PaymentRequisiteAssignmentRepository $assignmentRepository,
    ) {
    }

    public function applyToSnapshot(Workspace $workspace, AccountStatement $statement, AccountStatementSnapshot $snapshot): void
    {
        $assignment = $this->assignmentRepository->findCurrentForType(
            $workspace,
            $this->resolveSingleAccrualType($statement),
            $snapshot->getStatementDate(),
        );

        $profile = $assignment?->getPaymentRequisiteProfile();

        $snapshot->applyPaymentRequisites($profile, $profile === null ? null : $this->renderPaymentPurpose($profile->getPaymentPurposeTemplate(), $snapshot));
    }

    private function resolveSingleAccrualType(AccountStatement $statement): ?AccrualType
    {
        $types = [];

        foreach ($statement->accrualRows as $row) {
            $type = $row->accrual->getType();
            $types[$type->value] = $type;
        }

        return count($types) === 1 ? array_values($types)[0] : null;
    }

    private function renderPaymentPurpose(?string $template, AccountStatementSnapshot $snapshot): string
    {
        $template = $template === null || trim($template) === ''
            ? 'Оплата по квитанции {statement_number}, участок {account_number}'
            : $template;

        return strtr($template, [
            '{statement_number}' => $snapshot->getNumber(),
            '{account_number}' => $snapshot->getAccountNumber(),
            '{statement_date}' => $snapshot->getStatementDate()->format('d.m.Y'),
            '{amount_to_pay}' => number_format((float) $snapshot->getAmountToPay(), 2, '.', ''),
            '{workspace_name}' => $snapshot->getWorkspaceName(),
        ]);
    }
}

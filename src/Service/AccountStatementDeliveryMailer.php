<?php

namespace App\Service;

use App\Entity\AccountStatementDeliveryAttempt;
use App\Repository\AccountStatementAccrualSnapshotRepository;
use App\Repository\AccountStatementElectricityLineSnapshotRepository;
use App\Repository\AccountStatementElectricityRegisterSnapshotRepository;
use App\Repository\AccountStatementPaymentSnapshotRepository;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class AccountStatementDeliveryMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private AccountStatementAccrualSnapshotRepository $statementAccrualRepository,
        private AccountStatementElectricityRegisterSnapshotRepository $statementElectricityRegisterRepository,
        private AccountStatementElectricityLineSnapshotRepository $statementElectricityLineRepository,
        private AccountStatementPaymentSnapshotRepository $statementPaymentRepository,
        private AccountStatementPaymentQrCodeGenerator $paymentQrCodeGenerator,
        private AccountStatementPdfRenderer $pdfRenderer,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')]
        private string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    public function send(AccountStatementDeliveryAttempt $attempt): void
    {
        $delivery = $attempt->getDelivery() ?? throw new RuntimeException('Попытка доставки не связана с доставкой.');
        $statement = $delivery->getAccountStatement() ?? throw new RuntimeException('Доставка не связана с квитанцией.');
        $workspace = $delivery->getWorkspace() ?? throw new RuntimeException('Доставка не связана с хозяйством.');

        if ($delivery->isCancelled()) {
            throw new RuntimeException('Доставка отменена.');
        }

        if ($statement->isCancelled()) {
            throw new RuntimeException('Квитанция отменена.');
        }

        $pdf = $this->pdfRenderer->render([
            'statement' => $statement,
            'accruals' => $this->statementAccrualRepository->findByStatement($workspace, $statement),
            'electricity_registers' => $this->statementElectricityRegisterRepository->findByStatement($workspace, $statement),
            'electricity_lines' => $this->statementElectricityLineRepository->findByStatement($workspace, $statement),
            'payments' => $this->statementPaymentRepository->findByStatement($workspace, $statement),
            'payment_qr_code' => $this->paymentQrCodeGenerator->generate($statement),
        ]);

        $recipientName = $delivery->getRecipientName();
        $recipientGreeting = $recipientName === null ? '' : ', '.$recipientName;
        $amountToPay = $this->formatMoney($statement->getAmountToPay());
        $subject = sprintf('Квитанция %s, участок %s', $statement->getNumber(), $statement->getAccountNumber());
        $filename = sprintf('statement-%s.pdf', preg_replace('/[^A-Za-z0-9._-]+/', '-', $statement->getNumber()));

        $text = sprintf(
            "Здравствуйте%s.\n\nВо вложении квитанция %s по участку %s.\nСумма к оплате: %s руб.\n\nЭто автоматическое письмо, отвечать на него не нужно.\n",
            $recipientGreeting,
            $statement->getNumber(),
            $statement->getAccountNumber(),
            $amountToPay,
        );

        $html = sprintf(
            '<p>Здравствуйте%s.</p><p>Во вложении квитанция <strong>%s</strong> по участку <strong>%s</strong>.</p><p>Сумма к оплате: <strong>%s руб.</strong></p><p>Это автоматическое письмо, отвечать на него не нужно.</p>',
            htmlspecialchars($recipientGreeting, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($statement->getNumber(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($statement->getAccountNumber(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($amountToPay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );

        $this->mailer->send((new Email())
            ->from($this->buildAddress($this->fromEmail, $this->fromName))
            ->to($this->buildAddress($delivery->getRecipientEmail(), $recipientName))
            ->subject($subject)
            ->text($text)
            ->html($html)
            ->attach($pdf, $filename, 'application/pdf')
        );
    }

    private function buildAddress(string $email, ?string $name): Address
    {
        $name = $name === null ? '' : trim($name);

        if ($name === '') {
            return new Address($email);
        }

        return new Address($email, $name);
    }

    private function formatMoney(string $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ');
    }
}

<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final readonly class AccountStatementPdfRenderer
{
    public function __construct(
        private Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(array $context): string
    {
        $html = $this->twig->render('account_statement/pdf.html.twig', $context);
        $runtimeDir = $this->projectDir.'/var/dompdf';
        $fontCacheDir = $runtimeDir.'/fonts';

        if (!is_dir($fontCacheDir)) {
            mkdir($fontCacheDir, 0775, true);
        }

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('A4');
        $options->setDefaultPaperOrientation('portrait');
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setIsFontSubsettingEnabled(true);
        $options->setChroot($this->projectDir);
        $options->setTempDir($runtimeDir);
        $options->setFontCache($fontCacheDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}

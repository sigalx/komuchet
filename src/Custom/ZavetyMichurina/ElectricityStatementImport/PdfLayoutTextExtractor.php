<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class PdfLayoutTextExtractor
{
    public function __construct(
        private readonly string $binary = 'pdftotext',
    ) {
    }

    public function extract(string $pdfPath): string
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException(sprintf('PDF file "%s" is not readable.', $pdfPath));
        }

        $process = new Process([$this->binary, '-layout', $pdfPath, '-']);
        $process->setTimeout(30);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            throw new RuntimeException(sprintf('Failed to extract text from PDF "%s": %s', $pdfPath, $exception->getMessage()), previous: $exception);
        }

        $text = $process->getOutput();

        if (trim($text) === '') {
            throw new RuntimeException(sprintf('PDF file "%s" produced empty text.', $pdfPath));
        }

        return $text;
    }
}

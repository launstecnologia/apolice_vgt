<?php

namespace App\Services;

class ApoliceTemplateService
{
    private WordPlaceholderService $placeholderService;
    private PdfConverterService $pdfConverter;
    private string $templatePath;
    private string $docxDir;
    private string $pdfDir;

    public function __construct(
        WordPlaceholderService $placeholderService,
        PdfConverterService $pdfConverter,
        string $templatePath,
        string $docxDir,
        string $pdfDir
    ) {
        $this->placeholderService = $placeholderService;
        $this->pdfConverter = $pdfConverter;
        $this->templatePath = $templatePath;
        $this->docxDir = $docxDir;
        $this->pdfDir = $pdfDir;
    }

    public function gerarPdf(array $placeholders): string
    {
        $docxPath = $this->buildDocxPath();
        if (!is_dir($this->docxDir)) {
            mkdir($this->docxDir, 0775, true);
        }

        $this->placeholderService->apply($this->templatePath, $docxPath, $placeholders);

        return $this->pdfConverter->convertDocxToPdf($docxPath, $this->pdfDir);
    }

    private function buildDocxPath(): string
    {
        $name = 'apolice_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.docx';
        return rtrim($this->docxDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }
}

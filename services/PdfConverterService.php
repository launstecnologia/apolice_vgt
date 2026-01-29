<?php

namespace App\Services;

class PdfConverterService
{
    private string $libreOfficePath;

    public function __construct(string $libreOfficePath = '')
    {
        $this->libreOfficePath = $libreOfficePath !== '' ? $libreOfficePath : 'soffice';
    }

    public function convertDocxToPdf(string $docxPath, string $outputDir): string
    {
        if (!file_exists($docxPath)) {
            throw new \RuntimeException('Arquivo DOCX não encontrado para conversão.');
        }
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
                throw new \RuntimeException('Falha ao criar diretório de PDF.');
            }
        }
        if (!function_exists('shell_exec')) {
            throw new \RuntimeException('shell_exec está desabilitado no servidor. Habilite no php.ini ou use um serviço de conversão.');
        }

        $command = sprintf(
            '%s --headless --nologo --convert-to pdf --outdir %s %s 2>&1',
            escapeshellcmd($this->libreOfficePath),
            escapeshellarg($outputDir),
            escapeshellarg($docxPath)
        );

        $output = \shell_exec($command);
        if ($output === null) {
            throw new \RuntimeException('Falha ao executar o LibreOffice.');
        }

        $expected = $this->buildPdfPath($docxPath, $outputDir);
        if (!file_exists($expected)) {
            throw new \RuntimeException('PDF não foi gerado. Saída: ' . trim($output));
        }

        return $expected;
    }

    private function buildPdfPath(string $docxPath, string $outputDir): string
    {
        $base = pathinfo($docxPath, PATHINFO_FILENAME);
        return rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base . '.pdf';
    }
}

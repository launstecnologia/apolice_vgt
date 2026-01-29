<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfFromHtmlService
{
    public function convert(string $htmlPath, string $outputPath): void
    {
        if (!file_exists($htmlPath)) {
            throw new \RuntimeException('HTML não encontrado para gerar PDF.');
        }

        $html = file_get_contents($htmlPath);
        if ($html === false) {
            throw new \RuntimeException('Falha ao ler HTML.');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('dpi', 96);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html);
        $dompdf->render();

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($outputPath, $dompdf->output());
    }
}

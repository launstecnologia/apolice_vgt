<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfFromHtmlService
{
    public function convert(string $htmlPath, string $outputPath): void
    {
        if (!file_exists($htmlPath)) {
            throw new \RuntimeException('HTML não encontrado para conversão.');
        }

        $html = file_get_contents($htmlPath);
        if ($html === false) {
            throw new \RuntimeException('Falha ao ler o HTML.');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('dpi', 96);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $paper = $this->detectPaperSize($html);
        if ($paper !== null) {
            $dompdf->setPaper($paper);
        } else {
            $dompdf->setPaper('A4');
        }
        $dompdf->render();

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($outputPath, $dompdf->output());
    }

    private function detectPaperSize(string $html): ?array
    {
        if (preg_match('/@page\\s*\\{[^}]*size:\\s*([0-9.]+)in\\s+([0-9.]+)in/i', $html, $matches)) {
            $widthIn = (float) $matches[1];
            $heightIn = (float) $matches[2];
            if ($widthIn > 0 && $heightIn > 0) {
                $widthPt = $widthIn * 72;
                $heightPt = $heightIn * 72;
                return [0, 0, $widthPt, $heightPt];
            }
        }

        return null;
    }
}

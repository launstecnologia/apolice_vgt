<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

class PdfSeguroParser
{
    private array $contextMap = [
        'COMERCIAL: COMÉRCIO E SERVIÇO' => ['COMERCIAL', 'COMERCIO', 'SERVICO'],
        'COMERCIAL: ESCRITÓRIO E CONSULTÓRIO' => ['COMERCIAL', 'ESCRITORIO', 'CONSULTORIO'],
        'RESIDENCIAL: CASA' => ['RESIDENCIAL', 'CASA'],
        'RESIDENCIAL: APARTAMENTO' => ['RESIDENCIAL', 'APARTAMENTO'],
    ];

    public function parse(string $pdfPath, ?string $debugPath = null): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($pdfPath);
        $pages = $pdf->getPages();

        $currentTipo = null;
        $rows = [];
        $buffer = [];
        $hasText = false;
        $debugHandle = null;

        if ($debugPath !== null) {
            $dir = dirname($debugPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $debugHandle = fopen($debugPath, 'w');
        }

        foreach ($pages as $index => $page) {
            $text = $page->getText();
            if (trim($text) !== '') {
                $hasText = true;
            }

            if ($debugHandle) {
                fwrite($debugHandle, "=== Pagina " . ($index + 1) . " ===\n");
                fwrite($debugHandle, $text . "\n\n");
            }

            $lines = preg_split("/\r\n|\n|\r/", $text);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $context = $this->detectContext($line);
                if ($context !== null) {
                    $currentTipo = $context;
                    $buffer = [];
                    continue;
                }

                if ($currentTipo === null || $this->looksLikeHeader($line)) {
                    continue;
                }

                $numbers = $this->extractNumbers($line);
                if (count($numbers) >= 5) {
                    $mapped = $this->mapNumbers($numbers);
                    if ($mapped !== null) {
                        $mapped['tipo'] = $currentTipo;
                        $rows[] = $mapped;
                    }
                    $buffer = [];
                    continue;
                }

                if (!empty($numbers)) {
                    foreach ($numbers as $number) {
                        $buffer[] = $number;
                    }

                    if (count($buffer) >= 5) {
                        $mapped = $this->mapNumbers($buffer);
                        if ($mapped !== null) {
                            $mapped['tipo'] = $currentTipo;
                            $rows[] = $mapped;
                        }
                        $buffer = [];
                    }
                }
            }
        }

        if ($debugHandle) {
            fclose($debugHandle);
        }

        if (!$hasText) {
            throw new \RuntimeException('PDF sem texto selecionável. Esse arquivo parece ser imagem e requer OCR.');
        }

        return $rows;
    }

    private function detectContext(string $line): ?string
    {
        $upper = $this->normalizeText($line);

        foreach ($this->contextMap as $label => $tokens) {
            $match = true;
            foreach ($tokens as $token) {
                if (mb_strpos($upper, $token, 0, 'UTF-8') === false) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $label;
            }
        }

        return null;
    }

    private function looksLikeHeader(string $line): bool
    {
        $upper = $this->normalizeText($line);
        return (mb_strpos($upper, 'PREMIO', 0, 'UTF-8') !== false)
            || (mb_strpos($upper, 'INCENDIO', 0, 'UTF-8') !== false)
            || (mb_strpos($upper, 'VENDA', 0, 'UTF-8') !== false)
            || (mb_strpos($upper, 'ALUGUEL', 0, 'UTF-8') !== false);
    }

    private function extractNumbers(string $line): array
    {
        $matches = [];
        preg_match_all('/\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}|\d{1,3}(?:\.\d{3})+|\d+/', $line, $matches);
        $values = [];

        foreach ($matches[0] as $raw) {
            $values[] = $this->normalizeDecimal($raw);
        }

        return $values;
    }

    private function normalizeText(string $text): string
    {
        $upper = mb_strtoupper($text, 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $upper);
        if ($ascii !== false) {
            return $ascii;
        }

        return $upper;
    }

    private function mapNumbers(array $numbers): ?array
    {
        if (count($numbers) >= 6) {
            $vendaval = $this->sumDecimals($numbers[3], $numbers[4]);
            return [
                'premio_mensal' => $numbers[0],
                'incendio' => $numbers[1],
                'incendio_conteudo' => $numbers[2],
                'vendaval' => $vendaval,
                'perda_aluguel' => $numbers[5],
            ];
        }

        if (count($numbers) >= 5) {
            return [
                'premio_mensal' => $numbers[0],
                'incendio' => $numbers[1],
                'incendio_conteudo' => $numbers[2],
                'vendaval' => $numbers[3],
                'perda_aluguel' => $numbers[4],
            ];
        }

        return null;
    }

    private function normalizeDecimal(string $value): string
    {
        $value = str_replace(['R$', ' '], '', $value);
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);

        if (strpos($value, '.') === false) {
            return $value . '.00';
        }

        [$int, $dec] = explode('.', $value, 2);
        $dec = substr($dec . '00', 0, 2);

        return $int . '.' . $dec;
    }

    private function sumDecimals(string $a, string $b): string
    {
        $centsA = $this->toCents($a);
        $centsB = $this->toCents($b);
        $sum = $centsA + $centsB;

        return $this->fromCents($sum);
    }

    private function toCents(string $value): int
    {
        $normalized = $this->normalizeDecimal($value);
        [$int, $dec] = explode('.', $normalized, 2);
        return ((int) $int * 100) + (int) $dec;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $int = intdiv($cents, 100);
        $dec = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return $sign . $int . '.' . $dec;
    }
}

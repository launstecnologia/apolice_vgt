<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelProcessor
{
    public function process(string $inputPath, string $outputPath, array $map): void
    {
        $spreadsheet = IOFactory::load($inputPath);
        $sheet = $spreadsheet->getActiveSheet();

        $headerMap = $this->detectHeaderMap($sheet);
        if (!isset($headerMap['credito_s_multa'])) {
            throw new \RuntimeException('Coluna credito_s_multa não encontrada no Excel.');
        }

        $categoryIndex = $this->buildCategoryIndex($map);
        $maxRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $maxRow; $row++) {
            $creditoCell = $headerMap['credito_s_multa'] . $row;
            $creditoRaw = (string) $sheet->getCell($creditoCell)->getValue();
            $credito = $this->normalizeDecimal($creditoRaw);

            if ($credito === null) {
                continue;
            }

            $categoria = $this->detectCategoriaFromCredito($credito);
            if ($categoria === null || !isset($categoryIndex[$categoria])) {
                continue;
            }

            $creditoCents = $this->toCents($credito);
            $premioCents = $this->encontrarPremioReferencia($creditoCents, $categoryIndex[$categoria]['premios']);
            if ($premioCents === null) {
                continue;
            }

            $data = $categoryIndex[$categoria]['data'][(string) $premioCents] ?? null;
            if ($data === null || !empty($data['ambiguous'])) {
                continue;
            }

            $this->fillIfEmpty($sheet, $headerMap, $row, 'incendio', $data['incendio'] ?? null);
            $this->fillIfEmpty($sheet, $headerMap, $row, 'incendio_conteudo', $data['incendio_conteudo'] ?? null);
            $this->fillIfEmpty($sheet, $headerMap, $row, 'vendaval', $data['vendaval'] ?? null);
            $this->fillIfEmpty($sheet, $headerMap, $row, 'perda_aluguel', $data['perda_aluguel'] ?? null);
            $this->fillIfEmpty($sheet, $headerMap, $row, 'danos_eletricos', $data['danos_eletricos'] ?? null);
            $this->fillIfEmpty($sheet, $headerMap, $row, 'responsabilidade_civil', $data['responsabilidade_civil'] ?? null);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);
    }

    private function detectHeaderMap(Worksheet $sheet): array
    {
        $row = 1;
        $highestColumn = $sheet->getHighestColumn();
        $columns = $sheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, true, true);

        $map = [];
        foreach ($columns[$row] as $col => $value) {
            $normalized = $this->normalizeHeader((string) $value);
            if ($normalized !== '') {
                $map[$normalized] = $col;
            }
        }

        return $map;
    }

    private function fillIfEmpty(Worksheet $sheet, array $headerMap, int $row, string $columnKey, ?string $value): void
    {
        if ($value === null || !isset($headerMap[$columnKey])) {
            return;
        }

        $cell = $headerMap[$columnKey] . $row;
        $current = $sheet->getCell($cell)->getValue();

        if ($current !== null && $current !== '') {
            return;
        }

        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }
        $value = str_replace([' ', '-', '.'], '_', $value);
        $value = preg_replace('/[^a-z0-9_]+/u', '', $value);
        $value = preg_replace('/_+/', '_', $value);

        return trim($value, '_');
    }

    private function normalizeDecimal(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(['R$', ' '], '', $value);

        if (strpos($value, ',') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            if (substr_count($value, '.') > 1) {
                $parts = explode('.', $value);
                $decimal = array_pop($parts);
                $value = implode('', $parts) . '.' . $decimal;
            }
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        if (strpos($value, '.') === false) {
            return $value . '.00';
        }

        [$int, $dec] = explode('.', $value, 2);
        $dec = substr($dec . '00', 0, 2);

        return $int . '.' . $dec;
    }

    private function detectCategoriaFromCredito(string $credito): ?string
    {
        $parts = explode('.', $credito, 2);
        $centavos = $parts[1] ?? '00';

        if ($centavos === '99') {
            return 'COMERCIAL: COMÉRCIO E SERVIÇO';
        }
        if ($centavos === '98') {
            return 'COMERCIAL: ESCRITÓRIO E CONSULTÓRIO';
        }
        if ($centavos === '97') {
            return 'RESIDENCIAL: CASA';
        }
        if ($centavos === '96') {
            return 'RESIDENCIAL: APARTAMENTO';
        }

        return null;
    }

    private function buildCategoryIndex(array $map): array
    {
        $index = [];

        foreach ($map as $premio => $data) {
            if (!is_array($data)) {
                continue;
            }

            $categoria = $data['categoria'] ?? $data['tipo'] ?? null;
            if ($categoria === null || !$this->isPremioKey($premio)) {
                continue;
            }

            $cents = $this->toCents($premio);
            if (!isset($index[$categoria])) {
                $index[$categoria] = [
                    'premios' => [],
                    'data' => [],
                ];
            }
            $index[$categoria]['premios'][] = $cents;
            $index[$categoria]['data'][(string) $cents] = $data;
        }

        foreach ($index as $categoria => $payload) {
            $premios = $payload['premios'];
            sort($premios, SORT_NUMERIC);
            $index[$categoria]['premios'] = $premios;
        }

        return $index;
    }

    private function isPremioKey(string $value): bool
    {
        return (bool) preg_match('/^\\d+\\.\\d{2}$/', $value);
    }

    private function toCents(string $value): int
    {
        [$int, $dec] = explode('.', $value, 2);
        return ((int) $int * 100) + (int) $dec;
    }

    private function encontrarPremioReferencia(int $creditoCents, array $premiosCents): ?int
    {
        foreach ($premiosCents as $premioCents) {
            if ($premioCents >= $creditoCents) {
                return $premioCents;
            }
        }

        return null;
    }
}

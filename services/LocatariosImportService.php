<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LocatariosImportService
{
    private array $seguroMap = [];
    private array $requiredFields = [
        'segurado_nome',
        'segurado_tipo',
        'segurado_cpf_cnpj',
        'segurado_endereco',
        'segurado_bairro',
        'segurado_cep',
        'segurado_cidade',
        'segurado_uf',
    ];

    private array $riskFallbackMap = [
        'risco_endereco' => 'segurado_endereco',
        'risco_numero' => 'segurado_numero',
        'risco_bairro' => 'segurado_bairro',
        'risco_cep' => 'segurado_cep',
        'risco_cidade' => 'segurado_cidade',
        'risco_uf' => 'segurado_uf',
    ];

    private array $headerAliases = [
        'inquilino_nome' => 'segurado_nome',
        'inquilino_pessoa' => 'segurado_tipo',
        'inquilino_doc' => 'segurado_cpf_cnpj',
        'cidade' => 'segurado_cidade',
        'estado' => 'segurado_uf',
        'bairro' => 'segurado_bairro',
        'cep' => 'segurado_cep',
        'endereco' => 'segurado_endereco',
        'numero' => 'segurado_numero',
        'imovel_codigo' => 'id',
        'incendio' => 'coberturas_incendio',
        'incendio_conteudo' => 'coberturas_incendio_conteudo',
        'vendaval' => 'coberturas_vendaval',
        'perda_aluguel' => 'coberturas_perda_aluguel',
        'danos_eletricos' => 'coberturas_danos_eletricos',
        'dano_eletrico' => 'coberturas_danos_eletricos',
        'responsabilidade_civil' => 'coberturas_responsabilidade_civil',
    ];

    public function __construct(array $seguroMap = [])
    {
        $this->seguroMap = $seguroMap;
    }

    public function import(string $filePath): array
    {
        $sheet = $this->loadSheet($filePath);
        $headerMap = $this->detectHeaderMap($sheet);

        $missingHeaders = array_diff($this->requiredFields, array_keys($headerMap));
        if (!empty($missingHeaders)) {
            throw new \RuntimeException('Colunas obrigatórias ausentes: ' . implode(', ', $missingHeaders));
        }

        $maxRow = $sheet->getHighestRow();
        $categoryIndex = $this->buildCategoryIndex($this->seguroMap);
        $locatarios = [];
        $errors = [];

        for ($row = 2; $row <= $maxRow; $row++) {
            $data = $this->extractRow($sheet, $headerMap, $row);
            if ($this->isRowEmpty($data)) {
                continue;
            }

            if (!empty($categoryIndex)) {
                $data = $this->fillCoberturasFromMap($data, $categoryIndex);
            }

            $missing = $this->validateRow($data);
            if (!empty($missing)) {
                $errors[] = [
                    'row' => $row,
                    'missing' => $missing,
                ];
            }

            $locatarios[] = $this->mapToLocatario($data, $row);
        }

        return [
            'locatarios' => $locatarios,
            'errors' => $errors,
        ];
    }

    private function loadSheet(string $filePath): Worksheet
    {
        $type = IOFactory::identify($filePath);
        $reader = IOFactory::createReader($type);

        if ($reader instanceof Csv) {
            $reader->setDelimiter(';');
            $reader->setEnclosure('"');
            $reader->setInputEncoding('Windows-1252');
        }

        $spreadsheet = $reader->load($filePath);
        return $spreadsheet->getActiveSheet();
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
                $canonical = $this->headerAliases[$normalized] ?? $normalized;
                if (!isset($map[$canonical])) {
                    $map[$canonical] = $col;
                }
            }
        }

        return $map;
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

    private function extractRow(Worksheet $sheet, array $headerMap, int $row): array
    {
        $data = [];
        foreach ($headerMap as $key => $col) {
            $raw = (string) $sheet->getCell($col . $row)->getValue();
            $data[$key] = trim($this->normalizeText($raw));
        }

        $data = $this->normalizeRiskFallback($data);

        return $data;
    }

    private function isRowEmpty(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== '') {
                return false;
            }
        }
        return true;
    }

    private function validateRow(array $data): array
    {
        $missing = [];
        foreach ($this->requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $missing[] = $field;
            }
        }

        foreach ($this->riskFallbackMap as $risk => $fallback) {
            $riskValue = $data[$risk] ?? '';
            $fallbackValue = $data[$fallback] ?? '';
            if ($riskValue === '' && $fallbackValue === '') {
                $missing[] = $risk;
            }
        }

        return $missing;
    }

    private function mapToLocatario(array $data, int $row): array
    {
        return [
            'id' => $data['id'] ?? $data['segurado_cpf_cnpj'] ?? ('row_' . $row),
            'segurado' => [
                'nome' => $data['segurado_nome'] ?? '',
                'tipo' => $data['segurado_tipo'] ?? '',
                'cpf_cnpj' => $data['segurado_cpf_cnpj'] ?? '',
                'endereco' => $data['segurado_endereco'] ?? '',
                'bairro' => $data['segurado_bairro'] ?? '',
                'cep' => $data['segurado_cep'] ?? '',
                'cidade' => $data['segurado_cidade'] ?? '',
                'uf' => $data['segurado_uf'] ?? '',
            ],
            'risco' => [
                'endereco' => $data['risco_endereco'] ?? '',
                'numero' => $data['risco_numero'] ?? '',
                'bairro' => $data['risco_bairro'] ?? '',
                'cep' => $data['risco_cep'] ?? '',
                'cidade' => $data['risco_cidade'] ?? '',
                'uf' => $data['risco_uf'] ?? '',
                'questionario' => $data['risco_questionario'] ?? '',
            ],
            'coberturas' => [
                'incendio' => $data['coberturas_incendio'] ?? '',
                'incendio_conteudo' => $data['coberturas_incendio_conteudo'] ?? '',
                'vendaval' => $data['coberturas_vendaval'] ?? '',
                'perda_aluguel' => $data['coberturas_perda_aluguel'] ?? '',
                'danos_eletricos' => $data['coberturas_danos_eletricos'] ?? '',
                'responsabilidade_civil' => $data['coberturas_responsabilidade_civil'] ?? '',
            ],
        ];
    }

    private function normalizeRiskFallback(array $data): array
    {
        foreach ($this->riskFallbackMap as $risk => $fallback) {
            if (empty($data[$risk]) && !empty($data[$fallback])) {
                $data[$risk] = $data[$fallback];
            }
        }

        return $data;
    }

    private function normalizeText(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding === false) {
            return $value;
        }

        if ($encoding !== 'UTF-8') {
            $converted = mb_convert_encoding($value, 'UTF-8', $encoding);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $value;
    }

    private function fillCoberturasFromMap(array $data, array $categoryIndex): array
    {
        if (empty($data['credito_s_multa'])) {
            return $data;
        }

        $credito = $this->normalizeDecimal((string) $data['credito_s_multa']);
        if ($credito === null) {
            return $data;
        }

        $categoria = $this->detectCategoriaFromCredito($credito);
        if ($categoria === null || !isset($categoryIndex[$categoria])) {
            return $data;
        }

        $creditoCents = $this->toCents($credito);
        $premioCents = $this->encontrarPremioReferencia($creditoCents, $categoryIndex[$categoria]['premios']);
        if ($premioCents === null) {
            return $data;
        }

        $row = $categoryIndex[$categoria]['data'][(string) $premioCents] ?? null;
        if ($row === null || !empty($row['ambiguous'])) {
            return $data;
        }

        $data = $this->setCoberturaIfEmpty($data, 'coberturas_incendio', $row['incendio'] ?? null);
        $data = $this->setCoberturaIfEmpty($data, 'coberturas_incendio_conteudo', $row['incendio_conteudo'] ?? null);
        $data = $this->setCoberturaIfEmpty($data, 'coberturas_vendaval', $row['vendaval'] ?? null);
        $data = $this->setCoberturaIfEmpty($data, 'coberturas_perda_aluguel', $row['perda_aluguel'] ?? null);
        $data = $this->setCoberturaIfEmpty($data, 'coberturas_danos_eletricos', $row['danos_eletricos'] ?? null);
        $data = $this->setCoberturaIfEmpty($data, 'coberturas_responsabilidade_civil', $row['responsabilidade_civil'] ?? null);

        return $data;
    }

    private function setCoberturaIfEmpty(array $data, string $key, $value): array
    {
        if ($value === null || $value === '') {
            return $data;
        }
        if (!isset($data[$key]) || $data[$key] === '') {
            $data[$key] = (string) $value;
        }

        return $data;
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

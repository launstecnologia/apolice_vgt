<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Apolice;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ApoliceImportService
{
    private Apolice $apoliceModel;
    private HtmlTemplateService $templateService;
    private PdfFromHtmlService $pdfService;
    private string $storagePath;
    private string $templateHtmlPath;
    private string $logoPath;
    private array $seguroMap;

    private array $headerAliases = [
        'cpf_cnpj' => 'cpf_cnpj_locatario',
        'cpf' => 'cpf_cnpj_locatario',
        'cnpj' => 'cpf_cnpj_locatario',
        'cpf_cnpj_locatario' => 'cpf_cnpj_locatario',
        'inquilino_doc' => 'cpf_cnpj_locatario',
        'inquilino_nome' => 'segurado_nome',
        'inquilino_pessoa' => 'segurado_tipo',
        'endereco' => 'endereco',
        'numero' => 'numero',
        'complemento' => 'complemento',
        'bairro' => 'segurado_bairro',
        'cep' => 'segurado_cep',
        'cidade' => 'segurado_cidade',
        'estado' => 'segurado_uf',
        'nome' => 'segurado_nome',
        'segurado_nome' => 'segurado_nome',
        'credito_s_multa' => 'credito_s_multa',
        'incendio' => 'coberturas_incendio',
        'incendio_conteudo' => 'coberturas_incendio_conteudo',
        'vendaval' => 'coberturas_vendaval',
        'perda_aluguel' => 'coberturas_perda_aluguel',
        'danos_eletricos' => 'coberturas_danos_eletricos',
        'dano_eletrico' => 'coberturas_danos_eletricos',
    ];

    public function __construct(
        Apolice $apoliceModel,
        HtmlTemplateService $templateService,
        PdfFromHtmlService $pdfService,
        string $storagePath,
        string $templateHtmlPath,
        string $logoPath,
        array $seguroMap = []
    ) {
        $this->apoliceModel = $apoliceModel;
        $this->templateService = $templateService;
        $this->pdfService = $pdfService;
        $this->storagePath = $storagePath;
        $this->templateHtmlPath = $templateHtmlPath;
        $this->logoPath = $logoPath;
        $this->seguroMap = $seguroMap;
    }

    public function import(string $filePath, int $imobiliariaId, string $vigenciaInicio): array
    {
        if (!class_exists(IOFactory::class)) {
            $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            }
        }
        if (!class_exists(IOFactory::class)) {
            throw new \RuntimeException('PhpSpreadsheet não carregado. Verifique vendor/autoload.php.');
        }

        $sheet = $this->loadSheet($filePath);
        $headerMap = $this->detectHeaderMap($sheet);
        $categoryIndex = $this->buildCategoryIndex($this->seguroMap);
        $vigenciaInicioNormalized = $this->normalizeDate($vigenciaInicio);

        $maxRow = $sheet->getHighestRow();
        $imported = 0;
        $errors = [];

        for ($row = 2; $row <= $maxRow; $row++) {
            $data = $this->extractRow($sheet, $headerMap, $row);
            if ($this->isRowEmpty($data)) {
                continue;
            }

            if (!empty($categoryIndex)) {
                $data = $this->fillCoberturasFromMap($data, $categoryIndex);
            }

            $cpf = $data['cpf_cnpj_locatario'] ?? '';
            $endereco = $this->buildEndereco($data);
            $data['endereco'] = $endereco;
            $dataApolice = $vigenciaInicioNormalized;

            if ($cpf === '' || $dataApolice === '') {
                $errors[] = ['row' => $row, 'message' => 'CPF/CNPJ ou data da apólice ausente'];
                continue;
            }

            $pdfFile = $this->generatePdf($data, $dataApolice);
            $hash = hash('sha256', $imobiliariaId . '|' . $cpf . '|' . $dataApolice . '|' . $endereco);

            $this->apoliceModel->create([
                'imobiliaria_id' => $imobiliariaId,
                'cpf_cnpj_locatario' => $cpf,
                'endereco' => $endereco,
                'data_apolice' => $dataApolice,
                'arquivo_pdf' => $pdfFile,
                'hash_apolice' => $hash,
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'errors' => $errors];
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

    private function extractRow(Worksheet $sheet, array $headerMap, int $row): array
    {
        $data = [];
        foreach ($headerMap as $key => $col) {
            $value = (string) $sheet->getCell($col . $row)->getValue();
            $data[$key] = trim($this->normalizeText($value));
        }

        if (isset($data['cpf_cnpj_locatario'])) {
            $data['cpf_cnpj_locatario'] = $this->normalizeCpfCnpj($data['cpf_cnpj_locatario']);
        }

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

    private function normalizeText(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $converted = mb_convert_encoding($value, 'UTF-8', $encoding);
            if ($converted !== false) {
                return $converted;
            }
        }
        return $value;
    }

    private function normalizeCpfCnpj(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        return $digits ?: $value;
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('d/m/Y', $value) ?: \DateTime::createFromFormat('Y-m-d', $value);
        if (!$dt instanceof \DateTime) {
            return '';
        }
        return $dt->format('Y-m-d');
    }

    private function buildEndereco(array $data): string
    {
        $endereco = trim((string) ($data['endereco'] ?? ''));
        $numero = trim((string) ($data['numero'] ?? ''));
        $complemento = trim((string) ($data['complemento'] ?? ''));

        $parts = [];
        if ($endereco !== '') {
            $parts[] = $endereco;
        }
        if ($numero !== '') {
            $parts[] = $numero;
        }
        $joined = implode(', ', $parts);
        if ($complemento !== '') {
            $joined .= ' ' . $complemento;
        }

        return $joined;
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

        foreach ($map as $premio => $row) {
            if (!is_array($row)) {
                continue;
            }

            $categoria = $row['categoria'] ?? $row['tipo'] ?? null;
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
            $index[$categoria]['data'][(string) $cents] = $row;
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
        return (bool) preg_match('/^\d+\.\d{2}$/', $value);
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

    private function generatePdf(array $row, string $dataApolice): string
    {
        $pdfDir = rtrim($this->storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0775, true);
        }

        $safeId = bin2hex(random_bytes(6));
        $htmlPath = $pdfDir . DIRECTORY_SEPARATOR . 'tmp_' . $safeId . '.html';
        $pdfPath = $pdfDir . DIRECTORY_SEPARATOR . 'apolice_' . $safeId . '.pdf';

        $vigenciaFim = $this->calculateEndDate($dataApolice);
        $dataProposta = $this->calculateProposalDate($dataApolice);

        $placeholders = [
            'LOGO_MAPFRE' => $this->buildLogoDataUri($this->logoPath),
            'NOME_SEGURADO' => $row['segurado_nome'] ?? '',
            'TIPO_PESSOA' => $row['segurado_tipo'] ?? '',
            'CPF_CNPJ' => $row['cpf_cnpj_locatario'] ?? '',
            'ENDERECO_SEGURADO' => $row['endereco'] ?? '',
            'BAIRRO_SEGURADO' => $row['segurado_bairro'] ?? '',
            'CEP_SEGURADO' => $row['segurado_cep'] ?? '',
            'CIDADE_SEGURADO' => $row['segurado_cidade'] ?? '',
            'UF_SEGURADO' => $row['segurado_uf'] ?? '',
            'VIGENCIA_INICIO' => $this->formatDateBr($dataApolice),
            'VIGENCIA_FIM' => $this->formatDateBr($vigenciaFim),
            'DATA_PROPOSTA' => $this->formatDateBr($dataProposta),
            'VALOR_INCENDIO' => $this->formatMoney($row['coberturas_incendio'] ?? ''),
            'VALOR_INCENDIO_CONTEUDO' => $this->formatMoney($row['coberturas_incendio_conteudo'] ?? ''),
            'VALOR_VENDAVAL' => $this->formatMoney($row['coberturas_vendaval'] ?? ''),
            'VALOR_PERDA_ALUGUEL' => $this->formatMoney($row['coberturas_perda_aluguel'] ?? ''),
            'VALOR_DANOS_ELETRICOS' => $this->formatMoney($row['coberturas_danos_eletricos'] ?? ''),
        ];

        $this->templateService->render($this->templateHtmlPath, $htmlPath, $placeholders);
        $this->pdfService->convert($htmlPath, $pdfPath);
        @unlink($htmlPath);

        return $pdfPath;
    }

    private function buildLogoDataUri(string $path): string
    {
        if ($path === '' || !file_exists($path)) {
            return '';
        }
        $data = file_get_contents($path);
        if ($data === false) {
            return '';
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $mime = 'image/jpeg';
        } elseif ($ext === 'gif') {
            $mime = 'image/gif';
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    private function formatDateBr(string $value): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        if ($dt instanceof \DateTime) {
            return $dt->format('d/m/Y');
        }
        return $value;
    }

    private function formatMoney($value): string
    {
        if ($value === '' || $value === null) {
            return '';
        }
        if (is_numeric($value)) {
            return number_format((float) $value, 2, ',', '.');
        }
        return (string) $value;
    }

    private function calculateProposalDate(string $vigenciaInicio): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $vigenciaInicio);
        if (!$dt instanceof \DateTime) {
            return $vigenciaInicio;
        }
        $dt->modify('first day of next month');
        return $dt->format('Y-m-d');
    }

    private function calculateEndDate(string $vigenciaInicio): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $vigenciaInicio);
        if (!$dt instanceof \DateTime) {
            return $vigenciaInicio;
        }
        $dt->modify('+1 month');
        $dt->modify('-1 day');
        return $dt->format('Y-m-d');
    }
}

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

    private array $headerAliases = [
        'cpf_cnpj' => 'cpf_cnpj_locatario',
        'cpf' => 'cpf_cnpj_locatario',
        'cnpj' => 'cpf_cnpj_locatario',
        'cpf_cnpj_locatario' => 'cpf_cnpj_locatario',
        'endereco' => 'endereco',
        'data_apolice' => 'data_apolice',
        'data' => 'data_apolice',
        'nome' => 'segurado_nome',
        'segurado_nome' => 'segurado_nome',
    ];

    public function __construct(
        Apolice $apoliceModel,
        HtmlTemplateService $templateService,
        PdfFromHtmlService $pdfService,
        string $storagePath,
        string $templateHtmlPath,
        string $logoPath
    ) {
        $this->apoliceModel = $apoliceModel;
        $this->templateService = $templateService;
        $this->pdfService = $pdfService;
        $this->storagePath = $storagePath;
        $this->templateHtmlPath = $templateHtmlPath;
        $this->logoPath = $logoPath;
    }

    public function import(string $filePath, int $imobiliariaId): array
    {
        $sheet = $this->loadSheet($filePath);
        $headerMap = $this->detectHeaderMap($sheet);

        $maxRow = $sheet->getHighestRow();
        $imported = 0;
        $errors = [];

        for ($row = 2; $row <= $maxRow; $row++) {
            $data = $this->extractRow($sheet, $headerMap, $row);
            if ($this->isRowEmpty($data)) {
                continue;
            }

            $cpf = $data['cpf_cnpj_locatario'] ?? '';
            $endereco = $data['endereco'] ?? '';
            $dataApolice = $this->normalizeDate($data['data_apolice'] ?? '');

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

    private function generatePdf(array $row, string $dataApolice): string
    {
        $pdfDir = rtrim($this->storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pdf';
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0775, true);
        }

        $safeId = bin2hex(random_bytes(6));
        $htmlPath = $pdfDir . DIRECTORY_SEPARATOR . 'tmp_' . $safeId . '.html';
        $pdfPath = $pdfDir . DIRECTORY_SEPARATOR . 'apolice_' . $safeId . '.pdf';

        $placeholders = [
            'LOGO_MAPFRE' => $this->buildLogoDataUri($this->logoPath),
            'NOME_SEGURADO' => $row['segurado_nome'] ?? '',
            'CPF_CNPJ' => $row['cpf_cnpj_locatario'] ?? '',
            'ENDERECO_SEGURADO' => $row['endereco'] ?? '',
            'CIDADE_SEGURADO' => $row['cidade'] ?? '',
            'UF_SEGURADO' => $row['uf'] ?? '',
            'VIGENCIA_INICIO' => $this->formatDateBr($dataApolice),
            'VIGENCIA_FIM' => $this->formatDateBr($dataApolice),
            'DATA_PROPOSTA' => $this->formatDateBr($dataApolice),
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
}

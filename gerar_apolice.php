<?php

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo 'Dependências não instaladas. Execute "composer install".';
    exit;
}
require $autoload;

use App\Services\ApoliceTemplateService;
use App\Services\HtmlTemplateService;
use App\Services\PdfConverterService;
use App\Services\PdfFromHtmlService;
use App\Services\WordPlaceholderService;

if (!class_exists(ApoliceTemplateService::class)) {
    spl_autoload_register(function ($class) {
        $prefix = 'App\\Services\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/services/' . $relative . '.php';
        if (file_exists($path)) {
            require $path;
        }
    });
}

$config = require __DIR__ . '/config.php';
$templatePath = $config['template_path'] ?? (__DIR__ . '/templates/apolice_base.docx');
$templateHtmlPath = $config['template_html_path'] ?? '';
$docxDir = $config['storage_docx_path'] ?? (__DIR__ . '/storage/docx');
$htmlDir = $config['storage_html_path'] ?? (__DIR__ . '/storage/html');
$pdfDir = $config['storage_pdf_path'] ?? (__DIR__ . '/storage/pdf');
$libreOffice = $config['libreoffice_path'] ?? '';
$engine = $config['pdf_engine'] ?? 'auto';

$payload = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $payload = json_decode($raw, true) ?: [];
    } else {
        $payload = $_POST;
    }
}

$placeholders = buildPlaceholders($payload);
$placeholders['LOGO_MAPFRE'] = buildLogoDataUri($config['logo_path'] ?? '');
$dates = buildDates($payload);
$placeholders = array_merge($placeholders, $dates);

if (!empty($payload['locatario_id'])) {
    persistLocatarioDates(
        $config['storage_path'] ?? (__DIR__ . '/storage'),
        (string) $payload['locatario_id'],
        $payload
    );
}

try {
    if ($engine === 'html' || ($engine === 'auto' && $templateHtmlPath !== '' && file_exists($templateHtmlPath))) {
        $htmlPath = rtrim($htmlDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'apolice_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.html';
        $templateService = new HtmlTemplateService();
        $templateService->render($templateHtmlPath, $htmlPath, $placeholders);

        $pdfPath = rtrim($pdfDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . pathinfo($htmlPath, PATHINFO_FILENAME) . '.pdf';
        $pdfService = new PdfFromHtmlService();
        $pdfService->convert($htmlPath, $pdfPath);
    } else {
        $service = new ApoliceTemplateService(
            new WordPlaceholderService(),
            new PdfConverterService($libreOffice),
            $templatePath,
            $docxDir,
            $pdfDir
        );
        $pdfPath = $service->gerarPdf($placeholders);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erro ao gerar apólice: ' . $e->getMessage();
    exit;
}

$filename = 'apolice_' . date('Ymd_His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($pdfPath));
readfile($pdfPath);
exit;

function buildPlaceholders(array $data): array
{
    $segurado = $data['segurado'] ?? [];
    $risco = $data['risco'] ?? [];
    $coberturas = $data['coberturas'] ?? [];

    return [
        'NOME_SEGURADO' => $segurado['nome'] ?? '',
        'TIPO_PESSOA' => $segurado['tipo'] ?? '',
        'CPF_CNPJ' => $segurado['cpf_cnpj'] ?? '',
        'ENDERECO_SEGURADO' => $segurado['endereco'] ?? '',
        'BAIRRO_SEGURADO' => $segurado['bairro'] ?? '',
        'CEP_SEGURADO' => $segurado['cep'] ?? '',
        'CIDADE_SEGURADO' => $segurado['cidade'] ?? '',
        'UF_SEGURADO' => $segurado['uf'] ?? '',

        'ENDERECO_RISCO' => $risco['endereco'] ?? '',
        'NUMERO_RISCO' => $risco['numero'] ?? '',
        'BAIRRO_RISCO' => $risco['bairro'] ?? '',
        'CEP_RISCO' => $risco['cep'] ?? '',
        'CIDADE_RISCO' => $risco['cidade'] ?? '',
        'UF_RISCO' => $risco['uf'] ?? '',
        'QUESTIONARIO_RESPOSTAS' => $risco['questionario'] ?? '',

        'VALOR_INCENDIO' => formatMoney($coberturas['incendio'] ?? ''),
        'VALOR_INCENDIO_CONTEUDO' => formatMoney($coberturas['incendio_conteudo'] ?? ''),
        'VALOR_VENDAVAL' => formatMoney($coberturas['vendaval'] ?? ''),
        'VALOR_PERDA_ALUGUEL' => formatMoney($coberturas['perda_aluguel'] ?? ''),
        'VALOR_DANOS_ELETRICOS' => formatMoney($coberturas['danos_eletricos'] ?? ''),
        'VALOR_RESPONSABILIDADE_CIVIL' => formatMoney($coberturas['responsabilidade_civil'] ?? ''),
    ];
}

function buildDates(array $data): array
{
    $vigenciaInicioRaw = (string) ($data['vigencia_inicio'] ?? '');
    $vigenciaFimRaw = (string) ($data['vigencia_fim'] ?? '');

    $vigenciaInicio = formatDateBr($vigenciaInicioRaw);
    $vigenciaFim = formatDateBr($vigenciaFimRaw);

    if ($vigenciaInicio !== '' && $vigenciaFim === '') {
        $vigenciaFim = formatDateBr(calculateEndDate($vigenciaInicioRaw));
    }

    $dataProposta = '';
    if ($vigenciaInicioRaw !== '') {
        $dataProposta = formatDateBr(calculateProposalDate($vigenciaInicioRaw));
    }

    return [
        'VIGENCIA_INICIO' => $vigenciaInicio,
        'VIGENCIA_FIM' => $vigenciaFim,
        'DATA_PROPOSTA' => $dataProposta,
    ];
}

function formatDateBr(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $dt = \DateTime::createFromFormat('Y-m-d', $value);
    if ($dt instanceof \DateTime) {
        return $dt->format('d/m/Y');
    }

    return $value;
}

function calculateProposalDate(string $vigenciaInicio): string
{
    $dt = \DateTime::createFromFormat('Y-m-d', $vigenciaInicio);
    if (!$dt instanceof \DateTime) {
        return '';
    }

    $dt->modify('first day of next month');
    return $dt->format('Y-m-d');
}

function calculateEndDate(string $vigenciaInicio): string
{
    $dt = \DateTime::createFromFormat('Y-m-d', $vigenciaInicio);
    if (!$dt instanceof \DateTime) {
        return '';
    }

    $dt->modify('+1 month');
    $dt->modify('-1 day');
    return $dt->format('Y-m-d');
}

function persistLocatarioDates(string $storagePath, string $locatarioId, array $payload): void
{
    $path = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'locatarios.json';
    if (!file_exists($path)) {
        return;
    }

    $content = file_get_contents($path);
    $data = json_decode($content ?: '[]', true);
    if (!is_array($data)) {
        return;
    }

    $updated = false;
    foreach ($data as &$locatario) {
        if (!is_array($locatario)) {
            continue;
        }
        $id = (string) ($locatario['id'] ?? '');
        if ($id !== $locatarioId) {
            continue;
        }
        $vigenciaInicio = (string) ($payload['vigencia_inicio'] ?? '');
        $vigenciaFim = (string) ($payload['vigencia_fim'] ?? '');
        $dataProposta = '';
        if ($vigenciaInicio !== '') {
            $dataProposta = calculateProposalDate($vigenciaInicio);
        }
        if ($vigenciaInicio !== '' && $vigenciaFim === '') {
            $vigenciaFim = calculateEndDate($vigenciaInicio);
        }

        $locatario['vigencia_inicio'] = $vigenciaInicio;
        $locatario['vigencia_fim'] = $vigenciaFim;
        $locatario['data_proposta'] = $dataProposta;
        $updated = true;
        break;
    }
    unset($locatario);

    if ($updated) {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function formatMoney($value): string
{
    if ($value === '' || $value === null) {
        return '';
    }

    if (is_numeric($value)) {
        return number_format((float) $value, 2, ',', '.');
    }

    return (string) $value;
}

function buildLogoDataUri(string $path): string
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

<?php

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo 'Dependências não instaladas. Execute "composer install" no servidor.';
    exit;
}

require $autoload;

use App\Services\ExcelProcessor;
use App\Services\SeguroJsonRepository;

if (!class_exists(PdfSeguroParser::class)) {
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

$basePath = __DIR__;
$config = require $basePath . '/config.php';
$storagePath = $config['storage_path'] ?? ($basePath . '/storage');
$baseUrl = $config['base_url'] ?? '';
if ($baseUrl === '') {
    $baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}
$baseUrl = $baseUrl === '/' ? '' : $baseUrl;

if (!is_dir($storagePath)) {
    mkdir($storagePath, 0775, true);
}

$excelDir = $storagePath . '/uploads/excel';
$jsonPath = $storagePath . '/data/seguro_map.json';

if (!is_dir($excelDir)) {
    mkdir($excelDir, 0775, true);
}
if (!is_dir(dirname($jsonPath))) {
    mkdir(dirname($jsonPath), 0775, true);
}

$alerts = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}

$excelFile = $_FILES['excel'] ?? null;

if ($excelFile === null || $excelFile['error'] !== UPLOAD_ERR_OK) {
    $alerts[] = ['type' => 'error', 'message' => 'Envie um arquivo Excel válido.'];
    $_SESSION['alerts'] = $alerts;
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}

$repo = new SeguroJsonRepository($jsonPath);
$map = $repo->load();
if (empty($map)) {
    $alerts[] = ['type' => 'error', 'message' => 'Base JSON inexistente. Verifique o arquivo seguro_map.json.'];
    $_SESSION['alerts'] = $alerts;
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}

$excelName = uniqid('entrada_', true) . '.xlsx';
$excelPath = $excelDir . '/' . $excelName;

if (!move_uploaded_file($excelFile['tmp_name'], $excelPath)) {
    $alerts[] = ['type' => 'error', 'message' => 'Falha ao salvar o Excel enviado.'];
    $_SESSION['alerts'] = $alerts;
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}

$outputName = 'resultado_' . date('Ymd_His') . '.xlsx';
$outputPath = $excelDir . '/' . $outputName;

try {
    $processor = new ExcelProcessor();
    $processor->process($excelPath, $outputPath, $map);
} catch (Throwable $e) {
    $alerts[] = ['type' => 'error', 'message' => 'Erro ao processar o Excel: ' . $e->getMessage()];
    $_SESSION['alerts'] = $alerts;
    header('Location: ' . $baseUrl . '/index.php');
    exit;
}

$_SESSION['alerts'] = $alerts;

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $outputName . '"');
header('Content-Length: ' . filesize($outputPath));
readfile($outputPath);
exit;

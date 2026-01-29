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

use App\Services\LocatariosImportService;
use App\Services\SeguroJsonRepository;

if (!class_exists(LocatariosImportService::class)) {
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

$alerts = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $baseUrl . '/locatarios.php');
    exit;
}

$file = $_FILES['locatarios'] ?? null;
if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
    $alerts[] = ['type' => 'error', 'message' => 'Envie uma planilha válida.'];
    $_SESSION['alerts'] = $alerts;
    header('Location: ' . $baseUrl . '/locatarios.php');
    exit;
}

$tmpPath = $file['tmp_name'];
$seguroMapPath = $storagePath . '/data/seguro_map.json';
$seguroRepo = new SeguroJsonRepository($seguroMapPath);
$seguroMap = $seguroRepo->load();
$importer = new LocatariosImportService($seguroMap);

try {
    $result = $importer->import($tmpPath);
} catch (Throwable $e) {
    $alerts[] = ['type' => 'error', 'message' => 'Erro ao importar: ' . $e->getMessage()];
    $_SESSION['alerts'] = $alerts;
    header('Location: ' . $baseUrl . '/locatarios.php');
    exit;
}

$locatariosPath = $storagePath . '/data/locatarios.json';
if (!is_dir(dirname($locatariosPath))) {
    mkdir(dirname($locatariosPath), 0775, true);
}
file_put_contents($locatariosPath, json_encode($result['locatarios'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$errors = $result['errors'];
if (!empty($errors)) {
    $alerts[] = [
        'type' => 'error',
        'message' => 'Importado com pendências. Linhas com campos faltando: ' . count($errors),
    ];
} else {
    $alerts[] = ['type' => 'success', 'message' => 'Locatários importados com sucesso.'];
}

$_SESSION['alerts'] = $alerts;
$_SESSION['import_errors'] = $errors;

header('Location: ' . $baseUrl . '/locatarios.php');
exit;

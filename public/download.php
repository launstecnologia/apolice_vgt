<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/autoload.php';

use App\Models\Apolice;
use App\Services\AuthService;
use App\Services\Logger;

$config = require __DIR__ . '/../config/app.php';
$logger = new Logger($config['storage_path']);
$auth = new AuthService(db(), $logger);
$auth->requireAuth();

$user = $auth->user();
$apoliceId = (int) ($_GET['id'] ?? 0);
if ($apoliceId <= 0) {
    http_response_code(400);
    echo 'Parâmetro inválido.';
    exit;
}

$apoliceModel = new Apolice(db());
$apolice = $apoliceModel->findById($apoliceId);
if (!$apolice) {
    http_response_code(404);
    echo 'Apólice não encontrada.';
    exit;
}

if ($user['tipo'] === 'imobiliaria' && (int) $apolice['imobiliaria_id'] !== (int) $user['imobiliaria_id']) {
    $logger->security('Tentativa de acesso indevido PDF ID=' . $apoliceId . ' user=' . $user['email']);
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$path = $apolice['arquivo_pdf'] ?? '';
if ($path === '' || !file_exists($path)) {
    http_response_code(404);
    echo 'Arquivo não disponível.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="apolice_' . $apoliceId . '.pdf"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

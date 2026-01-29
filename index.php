<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/autoload.php';

use App\Services\AuthService;
use App\Services\Logger;

$config = require __DIR__ . '/config/app.php';
$logger = new Logger($config['storage_path']);
$auth = new AuthService(db(), $logger);

$user = $auth->user();
if (!$user) {
    header('Location: /login.php');
    exit;
}

if ($user['tipo'] === 'admin') {
    header('Location: /admin.php');
    exit;
}

header('Location: /imobiliaria.php');
exit;

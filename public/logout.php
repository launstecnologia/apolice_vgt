<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/autoload.php';

use App\Services\AuthService;
use App\Services\Logger;

$config = require __DIR__ . '/../config/app.php';
$logger = new Logger($config['storage_path']);
$auth = new AuthService(db(), $logger);

$auth->logout();
header('Location: /login.php');
exit;

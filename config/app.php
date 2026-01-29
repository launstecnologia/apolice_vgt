<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

return [
    'app_env' => env('APP_ENV', 'production'),
    'app_key' => env('APP_KEY', ''),
    'storage_path' => env('STORAGE_PATH', __DIR__ . '/../storage'),
    'template_html_path' => __DIR__ . '/../2cad4251-fd2d-4d06-a0c1-3c29c689843d.html',
    'logo_path' => __DIR__ . '/../public/logo_mafre.jpg',
];

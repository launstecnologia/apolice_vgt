<?php

declare(strict_types=1);

namespace App\Services;

class Logger
{
    private string $logDir;

    public function __construct(string $storagePath)
    {
        $candidate = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($candidate)) {
            @mkdir($candidate, 0775, true);
        }

        if (is_dir($candidate) && is_writable($candidate)) {
            $this->logDir = $candidate;
        } else {
            $this->logDir = sys_get_temp_dir();
            error_log('Logger fallback to temp dir: ' . $this->logDir);
        }
    }

    public function security(string $message): void
    {
        $this->write('security.log', $message);
    }

    public function audit(string $message): void
    {
        $this->write('audit.log', $message);
    }

    private function write(string $file, string $message): void
    {
        $path = $this->logDir . DIRECTORY_SEPARATOR . $file;
        $line = sprintf("[%s] %s%s", date('Y-m-d H:i:s'), $message, PHP_EOL);
        $ok = @file_put_contents($path, $line, FILE_APPEND);
        if ($ok === false) {
            error_log('Falha ao gravar log em ' . $path);
        }
    }
}

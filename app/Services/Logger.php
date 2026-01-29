<?php

declare(strict_types=1);

namespace App\Services;

class Logger
{
    private string $logDir;

    public function __construct(string $storagePath)
    {
        $this->logDir = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
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
        file_put_contents($path, $line, FILE_APPEND);
    }
}

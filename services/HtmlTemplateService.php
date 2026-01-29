<?php

namespace App\Services;

class HtmlTemplateService
{
    public function render(string $templatePath, string $outputPath, array $values): void
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Template HTML não encontrado.');
        }

        $html = file_get_contents($templatePath);
        if ($html === false) {
            throw new \RuntimeException('Falha ao ler o template HTML.');
        }

        foreach ($values as $key => $value) {
            $html = str_replace('{{' . $key . '}}', $this->sanitizeValue($value), $html);
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($outputPath, $html);
    }

    private function sanitizeValue($value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

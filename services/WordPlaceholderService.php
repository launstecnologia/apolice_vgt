<?php

namespace App\Services;

use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;

class WordPlaceholderService
{
    public function apply(string $templatePath, string $outputPath, array $values): void
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Template DOCX não encontrado.');
        }

        if (!copy($templatePath, $outputPath)) {
            throw new \RuntimeException('Falha ao copiar o template DOCX.');
        }

        $this->normalizePlaceholders($outputPath, array_keys($values));

        $template = new TemplateProcessor($outputPath);
        foreach ($values as $key => $value) {
            $template->setValue($key, $this->sanitizeValue($value));
        }

        $template->saveAs($outputPath);
    }

    private function normalizePlaceholders(string $docxPath, array $keys): void
    {
        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new \RuntimeException('Falha ao abrir o DOCX para normalizar placeholders.');
        }

        $targets = $this->collectXmlTargets($zip);
        foreach ($targets as $path) {
            $content = $zip->getFromName($path);
            if ($content === false) {
                continue;
            }
            foreach ($keys as $key) {
                $content = str_replace('{{' . $key . '}}', '${' . $key . '}', $content);
            }
            $zip->addFromString($path, $content);
        }

        $zip->close();
    }

    private function collectXmlTargets(ZipArchive $zip): array
    {
        $targets = ['word/document.xml'];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/^word\\/(header|footer)\\d+\\.xml$/', $name)) {
                $targets[] = $name;
            }
        }

        return $targets;
    }

    private function sanitizeValue($value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

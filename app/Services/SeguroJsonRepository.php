<?php

declare(strict_types=1);

namespace App\Services;

class SeguroJsonRepository
{
    private string $jsonPath;

    public function __construct(string $jsonPath)
    {
        $this->jsonPath = $jsonPath;
    }

    public function save(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            if (!isset($row['premio_mensal'])) {
                continue;
            }

            $key = $row['premio_mensal'];

            if (isset($map[$key])) {
                $map[$key] = ['ambiguous' => true];
                continue;
            }

            $map[$key] = [
                'tipo' => $row['tipo'] ?? '',
                'incendio' => $row['incendio'] ?? null,
                'incendio_conteudo' => $row['incendio_conteudo'] ?? null,
                'vendaval' => $row['vendaval'] ?? null,
                'perda_aluguel' => $row['perda_aluguel'] ?? null,
            ];
        }

        $this->writeJson($map);

        return $map;
    }

    public function load(): array
    {
        if (!file_exists($this->jsonPath)) {
            return [];
        }

        $content = file_get_contents($this->jsonPath);
        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        return $this->normalizeMap($data);
    }

    private function normalizeMap(array $data): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $normalizedKey = $this->normalizePremioKey($key);
            if ($normalizedKey !== null && is_array($value)) {
                if (isset($flat[$normalizedKey])) {
                    $flat[$normalizedKey] = ['ambiguous' => true];
                    continue;
                }
                $flat[$normalizedKey] = $value;
                continue;
            }

            if (is_array($value)) {
                $tipo = $key;
                foreach ($value as $premio => $row) {
                    $normalizedPremio = $this->normalizePremioKey($premio);
                    if ($normalizedPremio === null || !is_array($row)) {
                        continue;
                    }
                    if (isset($flat[$normalizedPremio])) {
                        $flat[$normalizedPremio] = ['ambiguous' => true];
                        continue;
                    }
                    $flat[$normalizedPremio] = $row;
                    if (!isset($flat[$normalizedPremio]['categoria'])) {
                        $flat[$normalizedPremio]['tipo'] = $tipo;
                    }
                }
            }
        }

        return $flat;
    }

    private function normalizePremioKey(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(['R$', ' '], '', $value);

        if (strpos($value, ',') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            if (substr_count($value, '.') > 1) {
                $parts = explode('.', $value);
                $decimal = array_pop($parts);
                $value = implode('', $parts) . '.' . $decimal;
            }
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        if (strpos($value, '.') === false) {
            return $value . '.00';
        }

        [$int, $dec] = explode('.', $value, 2);
        $dec = substr($dec . '00', 0, 2);

        return $int . '.' . $dec;
    }

    private function writeJson(array $data): void
    {
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->jsonPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}

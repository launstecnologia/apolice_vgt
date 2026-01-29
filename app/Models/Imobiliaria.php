<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Imobiliaria
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(string $nome, string $cnpj, string $status): int
    {
        $stmt = $this->db->prepare('INSERT INTO imobiliarias (nome, cnpj, status, created_at) VALUES (:nome, :cnpj, :status, NOW())');
        $stmt->execute([
            'nome' => $nome,
            'cnpj' => $cnpj,
            'status' => $status,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM imobiliarias ORDER BY created_at DESC');
        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE imobiliarias SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM imobiliarias WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

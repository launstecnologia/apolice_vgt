<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Usuario
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(string $nome, string $email, string $senhaHash, string $tipo, ?int $imobiliariaId, string $status): int
    {
        $stmt = $this->db->prepare('INSERT INTO usuarios (nome, email, senha_hash, tipo, imobiliaria_id, status, created_at) VALUES (:nome, :email, :senha_hash, :tipo, :imobiliaria_id, :status, NOW())');
        $stmt->execute([
            'nome' => $nome,
            'email' => $email,
            'senha_hash' => $senhaHash,
            'tipo' => $tipo,
            'imobiliaria_id' => $imobiliariaId,
            'status' => $status,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM usuarios ORDER BY created_at DESC');
        return $stmt->fetchAll();
    }
}

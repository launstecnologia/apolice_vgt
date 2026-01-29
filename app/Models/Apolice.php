<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Apolice
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO apolices
                (imobiliaria_id, cpf_cnpj_locatario, endereco, data_apolice, arquivo_pdf, hash_apolice, created_at)
            VALUES
                (:imobiliaria_id, :cpf_cnpj_locatario, :endereco, :data_apolice, :arquivo_pdf, :hash_apolice, NOW())
        ');
        $stmt->execute([
            'imobiliaria_id' => $data['imobiliaria_id'],
            'cpf_cnpj_locatario' => $data['cpf_cnpj_locatario'],
            'endereco' => $data['endereco'],
            'data_apolice' => $data['data_apolice'],
            'arquivo_pdf' => $data['arquivo_pdf'],
            'hash_apolice' => $data['hash_apolice'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function searchAdmin(array $filters): array
    {
        $sql = 'SELECT a.*, i.nome AS imobiliaria_nome FROM apolices a JOIN imobiliarias i ON i.id = a.imobiliaria_id WHERE 1=1';
        $params = [];

        if (!empty($filters['imobiliaria_id'])) {
            $sql .= ' AND a.imobiliaria_id = :imobiliaria_id';
            $params['imobiliaria_id'] = $filters['imobiliaria_id'];
        }
        if (!empty($filters['cpf_cnpj_locatario'])) {
            $sql .= ' AND a.cpf_cnpj_locatario LIKE :cpf';
            $params['cpf'] = '%' . $filters['cpf_cnpj_locatario'] . '%';
        }
        if (!empty($filters['endereco'])) {
            $sql .= ' AND a.endereco LIKE :endereco';
            $params['endereco'] = '%' . $filters['endereco'] . '%';
        }
        if (!empty($filters['data_apolice'])) {
            $sql .= ' AND a.data_apolice = :data_apolice';
            $params['data_apolice'] = $filters['data_apolice'];
        }

        $sql .= ' ORDER BY a.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findByImobiliariaAndCpfDate(int $imobiliariaId, string $cpfCnpj, string $dataApolice): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM apolices
            WHERE imobiliaria_id = :imobiliaria_id
              AND cpf_cnpj_locatario = :cpf
              AND data_apolice = :data
            LIMIT 1
        ');
        $stmt->execute([
            'imobiliaria_id' => $imobiliariaId,
            'cpf' => $cpfCnpj,
            'data' => $dataApolice,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM apolices WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

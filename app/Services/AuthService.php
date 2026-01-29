<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class AuthService
{
    private PDO $db;
    private Logger $logger;

    public function __construct(PDO $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function attemptLogin(string $email, string $password): bool
    {
        $stmt = $this->db->prepare('SELECT * FROM usuarios WHERE email = :email AND status = "ativo" LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['senha_hash'])) {
            $this->logger->security('Falha de login para ' . $email . ' IP=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            return false;
        }

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'nome' => $user['nome'],
            'email' => $user['email'],
            'tipo' => $user['tipo'],
            'imobiliaria_id' => $user['imobiliaria_id'] ? (int) $user['imobiliaria_id'] : null,
        ];

        $this->logger->audit('Login realizado: ' . $email);
        return true;
    }

    public function logout(): void
    {
        $email = $_SESSION['user']['email'] ?? 'desconhecido';
        $this->logger->audit('Logout: ' . $email);
        $_SESSION = [];
        session_destroy();
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function requireAuth(): void
    {
        if (empty($_SESSION['user'])) {
            header('Location: /login.php');
            exit;
        }
    }

    public function requireRole(string $role): void
    {
        $user = $this->user();
        if (!$user || $user['tipo'] !== $role) {
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }
    }
}

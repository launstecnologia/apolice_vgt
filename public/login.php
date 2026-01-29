<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/autoload.php';

use App\Services\AuthService;
use App\Services\Logger;

$config = require __DIR__ . '/../config/app.php';
$logger = new Logger($config['storage_path']);
$auth = new AuthService(db(), $logger);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');
    if ($email === '' || $senha === '') {
        $error = 'Informe email e senha.';
    } else {
        try {
            if ($auth->attemptLogin($email, $senha)) {
                header('Location: /index.php');
                exit;
            }
            $error = 'Credenciais inválidas.';
        } catch (Throwable $e) {
            $error = 'Erro ao autenticar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Login - Apólices</title>
</head>
<body class="bg-slate-100">
    <div class="min-h-screen flex items-center justify-center p-6">
        <div class="w-full max-w-md rounded-xl bg-white p-8 shadow">
            <h1 class="text-xl font-semibold">Acesso ao sistema</h1>
            <p class="text-sm text-slate-500 mb-6">Informe suas credenciais.</p>
            <?php if ($error !== ''): ?>
                <div class="mb-4 rounded bg-red-50 px-4 py-2 text-sm text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium">Email</label>
                    <input type="email" name="email" required class="mt-1 w-full rounded border border-slate-200 p-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium">Senha</label>
                    <input type="password" name="senha" required class="mt-1 w-full rounded border border-slate-200 p-2 text-sm">
                </div>
                <button class="w-full rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Entrar</button>
            </form>
        </div>
    </div>
</body>
</html>

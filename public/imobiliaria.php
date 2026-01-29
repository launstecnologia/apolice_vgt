<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/autoload.php';

use App\Models\Apolice;
use App\Services\AuthService;
use App\Services\Logger;

$config = require __DIR__ . '/../config/app.php';
$logger = new Logger($config['storage_path']);
$auth = new AuthService(db(), $logger);
$auth->requireAuth();
$auth->requireRole('imobiliaria');

$user = $auth->user();
$apoliceModel = new Apolice(db());

$result = null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cpfCnpj = trim((string) ($_POST['cpf_cnpj'] ?? ''));
    $dataApolice = trim((string) ($_POST['data_apolice'] ?? ''));

    if ($cpfCnpj === '' || $dataApolice === '') {
        $message = 'Informe CPF/CNPJ e data da apólice.';
    } else {
        $cpfCnpj = preg_replace('/\D+/', '', $cpfCnpj);
        $apolice = $apoliceModel->findByImobiliariaAndCpfDate(
            (int) $user['imobiliaria_id'],
            $cpfCnpj,
            $dataApolice
        );

        if (!$apolice) {
            $logger->security('Busca sem resultado (imobiliaria): ' . $cpfCnpj . ' data=' . $dataApolice . ' IP=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            $message = 'Apólice não encontrada.';
        } else {
            $result = $apolice;
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
    <title>Consulta de Apólices</title>
</head>
<body class="bg-slate-100">
    <div class="max-w-2xl mx-auto p-6">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-semibold">Consulta de apólice</h1>
            <a class="text-blue-600 hover:underline text-sm" href="/logout.php">Sair</a>
        </div>

        <div class="rounded bg-white p-6 shadow">
            <?php if ($message !== ''): ?>
                <div class="mb-4 rounded bg-amber-50 px-4 py-2 text-sm text-amber-700"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium">CPF/CNPJ do locatário</label>
                    <input name="cpf_cnpj" type="text" required class="mt-1 w-full rounded border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium">Data da apólice</label>
                    <input name="data_apolice" type="date" required class="mt-1 w-full rounded border p-2 text-sm">
                </div>
                <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Buscar</button>
            </form>

            <?php if ($result): ?>
                <div class="mt-6 border-t pt-4">
                    <p class="text-sm text-slate-600">Apólice encontrada.</p>
                    <a class="text-blue-600 hover:underline text-sm" href="/download.php?id=<?php echo (int) $result['id']; ?>">Baixar PDF</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

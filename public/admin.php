<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/autoload.php';

use App\Models\Imobiliaria;
use App\Models\Usuario;
use App\Models\Apolice;
use App\Services\AuthService;
use App\Services\Logger;
use App\Services\ApoliceImportService;
use App\Services\HtmlTemplateService;
use App\Services\PdfFromHtmlService;
use PhpOffice\PhpSpreadsheet\IOFactory;

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

$config = require __DIR__ . '/../config/app.php';
$logger = new Logger($config['storage_path']);
$auth = new AuthService(db(), $logger);
$auth->requireAuth();
$auth->requireRole('admin');

$imobiliariaModel = new Imobiliaria(db());
$usuarioModel = new Usuario(db());
$apoliceModel = new Apolice(db());

$alerts = [];
function tailLog(string $path, int $maxLines = 120): string
{
    if (!file_exists($path)) {
        return '';
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return '';
    }
    $slice = array_slice($lines, -$maxLines);
    return implode(PHP_EOL, $slice);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_imobiliaria') {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $cnpj = trim((string) ($_POST['cnpj'] ?? ''));
        $status = $_POST['status'] ?? 'ativo';
        if ($nome !== '' && $cnpj !== '') {
            $imobiliariaModel->create($nome, $cnpj, $status);
            $logger->audit('Imobiliária criada: ' . $nome);
            $alerts[] = ['type' => 'success', 'message' => 'Imobiliária criada.'];
        } else {
            $alerts[] = ['type' => 'error', 'message' => 'Nome e CNPJ são obrigatórios.'];
        }
    }

    if ($action === 'create_user') {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');
        $tipo = $_POST['tipo'] ?? 'imobiliaria';
        $imobiliariaId = !empty($_POST['imobiliaria_id']) ? (int) $_POST['imobiliaria_id'] : null;
        if ($nome !== '' && $email !== '' && $senha !== '') {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $usuarioModel->create($nome, $email, $hash, $tipo, $imobiliariaId, 'ativo');
            $logger->audit('Usuário criado: ' . $email);
            $alerts[] = ['type' => 'success', 'message' => 'Usuário criado.'];
        } else {
            $alerts[] = ['type' => 'error', 'message' => 'Nome, email e senha são obrigatórios.'];
        }
    }

    if ($action === 'toggle_imobiliaria') {
        $id = (int) ($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'ativo';
        if ($id > 0) {
            $imobiliariaModel->updateStatus($id, $status);
            $logger->audit('Status imobiliária atualizado ID=' . $id . ' para ' . $status);
            $alerts[] = ['type' => 'success', 'message' => 'Status atualizado.'];
        }
    }

    if ($action === 'import_apolices') {
        $imobiliariaId = (int) ($_POST['imobiliaria_id'] ?? 0);
        $file = $_FILES['planilha'] ?? null;
        if ($imobiliariaId > 0 && $file && $file['error'] === UPLOAD_ERR_OK) {
            try {
                if (!class_exists(IOFactory::class)) {
                    throw new RuntimeException('Dependência PhpSpreadsheet não carregada. Confirme "composer install" na raiz do projeto.');
                }
                $importService = new ApoliceImportService(
                    $apoliceModel,
                    new HtmlTemplateService(),
                    new PdfFromHtmlService(),
                    $config['storage_path'],
                    $config['template_html_path'],
                    $config['logo_path']
                );
                $result = $importService->import($file['tmp_name'], $imobiliariaId);
                $alerts[] = ['type' => 'success', 'message' => 'Apólices importadas: ' . $result['imported']];
                if (!empty($result['errors'])) {
                    $alerts[] = ['type' => 'error', 'message' => 'Pendências: ' . count($result['errors'])];
                }
            } catch (Throwable $e) {
                $logger->security('Erro importando planilha: ' . $e->getMessage());
                $alerts[] = ['type' => 'error', 'message' => 'Erro ao importar planilha. Consulte o log de segurança.'];
            }
        } else {
            $alerts[] = ['type' => 'error', 'message' => 'Selecione a imobiliária e a planilha.'];
        }
    }
}

$imobiliarias = $imobiliariaModel->all();
$usuarios = $usuarioModel->all();

$filters = [
    'imobiliaria_id' => $_GET['imobiliaria_id'] ?? '',
    'cpf_cnpj_locatario' => $_GET['cpf_cnpj_locatario'] ?? '',
    'endereco' => $_GET['endereco'] ?? '',
    'data_apolice' => $_GET['data_apolice'] ?? '',
];
$apolices = $apoliceModel->searchAdmin($filters);
$auditLog = tailLog($config['storage_path'] . '/logs/audit.log');
$securityLog = tailLog($config['storage_path'] . '/logs/security.log');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Admin - Apólices</title>
</head>
<body class="bg-slate-100">
    <div class="max-w-6xl mx-auto p-6 space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Administração</h1>
            <div class="text-sm">
                <a class="text-blue-600 hover:underline" href="/logout.php">Sair</a>
            </div>
        </div>

        <?php foreach ($alerts as $alert): ?>
            <div class="rounded px-4 py-2 text-sm <?php echo $alert['type'] === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?>">
                <?php echo htmlspecialchars($alert['message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endforeach; ?>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded bg-white p-5 shadow">
                <h2 class="font-semibold mb-3">Cadastrar imobiliária</h2>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="action" value="create_imobiliaria">
                    <input type="text" name="nome" placeholder="Nome" class="w-full rounded border p-2 text-sm">
                    <input type="text" name="cnpj" placeholder="CNPJ" class="w-full rounded border p-2 text-sm">
                    <select name="status" class="w-full rounded border p-2 text-sm">
                        <option value="ativo">Ativo</option>
                        <option value="inativo">Inativo</option>
                    </select>
                    <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Salvar</button>
                </form>
            </div>

            <div class="rounded bg-white p-5 shadow">
                <h2 class="font-semibold mb-3">Cadastrar usuário</h2>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="action" value="create_user">
                    <input type="text" name="nome" placeholder="Nome" class="w-full rounded border p-2 text-sm">
                    <input type="email" name="email" placeholder="Email" class="w-full rounded border p-2 text-sm">
                    <input type="password" name="senha" placeholder="Senha" class="w-full rounded border p-2 text-sm">
                    <select name="tipo" class="w-full rounded border p-2 text-sm">
                        <option value="imobiliaria">Imobiliária</option>
                        <option value="admin">Admin</option>
                    </select>
                    <select name="imobiliaria_id" class="w-full rounded border p-2 text-sm">
                        <option value="">Vincular imobiliária (opcional)</option>
                        <?php foreach ($imobiliarias as $imobiliaria): ?>
                            <option value="<?php echo (int) $imobiliaria['id']; ?>"><?php echo htmlspecialchars($imobiliaria['nome'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Salvar</button>
                </form>
            </div>
        </div>

        <div class="rounded bg-white p-5 shadow">
            <h2 class="font-semibold mb-3">Importar apólices</h2>
            <form method="post" enctype="multipart/form-data" class="flex flex-col gap-3 md:flex-row md:items-center">
                <input type="hidden" name="action" value="import_apolices">
                <select name="imobiliaria_id" class="rounded border p-2 text-sm">
                    <option value="">Selecione a imobiliária</option>
                    <?php foreach ($imobiliarias as $imobiliaria): ?>
                        <option value="<?php echo (int) $imobiliaria['id']; ?>"><?php echo htmlspecialchars($imobiliaria['nome'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="file" name="planilha" accept=".csv,.xlsx,.xls" class="rounded border p-2 text-sm">
                <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Importar</button>
            </form>
        </div>

        <div class="rounded bg-white p-5 shadow">
            <h2 class="font-semibold mb-3">Apólices - filtros</h2>
            <form method="get" class="grid gap-3 md:grid-cols-4">
                <select name="imobiliaria_id" class="rounded border p-2 text-sm">
                    <option value="">Todas imobiliárias</option>
                    <?php foreach ($imobiliarias as $imobiliaria): ?>
                        <option value="<?php echo (int) $imobiliaria['id']; ?>" <?php echo ($filters['imobiliaria_id'] == $imobiliaria['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($imobiliaria['nome'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="cpf_cnpj_locatario" placeholder="CPF/CNPJ" value="<?php echo htmlspecialchars((string) $filters['cpf_cnpj_locatario'], ENT_QUOTES, 'UTF-8'); ?>" class="rounded border p-2 text-sm">
                <input type="text" name="endereco" placeholder="Endereço" value="<?php echo htmlspecialchars((string) $filters['endereco'], ENT_QUOTES, 'UTF-8'); ?>" class="rounded border p-2 text-sm">
                <input type="date" name="data_apolice" value="<?php echo htmlspecialchars((string) $filters['data_apolice'], ENT_QUOTES, 'UTF-8'); ?>" class="rounded border p-2 text-sm">
                <button class="md:col-span-4 rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filtrar</button>
            </form>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left p-2">Imobiliária</th>
                            <th class="text-left p-2">CPF/CNPJ</th>
                            <th class="text-left p-2">Endereço</th>
                            <th class="text-left p-2">Data</th>
                            <th class="text-left p-2">PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($apolices as $apolice): ?>
                        <tr class="border-b">
                            <td class="p-2"><?php echo htmlspecialchars($apolice['imobiliaria_nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($apolice['cpf_cnpj_locatario'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($apolice['endereco'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($apolice['data_apolice'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2"><a class="text-blue-600 hover:underline" href="/download.php?id=<?php echo (int) $apolice['id']; ?>">Baixar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded bg-white p-5 shadow">
            <h2 class="font-semibold mb-3">Usuários</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left p-2">Nome</th>
                            <th class="text-left p-2">Email</th>
                            <th class="text-left p-2">Tipo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usuarios as $usuario): ?>
                        <tr class="border-b">
                            <td class="p-2"><?php echo htmlspecialchars($usuario['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($usuario['tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded bg-white p-5 shadow">
            <h2 class="font-semibold mb-3">Imobiliárias</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left p-2">Nome</th>
                            <th class="text-left p-2">CNPJ</th>
                            <th class="text-left p-2">Status</th>
                            <th class="text-left p-2">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($imobiliarias as $imobiliaria): ?>
                        <tr class="border-b">
                            <td class="p-2"><?php echo htmlspecialchars($imobiliaria['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($imobiliaria['cnpj'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($imobiliaria['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-2">
                                <form method="post">
                                    <input type="hidden" name="action" value="toggle_imobiliaria">
                                    <input type="hidden" name="id" value="<?php echo (int) $imobiliaria['id']; ?>">
                                    <input type="hidden" name="status" value="<?php echo $imobiliaria['status'] === 'ativo' ? 'inativo' : 'ativo'; ?>">
                                    <button class="text-xs text-blue-600 hover:underline">
                                        <?php echo $imobiliaria['status'] === 'ativo' ? 'Desativar' : 'Ativar'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded bg-white p-5 shadow">
                <h2 class="font-semibold mb-3">Auditoria</h2>
                <pre class="max-h-64 overflow-auto rounded bg-slate-50 p-3 text-xs text-slate-700"><?php echo htmlspecialchars($auditLog, ENT_QUOTES, 'UTF-8'); ?></pre>
            </div>
            <div class="rounded bg-white p-5 shadow">
                <h2 class="font-semibold mb-3">Segurança</h2>
                <pre class="max-h-64 overflow-auto rounded bg-slate-50 p-3 text-xs text-slate-700"><?php echo htmlspecialchars($securityLog, ENT_QUOTES, 'UTF-8'); ?></pre>
            </div>
        </div>
    </div>
</body>
</html>

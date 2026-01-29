<?php

session_start();

$basePath = __DIR__;
$config = require $basePath . '/config.php';
$storagePath = $config['storage_path'] ?? ($basePath . '/storage');
$baseUrl = $config['base_url'] ?? '';
if ($baseUrl === '') {
    $baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}
$baseUrl = $baseUrl === '/' ? '' : $baseUrl;

$locatariosPath = $storagePath . '/data/locatarios.json';
$locatarios = [];

if (file_exists($locatariosPath)) {
    $content = file_get_contents($locatariosPath);
    $decoded = json_decode($content ?: '[]', true);
    if (is_array($decoded)) {
        $locatarios = $decoded;
    }
}

$alerts = $_SESSION['alerts'] ?? [];
$importErrors = $_SESSION['import_errors'] ?? [];
unset($_SESSION['alerts'], $_SESSION['import_errors']);

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $searchDigits = preg_replace('/\D+/', '', $search);
    $locatarios = array_values(array_filter($locatarios, function (array $locatario) use ($searchDigits) {
        $cpf = $locatario['segurado']['cpf_cnpj'] ?? '';
        $cpfDigits = preg_replace('/\D+/', '', $cpf);
        return $searchDigits !== '' && strpos($cpfDigits, $searchDigits) !== false;
    }));
}

if (empty($alerts) && empty($locatarios)) {
    $alerts[] = ['type' => 'error', 'message' => 'Nenhum locatário encontrado em locatarios.json.'];
}
if (empty($locatarios)) {
    $alerts[] = ['type' => 'error', 'message' => 'Nenhum locatário encontrado em locatarios.json.'];
}

$content = '';
ob_start();
?>
<div class="flex justify-center">
    <div class="w-full max-w-5xl rounded-xl bg-white p-8 shadow">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold">Locatários</h2>
                <p class="text-sm text-slate-500">Clique em "Gerar apólice" para baixar o PDF.</p>
            </div>
            <a href="<?php echo htmlspecialchars($baseUrl . '/index.php', ENT_QUOTES, 'UTF-8'); ?>" class="text-sm text-blue-600 hover:underline">
                Voltar ao processamento
            </a>
        </div>

        <form action="<?php echo htmlspecialchars($baseUrl . '/upload_locatarios.php', ENT_QUOTES, 'UTF-8'); ?>" method="post" enctype="multipart/form-data" class="mb-6 rounded-lg border border-slate-200 bg-slate-50 p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center">
                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium text-slate-700">Planilha de locatários (CSV/XLSX)</label>
                    <input type="file" name="locatarios" accept=".csv,.xlsx,.xls" required class="w-full rounded border border-slate-200 bg-white p-2 text-sm">
                </div>
                <div class="pt-2 md:pt-6">
                    <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                        Importar
                    </button>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500">Colunas obrigatórias: segurado_nome, segurado_tipo, segurado_cpf_cnpj, segurado_endereco, segurado_bairro, segurado_cep, segurado_cidade, segurado_uf, risco_endereco, risco_numero, risco_bairro, risco_cep, risco_cidade, risco_uf.</p>
        </form>

        <?php if (!empty($importErrors)): ?>
            <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
                <p class="font-semibold">Pendências de preenchimento</p>
                <p class="mt-1">Confira as linhas com campos obrigatórios faltando:</p>
                <div class="mt-2 max-h-48 overflow-auto text-xs">
                    <ul class="list-disc pl-5">
                        <?php foreach ($importErrors as $error): ?>
                            <li>Linha <?php echo htmlspecialchars((string) $error['row'], ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars(implode(', ', $error['missing']), ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($baseUrl . '/locatarios.php', ENT_QUOTES, 'UTF-8'); ?>" method="get" class="mb-4 flex flex-col gap-2 md:flex-row md:items-center">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Buscar por CPF/CNPJ" class="w-full rounded border border-slate-200 bg-white p-2 text-sm md:max-w-xs">
            <button type="submit" class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">Buscar</button>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full border border-slate-200 text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="border-b px-4 py-2">ID</th>
                        <th class="border-b px-4 py-2">Nome</th>
                        <th class="border-b px-4 py-2">CPF/CNPJ</th>
                        <th class="border-b px-4 py-2">Cidade</th>
                        <th class="border-b px-4 py-2">Ação</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($locatarios as $locatario): ?>
                    <?php
                        $segurado = $locatario['segurado'] ?? [];
                        $risco = $locatario['risco'] ?? [];
                        $coberturas = $locatario['coberturas'] ?? [];
                        $vigenciaInicio = $locatario['vigencia_inicio'] ?? '';
                        $vigenciaFim = $locatario['vigencia_fim'] ?? '';
                    ?>
                    <tr class="border-b">
                        <td class="px-4 py-2"><?php echo htmlspecialchars($locatario['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2"><?php echo htmlspecialchars($segurado['nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2"><?php echo htmlspecialchars($segurado['cpf_cnpj'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2"><?php echo htmlspecialchars($segurado['cidade'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2">
                            <form action="<?php echo htmlspecialchars($baseUrl . '/gerar_apolice.php', ENT_QUOTES, 'UTF-8'); ?>" method="post">
                                <input type="hidden" name="locatario_id" value="<?php echo htmlspecialchars($locatario['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="segurado[nome]" value="<?php echo htmlspecialchars($segurado['nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="segurado[tipo]" value="<?php echo htmlspecialchars($segurado['tipo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="segurado[cpf_cnpj]" value="<?php echo htmlspecialchars($segurado['cpf_cnpj'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="segurado[endereco]" value="<?php echo htmlspecialchars($segurado['endereco'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="segurado[bairro]" value="<?php echo htmlspecialchars($segurado['bairro'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="segurado[cep]" value="<?php echo htmlspecialchars($segurado['cep'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="segurado[cidade]" value="<?php echo htmlspecialchars($segurado['cidade'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="segurado[uf]" value="<?php echo htmlspecialchars($segurado['uf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                                <input type="hidden" name="risco[endereco]" value="<?php echo htmlspecialchars($risco['endereco'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="risco[numero]" value="<?php echo htmlspecialchars($risco['numero'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="risco[bairro]" value="<?php echo htmlspecialchars($risco['bairro'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="risco[cep]" value="<?php echo htmlspecialchars($risco['cep'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="risco[cidade]" value="<?php echo htmlspecialchars($risco['cidade'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="risco[uf]" value="<?php echo htmlspecialchars($risco['uf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="risco[questionario]" value="<?php echo htmlspecialchars($risco['questionario'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                                <input type="hidden" name="coberturas[incendio]" value="<?php echo htmlspecialchars($coberturas['incendio'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="coberturas[incendio_conteudo]" value="<?php echo htmlspecialchars($coberturas['incendio_conteudo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="coberturas[vendaval]" value="<?php echo htmlspecialchars($coberturas['vendaval'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="coberturas[perda_aluguel]" value="<?php echo htmlspecialchars($coberturas['perda_aluguel'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="coberturas[danos_eletricos]" value="<?php echo htmlspecialchars($coberturas['danos_eletricos'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="coberturas[responsabilidade_civil]" value="<?php echo htmlspecialchars($coberturas['responsabilidade_civil'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                                <div class="mb-2 flex flex-col gap-2">
                                    <input type="date" name="vigencia_inicio" value="<?php echo htmlspecialchars($vigenciaInicio, ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded border border-slate-200 bg-white p-1 text-xs" placeholder="Vigência início">
                                    <input type="date" name="vigencia_fim" value="<?php echo htmlspecialchars($vigenciaFim, ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded border border-slate-200 bg-white p-1 text-xs" placeholder="Vigência término">
                                </div>
                                <button type="submit" class="rounded bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">
                                    Gerar apólice
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$title = 'Locatários';

require $basePath . '/views/layout.php';

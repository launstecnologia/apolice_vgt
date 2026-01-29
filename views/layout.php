<?php
/** @var string $title */
/** @var string $content */
/** @var array $alerts */
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
    <header class="bg-white shadow-sm">
        <div class="mx-auto max-w-5xl px-6 py-4">
            <h1 class="text-xl font-semibold">Seguro Incêndio</h1>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-10">
        <?php foreach ($alerts as $alert): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?php echo $alert['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'; ?>">
                <?php echo htmlspecialchars($alert['message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endforeach; ?>

        <?php echo $content; ?>
    </main>
</body>
</html>

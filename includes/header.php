<?php require_once __DIR__ . '/auth.php'; ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gráfica</title>
    <link rel="stylesheet" href="<?= h(url('assets/css/style.css')) ?>">
    <style>
    body > header {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center;
        gap: 1000px;
        padding: 30px 30px;
    }

    body > header form.form-sair {
        flex: 0 0 auto;
        margin: 0;
        padding: 0;
        background: transparent;
        box-shadow: none;
    }
    
</style>
</head>
<body>
<header>
    <strong>Gráfica Escolar</strong>
    <?php if (usuario()): ?>
        <nav aria-label="Menu principal">
            <a href="<?= h(url('dashboard.php')) ?>">Painel</a>
            <?php foreach (modulosVisiveis() as $pasta => [$nome]): ?>
                <a href="<?= h(url($pasta . '/')) ?>"><?= h($nome) ?></a>
            <?php endforeach; ?>
            <form class="form-sair" action="<?= h(url('logout.php')) ?>" method="post">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <button>Sair</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main>

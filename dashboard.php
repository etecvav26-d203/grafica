<?php
require_once __DIR__ . '/includes/auth.php';
exigirLogin();
include __DIR__ . '/includes/header.php';
?>
<h1>Painel de gestão</h1>
<p>Olá, <?= h(usuario()['nome']) ?> (<?= h(usuario()['cargo']) ?>).</p>
<?php
$simbolos = [
    'clientes'     => '𖨆',
    'produtos'     => '🖶',
    'pedidos'      => '✎',
    'estoque'      => '▦',
    'financeiro'   => '$',
    'relatorios'   => '▤',
    'funcionarios' => '☻',
];
?>

<style>
    .atalhos-painel {
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr));
    }

    .atalhos-painel .atalho-painel {
        display: flex;
        align-items: center;
        gap: 12px;
        min-height: 96px;
        padding: 20px;
        color: #18202b;
        font-size: 17px;
        font-weight: 600;
        text-decoration: none;
    }

    .atalhos-painel .atalho-painel:hover {
        background: #e9edf5;
    }

    .atalho-painel .simbolo-painel {
        width: 28px;
        flex-shrink: 0;
        color: #18202b;
        font-size: 24px;
        text-align: center;
    }
</style>

<div class="cards atalhos-painel">
    <?php foreach (modulosVisiveis() as $pasta => [$nome]): ?>
        <a class="panel atalho-painel" href="<?= h(url($pasta . '/')) ?>">
            <span class="simbolo-painel" aria-hidden="true"><?= h($simbolos[$pasta] ?? '›') ?></span>
            <?= h($nome) ?>
        </a>
    <?php endforeach; ?>
</div>
</div>
<img
    src="<?= h(url('assets/img/images.jfif')) ?>"
    class="imagem-painel"
    alt=""
>

<style>
 
 .imagem-painel {
    position: fixed;
    left: 0;
    bottom: 0;
    width: 100%;
    height: 40vh;
    object-fit: cover;
    object-position: center;
    opacity: 0.45;
    mask-image: linear-gradient(to bottom, transparent 0%, #000 100%);
    pointer-events: none;
}

    @media print {
        .imagem-painel {
            display: none;
        }
    }
</style>
</main>
</body>
</html>

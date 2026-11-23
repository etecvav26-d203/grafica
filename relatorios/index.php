<?php
require __DIR__ . '/relatorio.php';
if (!$erroPeriodo) {
    [$resumo, $recebimentos, $itens] = dadosApm(conexao(), $inicio, $fim);
    $periodo = http_build_query(['inicio' => $inicio->format('Y-m-d'), 'fim' => $fim->format('Y-m-d')]);
}
include __DIR__ . '/../includes/header.php';
?>
<h1>Relatório para a APM</h1>
<form method="get" action="<?= h(url('relatorios/')) ?>">
    <label for="inicio">Data inicial</label>
    <input id="inicio" type="date" name="inicio" value="<?= h($inicio ? $inicio->format('Y-m-d') : '') ?>" required>
    <label for="fim">Data final</label>
    <input id="fim" type="date" name="fim" value="<?= h($fim ? $fim->format('Y-m-d') : '') ?>" required>
    <button>Consultar</button>
</form>
<?php if ($erroPeriodo): ?>
    <p class="error" role="alert">Informe datas válidas com a data inicial anterior ou igual à final.</p>
<?php else: ?>
    <p><a class="btn no-print" href="<?= h(url('relatorios/gerar_pdf.php?' . $periodo)) ?>">Baixar PDF</a></p>
    <h2><?= h($inicio->format('d/m/Y')) ?> a <?= h($fim->format('d/m/Y')) ?></h2>
    <div class="cards">
        <div class="panel">Pedidos válidos<br><strong><?= h($resumo['pedidos'] - $resumo['cancelados']) ?></strong></div>
        <div class="panel">Pedidos cancelados<br><strong><?= h($resumo['cancelados']) ?></strong></div>
        <div class="panel">Valor dos pedidos válidos<br><strong><?= h(dinheiroApm($resumo['vendido'])) ?></strong></div>
        <div class="panel">Recebido no período<br><strong><?= h(dinheiroApm($resumo['recebido'])) ?></strong></div>
        <div class="panel">Saldo dos pedidos válidos no fim do período<br><strong><?= h(dinheiroApm($resumo['saldo'])) ?></strong></div>
    </div>
    <p>Pedidos pela data de cadastro e situação atual. Recebimentos pela data de pagamento, inclusive de pedidos anteriores.</p>
    <h2>Recebimentos por forma de pagamento</h2>
    <?php if (!$recebimentos): ?>
        <p>Nenhum recebimento no período.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Forma</th><th>Pagamentos</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($recebimentos as $linha): ?>
                <tr><td><?= h($formasApm[$linha['forma']] ?? $linha['forma']) ?></td><td><?= h($linha['quantidade']) ?></td><td><?= h(dinheiroApm($linha['total'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <h2>Produtos e serviços dos pedidos válidos</h2>
    <?php if (!$itens): ?>
        <p>Nenhum item no período.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Item</th><th>Tipo</th><th>Quantidade</th><th>Valor</th></tr></thead>
            <tbody>
            <?php foreach ($itens as $linha): ?>
                <tr><td><?= h($linha['nome']) ?></td><td><?= $linha['tipo'] === 'servico' ? 'Serviço' : 'Produto' ?></td><td><?= h($linha['quantidade']) ?></td><td><?= h(dinheiroApm($linha['total'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>

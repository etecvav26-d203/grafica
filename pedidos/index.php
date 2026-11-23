<?php
require __DIR__ . '/comum.php';

$pagina = inteiroPedido($_GET['pagina'] ?? '') ?: 1;
$total = (int) $pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn();
$paginas = max(1, (int) ceil($total / 25));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * 25;
$pedidos = $pdo->query(
    "SELECT p.id, p.status, p.total, p.criado_em, c.nome AS cliente
     FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
     ORDER BY p.id DESC LIMIT 25 OFFSET $offset"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<h1>Pedidos</h1>
<?php if ($podeCriarPedido): ?>
    <p><a class="btn" href="<?= h(url('pedidos/novo.php')) ?>">Novo pedido</a></p>
<?php endif; ?>
<?php if (!$pedidos): ?>
    <p>Nenhum pedido registrado.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Pedido</th><th>Cliente</th><th>Data</th><th>Status</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($pedidos as $pedido): ?>
            <tr>
                <td><a href="<?= h(url('pedidos/detalhes.php?id=' . $pedido['id'])) ?>">#<?= h($pedido['id']) ?></a></td>
                <td><?= h($pedido['cliente']) ?></td>
                <td><?= h($pedido['criado_em']) ?></td>
                <td><?= h($statusPedidos[$pedido['status']]) ?></td>
                <td><?= h(realPedido($pedido['total'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($paginas > 1): ?>
        <p>
            <?php if ($pagina > 1): ?><a href="?pagina=<?= $pagina - 1 ?>">Anterior</a><?php endif; ?>
            Página <?= $pagina ?> de <?= $paginas ?>
            <?php if ($pagina < $paginas): ?><a href="?pagina=<?= $pagina + 1 ?>">Próxima</a><?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>

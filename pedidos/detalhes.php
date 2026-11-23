<?php
require __DIR__ . '/comum.php';

$id = inteiroPedido($_GET['id'] ?? '');
if (!$id) {
    http_response_code(404);
    exit('Pedido não encontrado.');
}
$proximosStatus = [
    'recebido' => 'pronto',
    'producao' => 'pronto',
    'pronto' => 'entregue',
];

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = campoPost('acao');
    if ($acao === 'cancelar') {
        exigirCargo(['administrador', 'gerente']);
    } else {
        exigirCargo(['administrador', 'gerente', 'producao', 'atendente']);
    }
    verificarCsrf();

    try {
        $pdo->beginTransaction();
        $consulta = $pdo->prepare('SELECT status FROM pedidos WHERE id = ? FOR UPDATE');
        $consulta->execute([$id]);
        $atual = $consulta->fetchColumn();
        if (!$atual || campoPost('status_atual') !== $atual) {
            throw new DomainException('O pedido mudou desde que você abriu a tela. Confira o status atual.');
        }

        if ($acao === 'cancelar') {
            if (!isset($proximosStatus[$atual])) {
                throw new DomainException('Só é possível cancelar pedidos em aberto.');
            }
            $consulta = $pdo->prepare('SELECT id FROM pagamentos WHERE pedido_id = ? LIMIT 1');
            $consulta->execute([$id]);
            if ($consulta->fetchColumn()) {
                throw new DomainException('Este pedido possui pagamento registrado. O cancelamento está bloqueado.');
            }

            $consulta = $pdo->prepare(
                "SELECT i.produto_id, SUM(i.quantidade) AS quantidade
                 FROM pedido_itens i JOIN produtos p ON p.id = i.produto_id
                 WHERE i.pedido_id = ? AND p.tipo = 'produto'
                 GROUP BY i.produto_id ORDER BY i.produto_id"
            );
            $consulta->execute([$id]);
            $repor = $pdo->prepare('UPDATE produtos SET estoque = estoque + ? WHERE id = ? AND estoque <= 2147483647 - ?');
            $movimento = $pdo->prepare("INSERT INTO movimentacoes (produto_id, funcionario_id, tipo, quantidade, observacao) VALUES (?, ?, 'entrada', ?, ?)");
            foreach ($consulta->fetchAll() as $item) {
                $qtd = (int) $item['quantidade'];
                $repor->execute([$qtd, $item['produto_id'], $qtd]);
                if ($repor->rowCount() !== 1) {
                    throw new DomainException('O estorno ultrapassa o limite de estoque de um produto.');
                }
                $movimento->execute([$item['produto_id'], usuario()['id'], $qtd, 'Cancelamento do pedido #' . $id]);
            }
            $novoStatus = 'cancelado';
        } else {
            $novoStatus = $proximosStatus[$atual] ?? null;
            $permitido = $gestaoPedidos || ($atual === 'pronto' ? $cargoPedido === 'atendente' : $cargoPedido === 'producao');
            if ($acao !== 'avancar' || !$novoStatus || !$permitido) {
                http_response_code(403);
                throw new DomainException('Você não pode realizar esta alteração de status.');
            }
        }

        $pdo->prepare('UPDATE pedidos SET status = ? WHERE id = ?')->execute([$novoStatus, $id]);
        $pdo->commit();
        header('Location: ' . url('pedidos/detalhes.php?id=' . $id . '&atualizado=1'), true, 303);
        exit;
    } catch (Throwable $falha) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erro = $falha instanceof DomainException ? $falha->getMessage() : 'Não foi possível atualizar o pedido. Tente novamente.';
    }
}

$consulta = $pdo->prepare(
    'SELECT p.*, c.nome AS cliente, f.nome AS funcionario,
            EXISTS(SELECT 1 FROM pagamentos pg WHERE pg.pedido_id = p.id) AS tem_pagamento
     FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
     JOIN funcionarios f ON f.id = p.funcionario_id WHERE p.id = ?'
);
$consulta->execute([$id]);
$pedido = $consulta->fetch();
if (!$pedido) {
    http_response_code(404);
    exit('Pedido não encontrado.');
}

$consulta = $pdo->prepare(
    'SELECT p.nome, p.tipo, i.quantidade, i.preco_unitario, i.quantidade * i.preco_unitario AS subtotal
     FROM pedido_itens i JOIN produtos p ON p.id = i.produto_id
     WHERE i.pedido_id = ? ORDER BY i.id'
);
$consulta->execute([$id]);
$itens = $consulta->fetchAll();
$atual = $pedido['status'];
$proximo = $proximosStatus[$atual] ?? null;
$podeAvancar = $proximo && ($gestaoPedidos || ($atual === 'pronto' ? $cargoPedido === 'atendente' : $cargoPedido === 'producao'));

include __DIR__ . '/../includes/header.php';
?>
<h1>Pedido #<?= h($id) ?></h1>
<p><a href="<?= h(url('pedidos/')) ?>">Voltar para Pedidos</a></p>
<?php if (($_GET['criado'] ?? '') === '1'): ?><p class="success" role="status">Pedido criado.</p><?php endif; ?>
<?php if (($_GET['atualizado'] ?? '') === '1'): ?><p class="success" role="status">Pedido atualizado.</p><?php endif; ?>
<?php if ($erro): ?><p class="error" role="alert"><?= h($erro) ?></p><?php endif; ?>
<div class="panel">
    <p><strong>Cliente:</strong> <?= h($pedido['cliente']) ?></p>
    <p><strong>Registrado por:</strong> <?= h($pedido['funcionario']) ?> em <?= h($pedido['criado_em']) ?></p>
    <p><strong>Status:</strong> <?= h($statusPedidos[$atual]) ?></p>
    <p><strong>Total:</strong> <?= h(realPedido($pedido['total'])) ?></p>
</div>
<h2>Itens do pedido</h2>
<table>
    <thead><tr><th>Item</th><th>Tipo</th><th>Quantidade</th><th>Preço unitário</th><th>Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($itens as $item): ?>
        <tr>
            <td><?= h($item['nome']) ?></td>
            <td><?= $item['tipo'] === 'produto' ? 'Produto' : 'Serviço' ?></td>
            <td><?= h($item['quantidade']) ?></td>
            <td><?= h(realPedido($item['preco_unitario'])) ?></td>
            <td><?= h(realPedido($item['subtotal'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($podeAvancar || ($gestaoPedidos && $proximo && !$pedido['tem_pagamento'])): ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <input type="hidden" name="status_atual" value="<?= h($atual) ?>">
        <?php if ($podeAvancar): ?>
            <button name="acao" value="avancar">Marcar como <?= h($statusPedidos[$proximo]) ?></button>
        <?php endif; ?>
        <?php if ($gestaoPedidos && $proximo && !$pedido['tem_pagamento']): ?>
            <button name="acao" value="cancelar" onclick="return confirm('Cancelar o pedido e devolver os produtos ao estoque?')">Cancelar pedido</button>
        <?php endif; ?>
    </form>
<?php endif; ?>
<?php if ($gestaoPedidos && $proximo && $pedido['tem_pagamento']): ?>
    <p>O cancelamento está bloqueado porque há pagamento registrado.</p>
<?php endif; ?>
</main>
</body>
</html>

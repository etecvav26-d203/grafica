<?php
require_once __DIR__ . '/../includes/auth.php';
exigirModulo('financeiro');
require_once __DIR__ . '/../conexao.php';

$pdo = conexao();
$base = url('financeiro/');
$formas = ['pix' => 'Pix', 'dinheiro' => 'Dinheiro', 'cartao' => 'Cartão'];
$limitesId = ['options' => ['min_range' => 1, 'max_range' => 2147483647]];
$pedidoId = filter_input(INPUT_GET, 'pedido', FILTER_VALIDATE_INT, $limitesId) ?: 0;
$valorDigitado = '';
$forma = 'pix';
$erro = '';

function centavosFinanceiro($decimal)
{
    [$inteiro, $fracao] = array_pad(explode('.', (string) $decimal, 2), 2, '');
    return (int) $inteiro * 100 + (int) str_pad($fracao, 2, '0');
}

function reaisFinanceiro($valor)
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $valorDigitado = trim(campoPost('valor'));
    $decimal = str_replace(',', '.', $valorDigitado);
    $forma = campoPost('forma');
    $envio = campoPost('envio');

    if (!$pedidoId || !isset($formas[$forma]) || !preg_match('/^[0-9]{1,8}(\.[0-9]{1,2})?$/D', $decimal)
        || centavosFinanceiro($decimal) <= 0) {
        $erro = 'Informe uma forma de pagamento e um valor positivo com até duas casas decimais.';
    } elseif ($envio === '' || (int) ($_SESSION['formularios_pagamento'][$envio] ?? 0) !== $pedidoId) {
        $erro = 'Esse formulário já foi enviado ou expirou. Confira o histórico antes de registrar outro pagamento.';
    } else {
        try {
            $pdo->beginTransaction();
            $consulta = $pdo->prepare('SELECT total, status FROM pedidos WHERE id = ? FOR UPDATE');
            $consulta->execute([$pedidoId]);
            $pedido = $consulta->fetch();
            if (!$pedido || $pedido['status'] === 'cancelado') {
                throw new DomainException('Esse pedido não pode receber pagamentos.');
            }

            $consulta = $pdo->prepare('SELECT COALESCE(SUM(valor), 0) FROM pagamentos WHERE pedido_id = ?');
            $consulta->execute([$pedidoId]);
            $saldo = centavosFinanceiro($pedido['total']) - centavosFinanceiro($consulta->fetchColumn());
            if ($saldo <= 0) {
                throw new DomainException('Esse pedido já está quitado.');
            }
            if (centavosFinanceiro($decimal) > $saldo) {
                throw new DomainException('O valor ultrapassa o saldo de ' . reaisFinanceiro($saldo / 100) . '.');
            }
            $pdo->prepare('INSERT INTO pagamentos (pedido_id, valor, forma) VALUES (?, ?, ?)')
                ->execute([$pedidoId, $decimal, $forma]);
            $pdo->commit();
            unset($_SESSION['formularios_pagamento'][$envio]);
            header('Location: ' . $base . '?pedido=' . $pedidoId . '&salvo=1', true, 303);
            exit;
        } catch (Throwable $falha) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erro = $falha instanceof DomainException ? $falha->getMessage() : 'Não foi possível registrar o pagamento. Tente novamente.';
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<h1>Financeiro</h1>
<?php if ($erro): ?><p class="error" role="alert"><?= h($erro) ?></p><?php endif; ?>
<?php if ($pedidoId): ?>
    <?php
    $consulta = $pdo->prepare(
        'SELECT p.id, p.total, p.status, c.nome AS cliente
         FROM pedidos p JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?'
    );
    $consulta->execute([$pedidoId]);
    $pedido = $consulta->fetch();
    ?>
    <p><a href="<?= h($base) ?>">Voltar ao Financeiro</a></p>
    <?php if (!$pedido): ?>
        <p>Pedido não encontrado.</p>
    <?php else: ?>
        <?php
        $consulta = $pdo->prepare('SELECT valor, forma, pago_em FROM pagamentos WHERE pedido_id = ? ORDER BY id DESC');
        $consulta->execute([$pedidoId]);
        $historico = $consulta->fetchAll();
        $recebido = 0;
        foreach ($historico as $pagamento) {
            $recebido += centavosFinanceiro($pagamento['valor']);
        }
        $cancelado = $pedido['status'] === 'cancelado';
        $saldo = $cancelado ? 0 : max(0, centavosFinanceiro($pedido['total']) - $recebido);
        $situacao = $cancelado ? 'Cancelado' : ($saldo === 0 ? 'Quitado' : ($recebido > 0 ? 'Parcial' : 'Pendente'));
        ?>
        <?php if (($_GET['salvo'] ?? '') === '1'): ?><p class="success" role="status">Pagamento registrado.</p><?php endif; ?>
        <h2>Pedido #<?= h($pedidoId) ?></h2>
        <p><?= h($pedido['cliente']) ?> · <?= h($situacao) ?> · <a href="<?= h(url('pedidos/detalhes.php?id=' . $pedidoId)) ?>">Ver itens</a></p>
        <div class="cards">
            <div class="panel">Total do pedido<br><strong><?= h(reaisFinanceiro($pedido['total'])) ?></strong></div>
            <div class="panel">Valor recebido<br><strong><?= h(reaisFinanceiro($recebido / 100)) ?></strong></div>
            <div class="panel">Saldo a receber<br><strong><?= h(reaisFinanceiro($saldo / 100)) ?></strong></div>
        </div>
        <?php if ($saldo > 0): ?>
            <?php
            $envio = bin2hex(random_bytes(16));
            $_SESSION['formularios_pagamento'][$envio] = $pedidoId;
            $_SESSION['formularios_pagamento'] = array_slice($_SESSION['formularios_pagamento'], -30, null, true);
            ?>
            <form method="post" action="<?= h($base . '?pedido=' . $pedidoId) ?>">
                <h2>Registrar pagamento recebido</h2>
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <input type="hidden" name="envio" value="<?= h($envio) ?>">
                <label for="valor">Valor (R$)</label>
                <input id="valor" name="valor" inputmode="decimal" maxlength="11" value="<?= h($valorDigitado !== '' ? $valorDigitado : number_format($saldo / 100, 2, ',', '')) ?>" required>
                <label for="forma">Forma de pagamento</label>
                <select id="forma" name="forma">
                    <?php foreach ($formas as $chave => $rotulo): ?>
                        <option value="<?= h($chave) ?>" <?= $forma === $chave ? 'selected' : '' ?>><?= h($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <button>Registrar pagamento</button>
            </form>
        <?php endif; ?>
        <h2>Histórico do pedido</h2>
        <?php if (!$historico): ?>
            <p>Nenhum pagamento registrado.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Data e hora</th><th>Forma</th><th>Valor</th></tr></thead>
                <tbody>
                <?php foreach ($historico as $pagamento): ?>
                    <tr>
                        <td><?= h($pagamento['pago_em']) ?></td>
                        <td><?= h($formas[$pagamento['forma']] ?? $pagamento['forma']) ?></td>
                        <td><?= h(reaisFinanceiro($pagamento['valor'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php else: ?>
    <?php
    $busca = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    $situacao = $_GET['situacao'] ?? '';
    $situacoes = ['pendente' => 'Pendente', 'parcial' => 'Parcial', 'quitado' => 'Quitado', 'cancelado' => 'Cancelado'];
    $totaisPorPedido = 'SELECT pedido_id, SUM(valor) AS recebido FROM pagamentos GROUP BY pedido_id';
    $basePedidos = "SELECT p.id, p.total, c.nome AS cliente, COALESCE(pg.recebido, 0) AS recebido,
        CASE WHEN p.status = 'cancelado' THEN 0 ELSE GREATEST(p.total - COALESCE(pg.recebido, 0), 0) END AS saldo,
        CASE WHEN p.status = 'cancelado' THEN 'cancelado'
             WHEN p.total <= COALESCE(pg.recebido, 0) THEN 'quitado'
             WHEN COALESCE(pg.recebido, 0) > 0 THEN 'parcial'
             ELSE 'pendente' END AS situacao
        FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
        LEFT JOIN ($totaisPorPedido) pg ON pg.pedido_id = p.id";
    $resumo = $pdo->query(
        "SELECT COALESCE(SUM(recebido), 0) AS recebido, COALESCE(SUM(saldo), 0) AS saldo FROM ($basePedidos) pedidos"
    )->fetch();
    $filtros = [];
    $parametros = [];
    if ($busca !== '') {
        $idBusca = filter_var(ltrim($busca, '#'), FILTER_VALIDATE_INT, $limitesId);
        $filtros[] = "(id = ? OR cliente LIKE ? ESCAPE '!')";
        $parametros[] = $idBusca ?: 0;
        $parametros[] = '%' . strtr($busca, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    if (is_string($situacao) && isset($situacoes[$situacao])) {
        $filtros[] = 'situacao = ?';
        $parametros[] = $situacao;
    } else {
        $situacao = '';
    }
    $fonte = " FROM ($basePedidos) pedidos" . ($filtros ? ' WHERE ' . implode(' AND ', $filtros) : '');
    $consulta = $pdo->prepare('SELECT COUNT(*)' . $fonte);
    $consulta->execute($parametros);
    $paginas = max(1, (int) ceil($consulta->fetchColumn() / 25));
    $pagina = min($paginas, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT, $limitesId) ?: 1);
    $offset = ($pagina - 1) * 25;
    $consulta = $pdo->prepare('SELECT *' . $fonte . " ORDER BY id DESC LIMIT 25 OFFSET $offset");
    $consulta->execute($parametros);
    $pedidos = $consulta->fetchAll();
    ?>
    <div class="cards">
        <div class="panel">Total recebido<br><strong><?= h(reaisFinanceiro($resumo['recebido'])) ?></strong></div>
        <div class="panel">Total a receber<br><strong><?= h(reaisFinanceiro($resumo['saldo'])) ?></strong></div>
    </div>
    <form method="get" action="<?= h($base) ?>">
        <label for="q">Número do pedido ou nome do cliente</label>
        <input id="q" name="q" type="search" value="<?= h($busca) ?>">
        <label for="situacao">Situação</label>
        <select id="situacao" name="situacao">
            <option value="">Todas</option>
            <?php foreach ($situacoes as $chave => $rotulo): ?>
                <option value="<?= h($chave) ?>" <?= $situacao === $chave ? 'selected' : '' ?>><?= h($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <button>Buscar</button> <a href="<?= h($base) ?>">Limpar filtros</a>
    </form>
    <?php if (!$pedidos): ?>
        <p>Nenhum pedido encontrado.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Pedido</th><th>Cliente</th><th>Total</th><th>Recebido</th><th>Saldo</th><th>Situação</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($pedidos as $pedido): ?>
                <tr>
                    <td>#<?= h($pedido['id']) ?></td>
                    <td><?= h($pedido['cliente']) ?></td>
                    <td><?= h(reaisFinanceiro($pedido['total'])) ?></td>
                    <td><?= h(reaisFinanceiro($pedido['recebido'])) ?></td>
                    <td><?= h(reaisFinanceiro($pedido['saldo'])) ?></td>
                    <td><?= h($situacoes[$pedido['situacao']]) ?></td>
                    <td><a href="<?= h($base . '?pedido=' . $pedido['id']) ?>"><?= $pedido['saldo'] > 0 ? 'Receber / Ver histórico' : 'Ver histórico' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($paginas > 1): ?>
            <p>
                <?php if ($pagina > 1): ?><a href="<?= h($base . '?' . http_build_query(['q' => $busca, 'situacao' => $situacao, 'pagina' => $pagina - 1])) ?>">Anterior</a><?php endif; ?>
                Página <?= $pagina ?> de <?= $paginas ?>
                <?php if ($pagina < $paginas): ?><a href="<?= h($base . '?' . http_build_query(['q' => $busca, 'situacao' => $situacao, 'pagina' => $pagina + 1])) ?>">Próxima</a><?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>

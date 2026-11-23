<?php
require_once __DIR__ . '/../includes/auth.php';
exigirModulo('estoque');
require_once __DIR__ . '/../conexao.php';
$pdo = conexao();

$produtoPost = '';
$tipo = 'entrada';
$quantidadePost = '';
$observacao = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $produtoPost = campoPost('produto_id');
    $tipo = campoPost('tipo');
    $quantidadePost = campoPost('quantidade');
    $observacao = trim(campoPost('observacao'));
    $limites = ['options' => ['min_range' => 1, 'max_range' => 2147483647]];
    $produtoId = filter_var($produtoPost, FILTER_VALIDATE_INT, $limites);
    $quantidade = filter_var($quantidadePost, FILTER_VALIDATE_INT, $limites);

    if (!$produtoId || !$quantidade || !in_array($tipo, ['entrada', 'saida'], true)) {
        $erro = 'Escolha o produto e a operação e informe uma quantidade inteira maior que zero.';
    } elseif (!preg_match('/^.{0,255}$/usD', $observacao)) {
        $erro = 'Informe uma observação de até 255 caracteres.';
    } else {
        $responsavel = usuario()['id'];
        try {
            $pdo->beginTransaction();
            $consulta = $pdo->prepare("SELECT estoque FROM produtos WHERE id = ? AND tipo = 'produto' FOR UPDATE");
            $consulta->execute([$produtoId]);
            $produto = $consulta->fetch();

            if (!$produto) {
                throw new DomainException('Produto físico não encontrado.');
            }

            $saldo = (int) $produto['estoque'];
            if ($tipo === 'saida' && $quantidade > $saldo) {
                throw new DomainException('Estoque insuficiente. Saldo atual: ' . $saldo . ' unidade(s).');
            }
            if ($tipo === 'entrada' && $saldo > 2147483647 - $quantidade) {
                throw new DomainException('A entrada ultrapassa o limite de estoque do sistema.');
            }

            $delta = $tipo === 'entrada' ? $quantidade : -$quantidade;
            $pdo->prepare('UPDATE produtos SET estoque = estoque + ? WHERE id = ?')->execute([$delta, $produtoId]);
            $pdo->prepare(
                'INSERT INTO movimentacoes (produto_id, funcionario_id, tipo, quantidade, observacao) VALUES (?, ?, ?, ?, ?)'
            )->execute([$produtoId, $responsavel, $tipo, $quantidade, $observacao]);
            $pdo->commit();
            header('Location: ' . url('estoque/?salvo=1'), true, 303);
            exit;
        } catch (Throwable $falha) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erro = $falha instanceof DomainException
                ? $falha->getMessage()
                : 'Não foi possível registrar a movimentação. Tente novamente.';
        }
    }
}

$produtos = $pdo->query("SELECT id, nome, estoque, minimo, ativo FROM produtos WHERE tipo = 'produto' ORDER BY nome, id")->fetchAll();

$filtro = filter_input(INPUT_GET, 'produto', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) ?: 0;
$where = $filtro ? ' WHERE m.produto_id = ?' : '';
$parametros = $filtro ? [$filtro] : [];
$consulta = $pdo->prepare('SELECT COUNT(*) FROM movimentacoes m' . $where);
$consulta->execute($parametros);
$paginas = max(1, (int) ceil($consulta->fetchColumn() / 25));
$pagina = min($paginas, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1);
$offset = ($pagina - 1) * 25;
$consulta = $pdo->prepare(
    'SELECT m.tipo, m.quantidade, m.observacao, m.criado_em, p.nome AS produto, f.nome AS responsavel
     FROM movimentacoes m
     JOIN produtos p ON p.id = m.produto_id
     JOIN funcionarios f ON f.id = m.funcionario_id' . $where .
    " ORDER BY m.id DESC LIMIT 25 OFFSET $offset"
);
$consulta->execute($parametros);
$historico = $consulta->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<h1>Estoque</h1>
<?php if (($_GET['salvo'] ?? '') === '1'): ?>
    <p class="success" role="status">Movimentação registrada.</p>
<?php endif; ?>
<?php if ($erro): ?><p class="error" role="alert"><?= h($erro) ?></p><?php endif; ?>

<?php if (!$produtos): ?>
    <p>Cadastre um produto físico no catálogo para movimentar o estoque.</p>
<?php else: ?>
    <form method="post" action="<?= h(url('estoque/')) ?>">
        <h2>Registrar movimentação</h2>
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <label for="produto_id">Produto</label>
        <select id="produto_id" name="produto_id" required>
            <option value="">Selecione</option>
            <?php foreach ($produtos as $produto): ?>
                <option value="<?= h($produto['id']) ?>" <?= (string) $produto['id'] === $produtoPost ? 'selected' : '' ?>>
                    <?= h($produto['nome']) ?><?= $produto['ativo'] ? '' : ' (inativo)' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="tipo">Operação</label>
        <select id="tipo" name="tipo">
            <option value="entrada" <?= $tipo === 'entrada' ? 'selected' : '' ?>>Entrada</option>
            <option value="saida" <?= $tipo === 'saida' ? 'selected' : '' ?>>Saída</option>
        </select>
        <label for="quantidade">Quantidade</label>
        <input id="quantidade" type="number" name="quantidade" min="1" max="2147483647" step="1" value="<?= h($quantidadePost) ?>" required>
        <label for="observacao">Observação (opcional)</label>
        <input id="observacao" name="observacao" maxlength="255" value="<?= h($observacao) ?>">
        <button>Registrar</button>
    </form>

    <h2>Saldos atuais</h2>
    <table>
        <thead><tr><th>Produto</th><th>Saldo</th><th>Mínimo</th><th>Status do estoque</th></tr></thead>
        <tbody>
        <?php foreach ($produtos as $produto): ?>
            <?php $saldo = (int) $produto['estoque']; ?>
            <tr>
                <td><?= h($produto['nome']) ?><?= $produto['ativo'] ? '' : ' (inativo)' ?></td>
                <td><?= h($saldo) ?></td>
                <td><?= h($produto['minimo']) ?></td>
                <td>
                    <?php if ($saldo <= 0): ?>
                        <strong class="error">Sem estoque</strong>
                    <?php elseif ($saldo <= (int) $produto['minimo']): ?>
                        <strong class="error">No mínimo ou abaixo</strong>
                    <?php else: ?>
                        Normal
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Histórico de movimentações</h2>
<?php if ($produtos): ?>
    <form method="get" action="<?= h(url('estoque/')) ?>">
        <label for="produto">Filtrar histórico por produto</label>
        <select id="produto" name="produto">
            <option value="0">Todos os produtos</option>
            <?php foreach ($produtos as $produto): ?>
                <option value="<?= h($produto['id']) ?>" <?= (int) $produto['id'] === $filtro ? 'selected' : '' ?>>
                    <?= h($produto['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button>Filtrar</button>
    </form>
<?php endif; ?>
<?php if (!$historico): ?>
    <p>Nenhuma movimentação registrada para esta seleção.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Data e hora</th><th>Produto</th><th>Operação</th><th>Quantidade</th><th>Responsável</th><th>Observação</th></tr></thead>
        <tbody>
        <?php foreach ($historico as $movimento): ?>
            <tr>
                <td><?= h((new DateTimeImmutable($movimento['criado_em']))->format('d/m/Y H:i:s')) ?></td>
                <td><?= h($movimento['produto']) ?></td>
                <td><?= $movimento['tipo'] === 'entrada' ? 'Entrada' : 'Saída' ?></td>
                <td><?= h($movimento['quantidade']) ?></td>
                <td><?= h($movimento['responsavel']) ?></td>
                <td><?= h($movimento['observacao'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($paginas > 1): ?>
        <p>
            <?php if ($pagina > 1): ?>
                <a href="<?= h(url('estoque/?produto=' . $filtro . '&pagina=' . ($pagina - 1))) ?>">Anterior</a>
            <?php endif; ?>
            Página <?= $pagina ?> de <?= $paginas ?>
            <?php if ($pagina < $paginas): ?>
                <a href="<?= h(url('estoque/?produto=' . $filtro . '&pagina=' . ($pagina + 1))) ?>">Próxima</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
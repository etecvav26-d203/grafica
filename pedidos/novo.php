<?php
require __DIR__ . '/comum.php';
exigirCargo(['administrador', 'gerente', 'atendente']);

$erro = '';
$clienteId = inteiroPedido(campoPost('cliente_id'));
$linhas = [['produto' => '', 'quantidade' => '1']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    try {
        $produtosPost = $_POST['produto'] ?? [];
        $quantidadesPost = $_POST['quantidade'] ?? [];
        if (!is_array($produtosPost) || !is_array($quantidadesPost)
            || array_keys($produtosPost) !== array_keys($quantidadesPost)
            || count($produtosPost) < 1 || count($produtosPost) > 50) {
            throw new DomainException('Adicione entre 1 e 50 linhas ao pedido.');
        }

        $linhas = [];
        foreach ($produtosPost as $indice => $produtoPost) {
            $linhas[] = [
                'produto' => is_string($produtoPost) ? $produtoPost : '',
                'quantidade' => is_string($quantidadesPost[$indice]) ? $quantidadesPost[$indice] : '',
            ];
        }

        $quantidades = [];
        foreach ($linhas as $linha) {
            $id = inteiroPedido($linha['produto']);
            $qtd = inteiroPedido($linha['quantidade']);
            if (!$id || !$qtd || ($quantidades[$id] ?? 0) > 2147483647 - $qtd) {
                throw new DomainException('Selecione um item e uma quantidade inteira positiva em cada linha.');
            }
            $quantidades[$id] = ($quantidades[$id] ?? 0) + $qtd;
        }
        ksort($quantidades, SORT_NUMERIC);

        $token = campoPost('envio');
        if ($token !== '' && isset($_SESSION['pedidos_criados'][$token])) {
            header('Location: ' . url('pedidos/detalhes.php?id=' . $_SESSION['pedidos_criados'][$token]), true, 303);
            exit;
        }
        if ($token === '' || !isset($_SESSION['formularios_pedido'][$token])) {
            throw new DomainException('Formulário expirado. Revise os itens e envie novamente.');
        }

        $pdo->beginTransaction();
        $consultaCliente = $pdo->prepare('SELECT id FROM clientes WHERE id = ?');
        $consultaCliente->execute([$clienteId ?: 0]);
        if (!$consultaCliente->fetchColumn()) {
            throw new DomainException('Selecione um cliente cadastrado.');
        }

        $consultaItem = $pdo->prepare('SELECT nome, tipo, preco, estoque FROM produtos WHERE id = ? AND ativo = 1 FOR UPDATE');
        $itens = [];
        $total = 0;
        foreach ($quantidades as $id => $qtd) {
            $consultaItem->execute([$id]);
            $item = $consultaItem->fetch();
            if (!$item) {
                throw new DomainException('Um item ficou indisponível. Atualize a seleção.');
            }
            if ($item['tipo'] === 'produto' && $qtd > (int) $item['estoque']) {
                throw new DomainException('Estoque insuficiente para ' . $item['nome'] . '. Saldo: ' . $item['estoque'] . '.');
            }
            $preco = (int) str_replace('.', '', (string) $item['preco']);
            if ($preco < 0 || $preco > intdiv(9999999999 - $total, $qtd)) {
                throw new DomainException('O pedido ultrapassa o limite de valor ou tem preço inválido.');
            }
            $total += $preco * $qtd;
            $itens[$id] = $item;
        }

        $funcionario = usuario()['id'];
        $totalDecimal = intdiv($total, 100) . '.' . str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO pedidos (cliente_id, funcionario_id, total) VALUES (?, ?, ?)')
            ->execute([$clienteId, $funcionario, $totalDecimal]);
        $pedidoId = $pdo->lastInsertId();
        $inserirItem = $pdo->prepare('INSERT INTO pedido_itens (pedido_id, produto_id, quantidade, preco_unitario) VALUES (?, ?, ?, ?)');
        $baixar = $pdo->prepare('UPDATE produtos SET estoque = estoque - ? WHERE id = ?');
        $movimento = $pdo->prepare("INSERT INTO movimentacoes (produto_id, funcionario_id, tipo, quantidade, observacao) VALUES (?, ?, 'saida', ?, ?)");
        foreach ($itens as $id => $item) {
            $qtd = $quantidades[$id];
            $inserirItem->execute([$pedidoId, $id, $qtd, $item['preco']]);
            if ($item['tipo'] === 'produto') {
                $baixar->execute([$qtd, $id]);
                $movimento->execute([$id, $funcionario, $qtd, 'Pedido #' . $pedidoId]);
            }
        }
        $pdo->commit();
        unset($_SESSION['formularios_pedido'][$token]);
        $_SESSION['pedidos_criados'][$token] = $pedidoId;
        $_SESSION['pedidos_criados'] = array_slice($_SESSION['pedidos_criados'], -20, null, true);
        header('Location: ' . url('pedidos/detalhes.php?id=' . $pedidoId . '&criado=1'), true, 303);
        exit;
    } catch (Throwable $falha) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erro = $falha instanceof DomainException ? $falha->getMessage() : 'Não foi possível salvar o pedido. Tente novamente.';
    }
}

$envio = bin2hex(random_bytes(16));
$_SESSION['formularios_pedido'][$envio] = true;
$_SESSION['formularios_pedido'] = array_slice($_SESSION['formularios_pedido'], -20, null, true);
$clientes = $pdo->query('SELECT id, nome FROM clientes ORDER BY nome, id')->fetchAll();
$produtos = $pdo->query('SELECT id, nome, tipo, preco, estoque FROM produtos WHERE ativo = 1 ORDER BY nome, id')->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<h1>Novo pedido</h1>
<p><a href="<?= h(url('pedidos/')) ?>">Voltar para Pedidos</a></p>
<?php if ($erro): ?><p class="error" role="alert"><?= h($erro) ?></p><?php endif; ?>
<?php if (!$clientes || !$produtos): ?>
    <p>É necessário ter um cliente cadastrado e um item ativo no catálogo.</p>
<?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <input type="hidden" name="envio" value="<?= h($envio) ?>">
        <label for="cliente_id">Cliente</label>
        <select id="cliente_id" name="cliente_id" required>
            <option value="">Selecione</option>
            <?php foreach ($clientes as $cliente): ?>
                <option value="<?= h($cliente['id']) ?>" <?= (int) $cliente['id'] === $clienteId ? 'selected' : '' ?>><?= h($cliente['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <h2>Itens</h2>
        <div id="linhas">
            <?php foreach ($linhas as $linha): ?>
                <fieldset class="linha-item">
                    <label>Produto ou serviço
                        <select name="produto[]" required>
                            <option value="">Selecione</option>
                            <?php foreach ($produtos as $produto): ?>
                                <option value="<?= h($produto['id']) ?>" <?= (string) $produto['id'] === $linha['produto'] ? 'selected' : '' ?>>
                                    <?= h($produto['nome'] . ' · ' . realPedido($produto['preco']) .
                                        ($produto['tipo'] === 'produto' ? ' · Saldo: ' . $produto['estoque'] : ' · Serviço')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Quantidade <input name="quantidade[]" type="number" min="1" step="1" max="2147483647" value="<?= h($linha['quantidade']) ?>" required></label>
                    <button type="button" data-remover>Remover item</button>
                </fieldset>
            <?php endforeach; ?>
        </div>
        <button type="button" id="adicionar">Adicionar item</button>
        <button type="submit">Salvar pedido</button>
    </form>
    <script>
        const linhas = document.querySelector('#linhas');
        const adicionar = document.querySelector('#adicionar');

        function atualizarBotoes() {
            adicionar.disabled = linhas.children.length >= 50;
            linhas.querySelectorAll('[data-remover]').forEach(botao => {
                botao.disabled = linhas.children.length === 1;
            });
        }

        adicionar.addEventListener('click', () => {
            const linha = linhas.firstElementChild.cloneNode(true);
            linha.querySelector('select').value = '';
            linha.querySelector('input').value = '1';
            linhas.append(linha);
            atualizarBotoes();
            linha.querySelector('select').focus();
        });

        linhas.addEventListener('click', evento => {
            if (evento.target.matches('[data-remover]') && linhas.children.length > 1) {
                evento.target.closest('fieldset').remove();
                atualizarBotoes();
                adicionar.focus();
            }
        });

        atualizarBotoes();
    </script>
<?php endif; ?>
</main>
</body>
</html>

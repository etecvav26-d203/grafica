<?php
require_once __DIR__ . '/auth.php';

if (!in_array($modulo ?? '', ['clientes', 'produtos'], true)) {
    http_response_code(404);
    exit;
}

exigirModulo($modulo);
require_once __DIR__ . '/../conexao.php';
$pdo = conexao();
$catalogo = $modulo === 'produtos';
$titulo = $catalogo ? 'Produtos e serviços' : 'Clientes';
$base = url($modulo . '/');
$editar = $acao === 'editar';
$campos = $catalogo
    ? ['nome' => 'Nome', 'tipo' => 'Tipo', 'preco' => 'Preço (R$)', 'estoque' => 'Estoque inicial', 'minimo' => 'Estoque mínimo', 'ativo' => 'Situação']
    : ['nome' => 'Nome', 'email' => 'E-mail', 'telefone' => 'Telefone'];

if ($acao === 'index') {
    $busca = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    $tipo = $_GET['tipo'] ?? '';
    $filtros = [];
    $dados = [];
    if ($busca !== '') {
        $termo = '%' . strtr($busca, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $filtros[] = $catalogo ? "nome LIKE ? ESCAPE '!'" :
            "(nome LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR telefone LIKE ? ESCAPE '!')";
        $dados = array_fill(0, $catalogo ? 1 : 3, $termo);
    }
    if ($catalogo && in_array($tipo, ['produto', 'servico'], true)) {
        $filtros[] = 'tipo = ?';
        $dados[] = $tipo;
    }
    $where = $filtros ? ' WHERE ' . implode(' AND ', $filtros) : '';
    $contagem = $pdo->prepare("SELECT COUNT(*) FROM $modulo$where");
    $contagem->execute($dados);
    $paginas = max(1, (int) ceil($contagem->fetchColumn() / 25));
    $pagina = min($paginas, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1);
    $offset = ($pagina - 1) * 25;
    $consulta = $pdo->prepare("SELECT * FROM $modulo$where ORDER BY nome, id LIMIT 25 OFFSET $offset");
    $consulta->execute($dados);
    $registros = $consulta->fetchAll();

    include __DIR__ . '/header.php';
    ?>
    <h1><?= h($titulo) ?></h1>
    <p><a class="btn" href="<?= h($base . 'cadastrar.php') ?>"><?= $catalogo ? 'Cadastrar produto ou serviço' : 'Cadastrar cliente' ?></a></p>
    <?php if (($_GET['salvo'] ?? '') === '1'): ?><p class="success" role="status">Registro salvo.</p><?php endif; ?>
    <form method="get" action="<?= h($base) ?>">
        <label for="q"><?= $catalogo ? 'Buscar por nome' : 'Buscar por nome, e-mail ou telefone' ?></label>
        <input id="q" name="q" type="search" value="<?= h($busca) ?>">
        <?php if ($catalogo): ?>
            <label for="tipo">Tipo</label>
            <select id="tipo" name="tipo">
                <option value="">Todos</option>
                <option value="produto" <?= $tipo === 'produto' ? 'selected' : '' ?>>Produtos</option>
                <option value="servico" <?= $tipo === 'servico' ? 'selected' : '' ?>>Serviços</option>
            </select>
        <?php endif; ?>
        <button>Buscar</button>
        <a href="<?= h($base) ?>">Limpar filtros</a>
    </form>
    <?php if (!$registros): ?>
        <p>Nenhum registro encontrado.</p>
    <?php else: ?>
        <table>
            <thead><tr>
                <?php foreach ($campos as $campo => $rotulo): ?>
                    <th><?= h($campo === 'estoque' ? 'Em estoque' : $rotulo) ?></th>
                <?php endforeach; ?>
                <th>Ações</th>
            </tr></thead>
            <tbody>
            <?php foreach ($registros as $registro): ?>
                <tr>
                    <?php foreach ($campos as $campo => $rotulo): ?>
                        <?php
                        $valor = $registro[$campo];
                        if ($campo === 'tipo') {
                            $valor = $valor === 'servico' ? 'Serviço' : 'Produto';
                        } elseif ($campo === 'preco') {
                            $valor = 'R$ ' . number_format((float) $valor, 2, ',', '.');
                        } elseif ($campo === 'ativo') {
                            $valor = $valor ? 'Ativo' : 'Inativo';
                        } elseif (in_array($campo, ['estoque', 'minimo'], true) && $registro['tipo'] === 'servico') {
                            $valor = '—';
                        }
                        ?>
                        <td><?= h($valor ?? '—') ?></td>
                    <?php endforeach; ?>
                    <td><a href="<?= h($base . 'editar.php?id=' . $registro['id']) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($paginas > 1): ?>
            <p>
                <?php if ($pagina > 1): ?><a href="<?= h($base . '?' . http_build_query(['q' => $busca, 'tipo' => is_string($tipo) ? $tipo : '', 'pagina' => $pagina - 1])) ?>">Anterior</a><?php endif; ?>
                Página <?= $pagina ?> de <?= $paginas ?>
                <?php if ($pagina < $paginas): ?><a href="<?= h($base . '?' . http_build_query(['q' => $busca, 'tipo' => is_string($tipo) ? $tipo : '', 'pagina' => $pagina + 1])) ?>">Próxima</a><?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    return;
}

$id = null;
$registro = $catalogo
    ? ['nome' => '', 'tipo' => 'produto', 'preco' => '', 'estoque' => '0', 'minimo' => '0', 'ativo' => '1']
    : ['nome' => '', 'email' => '', 'telefone' => ''];
if ($editar) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $consulta = $pdo->prepare("SELECT * FROM $modulo WHERE id = ?");
    $consulta->execute([$id ?: 0]);
    $registro = $consulta->fetch();
    if (!$registro) {
        http_response_code(404);
        exit('Registro não encontrado.');
    }
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $registro['nome'] = trim(campoPost('nome'));
    $limiteNome = $catalogo ? 150 : 120;

    if (!preg_match('/^.{1,' . $limiteNome . '}$/uD', $registro['nome'])) {
        $erro = 'Informe um nome de até ' . $limiteNome . ' caracteres.';
    }

    if ($catalogo) {
        $registro['preco'] = str_replace(',', '.', trim(campoPost('preco')));
        $registro['ativo'] = $editar ? campoPost('ativo') : '1';
        if (!$editar) {
            $registro['tipo'] = campoPost('tipo');
            $registro['estoque'] = trim(campoPost('estoque')) ?: '0';
        }
        $produto = $registro['tipo'] === 'produto';
        $registro['minimo'] = $produto ? (trim(campoPost('minimo')) ?: '0') : '0';
        $estoque = $produto ? $registro['estoque'] : '0';
        $validarInteiro = static function ($valor) {
            return preg_match('/^[0-9]{1,10}$/D', (string) $valor) && $valor <= 2147483647;
        };
        if (!$erro && (!in_array($registro['tipo'], ['produto', 'servico'], true)
            || !in_array($registro['ativo'], ['0', '1'], true)
            || !preg_match('/^[0-9]{1,8}(\.[0-9]{1,2})?$/D', $registro['preco']))) {
            $erro = 'Informe um tipo, uma situação e um preço válido com até duas casas decimais.';
        } elseif (!$erro && (!$validarInteiro($registro['minimo']) || (!$editar && !$validarInteiro($estoque)))) {
            $erro = 'Informe quantidades inteiras maiores ou iguais a zero.';
        }
        if ($editar) {
            $sql = 'UPDATE produtos SET nome = ?, preco = ?, minimo = ?, ativo = ? WHERE id = ?';
            $dados = [$registro['nome'], $registro['preco'], $registro['minimo'], $registro['ativo'], $id];
        } else {
            $sql = 'INSERT INTO produtos (nome, tipo, preco, estoque, minimo) VALUES (?, ?, ?, ?, ?)';
            $dados = [$registro['nome'], $registro['tipo'], $registro['preco'], $estoque, $registro['minimo']];
        }
    } else {
        $registro['email'] = trim(campoPost('email'));
        $registro['telefone'] = trim(campoPost('telefone'));
        if (!$erro && $registro['email'] !== '' && (strlen($registro['email']) > 190 || !filter_var($registro['email'], FILTER_VALIDATE_EMAIL))) {
            $erro = 'Informe um e-mail válido ou deixe o campo em branco.';
        } elseif (!$erro && !preg_match('/^[0-9()+ .-]{0,30}$/D', $registro['telefone'])) {
            $erro = 'Informe um telefone válido de até 30 caracteres.';
        }
        $dados = [$registro['nome'], $registro['email'], $registro['telefone']];
        $sql = 'INSERT INTO clientes (nome, email, telefone) VALUES (?, ?, ?)';
        if ($editar) {
            $sql = 'UPDATE clientes SET nome = ?, email = ?, telefone = ? WHERE id = ?';
            $dados[] = $id;
        }
    }
    if (!$erro) {
        try {
            $pdo->prepare($sql)->execute($dados);
            header('Location: ' . $base . '?salvo=1', true, 303);
            exit;
        } catch (PDOException $falha) {
            $erro = 'Não foi possível salvar o registro. Tente novamente.';
        }
    }
}

include __DIR__ . '/header.php';
?>
<h1><?= $editar ? 'Editar' : 'Cadastrar' ?> <?= $catalogo ? 'produto ou serviço' : 'cliente' ?></h1>
<?php if ($erro): ?><p class="error" role="alert"><?= h($erro) ?></p><?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <label for="nome">Nome</label>
    <input id="nome" name="nome" value="<?= h($registro['nome']) ?>" maxlength="<?= $catalogo ? 150 : 120 ?>" required>
    <?php if ($catalogo): ?>
        <?php if ($editar): ?>
            <p>Tipo: <?= $registro['tipo'] === 'servico' ? 'Serviço' : 'Produto' ?></p>
        <?php else: ?>
            <label for="tipo">Tipo</label>
            <select id="tipo" name="tipo">
                <option value="produto" <?= $registro['tipo'] === 'produto' ? 'selected' : '' ?>>Produto</option>
                <option value="servico" <?= $registro['tipo'] === 'servico' ? 'selected' : '' ?>>Serviço</option>
            </select>
        <?php endif; ?>
        <label for="preco">Preço (R$)</label>
        <input id="preco" name="preco" inputmode="decimal" value="<?= h($registro['preco']) ?>" placeholder="Ex.: 12,50" required>
        <?php if (!$editar || $registro['tipo'] === 'produto'): ?>
            <p>Os campos de estoque se aplicam a produtos físicos.</p>
            <?php if ($editar): ?>
                <p>Saldo atual: <?= h($registro['estoque']) ?>. Para ajustar o saldo, use o módulo Estoque.</p>
            <?php else: ?>
                <label for="estoque">Estoque inicial</label>
                <input id="estoque" name="estoque" type="number" min="0" step="1" value="<?= h($registro['estoque']) ?>">
            <?php endif; ?>
            <label for="minimo">Estoque mínimo</label>
            <input id="minimo" name="minimo" type="number" min="0" step="1" value="<?= h($registro['minimo']) ?>">
        <?php endif; ?>
        <?php if ($editar): ?>
            <label for="ativo">Situação</label>
            <select id="ativo" name="ativo">
                <option value="1" <?= $registro['ativo'] ? 'selected' : '' ?>>Ativo</option>
                <option value="0" <?= !$registro['ativo'] ? 'selected' : '' ?>>Inativo</option>
            </select>
        <?php endif; ?>
    <?php else: ?>
        <label for="email">E-mail (opcional)</label>
        <input id="email" name="email" type="email" maxlength="190" value="<?= h($registro['email']) ?>">
        <label for="telefone">Telefone (opcional)</label>
        <input id="telefone" name="telefone" type="tel" maxlength="30" value="<?= h($registro['telefone']) ?>">
    <?php endif; ?>
    <button>Salvar</button>
    <a href="<?= h($base) ?>">Voltar</a>
</form>
</main>
</body>
</html>

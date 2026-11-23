<?php
require_once __DIR__ . '/auth.php';
exigirModulo('funcionarios');
require_once __DIR__ . '/../conexao.php';
$editar = $editar ?? false;

$pdo = conexao();
$cargos = [
    'administrador' => 'Administrador',
    'gerente' => 'Gerente',
    'atendente' => 'Atendente',
    'producao' => 'Produção',
];
$funcionario = ['nome' => '', 'email' => '', 'cargo' => 'atendente', 'ativo' => 1];
$id = null;
if ($editar) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $consulta = $pdo->prepare('SELECT nome, email, cargo, ativo FROM funcionarios WHERE id = ?');
    $consulta->execute([$id ?: 0]);
    $funcionario = $consulta->fetch();
    if (!$funcionario) {
        http_response_code(404);
        exit('Funcionário não encontrado.');
    }
}

$propriaConta = $editar && $id === (int) usuario()['id'];
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $funcionario = [
        'nome' => trim(campoPost('nome')),
        'email' => strtolower(trim(campoPost('email'))),
        'cargo' => $propriaConta ? 'administrador' : campoPost('cargo'),
        'ativo' => (!$editar || $propriaConta || campoPost('ativo') === '1') ? 1 : 0,
    ];
    $senha = campoPost('senha');
    $senhaNecessaria = !$editar || $senha !== '';

    if ($funcionario['nome'] === '' || !preg_match('/^.{1,120}$/usD', $funcionario['nome'])
        || strlen($funcionario['email']) > 190 || !filter_var($funcionario['email'], FILTER_VALIDATE_EMAIL)
        || !isset($cargos[$funcionario['cargo']])) {
        $erro = 'Informe um nome, um e-mail válido e um cargo da lista.';
    } elseif ($senhaNecessaria && (strlen($senha) < 10 || strlen($senha) > 72 || strpos($senha, "\0") !== false)) {
        $erro = 'Use uma senha de 10 a 72 bytes.';
    } else {
        $dados = array_values($funcionario);
        try {
            $pdo->beginTransaction();
            $administradores = $pdo->query(
                "SELECT id FROM funcionarios WHERE cargo = 'administrador' AND ativo = 1 ORDER BY id FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array((string) usuario()['id'], array_map('strval', $administradores), true)) {
                $pdo->rollBack();
                http_response_code(403);
                exit('Acesso negado.');
            }

            if ($editar) {
                $sql = 'UPDATE funcionarios SET nome = ?, email = ?, cargo = ?, ativo = ?';
                if ($senhaNecessaria) {
                    $sql .= ', senha = ?';
                    $dados[] = password_hash($senha, PASSWORD_DEFAULT);
                }
                $dados[] = $id;
                $pdo->prepare($sql . ' WHERE id = ?')->execute($dados);
            } else {
                $dados[] = password_hash($senha, PASSWORD_DEFAULT);
                $pdo->prepare('INSERT INTO funcionarios (nome, email, cargo, ativo, senha) VALUES (?, ?, ?, ?, ?)')->execute($dados);
            }
            $pdo->commit();
            header('Location: ' . url('funcionarios/'), true, 303);
            exit;
        } catch (PDOException $falha) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erro = ($falha->errorInfo[1] ?? null) == 1062
                ? 'Esse e-mail já está cadastrado.'
                : 'Não foi possível salvar o funcionário. Tente novamente.';
        }
    }
}

include __DIR__ . '/header.php';
?>
<h1><?= $editar ? 'Editar funcionário' : 'Cadastrar funcionário' ?></h1>
<?php if ($erro): ?><p class="error" role="alert"><?= h($erro) ?></p><?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <label for="nome">Nome</label>
    <input id="nome" name="nome" maxlength="120" value="<?= h($funcionario['nome']) ?>" required>
    <label for="email">E-mail</label>
    <input id="email" type="email" name="email" maxlength="190" value="<?= h($funcionario['email']) ?>" required>
    <label for="senha"><?= $editar ? 'Nova senha (opcional)' : 'Senha' ?></label>
    <input id="senha" type="password" name="senha" minlength="10" maxlength="72" autocomplete="new-password" <?= $editar ? '' : 'required' ?>>
    <?php if ($propriaConta): ?>
         <p>Cargo atual: Administrador. Sua conta permanece ativa ao salvar.</p>
    <?php else: ?>
        <label for="cargo">Cargo</label>
        <select id="cargo" name="cargo" required>
            <?php if (!isset($cargos[$funcionario['cargo']])): ?>
                <option value="" disabled selected>Selecione um cargo</option>
            <?php endif; ?>
            <?php foreach ($cargos as $valor => $rotulo): ?>
                <option value="<?= h($valor) ?>" <?= $funcionario['cargo'] === $valor ? 'selected' : '' ?>><?= h($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($editar): ?>
            <label for="ativo">Situação</label>
            <select id="ativo" name="ativo">
                <option value="1" <?= $funcionario['ativo'] ? 'selected' : '' ?>>Ativo</option>
                <option value="0" <?= !$funcionario['ativo'] ? 'selected' : '' ?>>Inativo</option>
            </select>
        <?php endif; ?>
    <?php endif; ?>
    <button>Salvar</button>
    <a href="<?= h(url('funcionarios/')) ?>">Voltar</a>
</form>
</main>
</body>
</html>
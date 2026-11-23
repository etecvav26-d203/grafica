<?php
require_once __DIR__ . '/includes/auth.php';

if (usuario()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    require_once __DIR__ . '/conexao.php';
    $consulta = conexao()->prepare('SELECT id, senha FROM funcionarios WHERE email = ? AND ativo = 1');
    $consulta->execute([strtolower(trim(campoPost('email')))]);
    $conta = $consulta->fetch();

    if ($conta && password_verify(campoPost('senha'), $conta['senha'])) {
        session_regenerate_id(true);
        $_SESSION = ['usuario' => ['id' => $conta['id']]];
        header('Location: ' . url('dashboard.php'), true, 303);
        exit;
    }
    $erro = 'E-mail ou senha inválidos.';
}

include __DIR__ . '/includes/header.php';
?>
<h1>Entrar no sistema</h1>
<?php if (($_GET['instalado'] ?? '') === '1'): ?>
    <p class="success">Administrador criado. Entre com a conta que acabou de cadastrar e remova instalar_admin.php da pasta do projeto.</p>
<?php endif; ?>
<?php if ($erro): ?><p class="error" role="alert"><?= h($erro) ?></p><?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <label for="email">E-mail</label>
    <input id="email" type="email" name="email" autocomplete="username" required>
    <label for="senha">Senha</label>
    <input id="senha" type="password" name="senha" autocomplete="current-password" required>
    <button>Entrar</button>
</form>
</main>
</body>
</html>

<?php
require_once __DIR__ . '/includes/auth.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(403);
    exit('Abra a instalação pelo localhost do computador que executa o XAMPP.');
}

require_once __DIR__ . '/conexao.php';
$pdo = conexao();

function verificarInstalacao($pdo)
{
    if ($pdo->query('SELECT COUNT(*) FROM funcionarios')->fetchColumn() > 0) {
        http_response_code(403);
        exit('A instalação inicial já foi encerrada. Acesse login.php.');
    }
}

verificarInstalacao($pdo);
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $nome = trim(campoPost('nome'));
    $email = strtolower(trim(campoPost('email')));
    $senha = campoPost('senha');
    if (!preg_match('/^.{1,120}$/usD', $nome) || strlen($email) > 190
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 10
        || strlen($senha) > 72 || strpos($senha, "\0") !== false) {
        $erro = 'Informe nome, e-mail válido e senha de 10 a 72 bytes.';
    } else {
        $lock = $pdo->query("SELECT GET_LOCK('sgg_instalar_admin', 5)")->fetchColumn();
        if ((int) $lock !== 1) {
            http_response_code(503);
            exit('Instalação ocupada. Tente novamente.');
        }
        try {
            verificarInstalacao($pdo);
            $consulta = $pdo->prepare("INSERT INTO funcionarios (nome, email, senha, cargo) VALUES (?, ?, ?, 'administrador')");
            $consulta->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('sgg_instalar_admin')");
        }
        header('Location: ' . url('login.php?instalado=1'), true, 303);
        exit;
    }
}
include __DIR__ . '/includes/header.php';
?>
<h1>Configurar primeiro administrador</h1>
<?php if ($erro): ?><p class="error" role="alert"><?= h($erro) ?></p><?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <label for="nome">Nome</label>
    <input id="nome" name="nome" maxlength="120" required>
    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" maxlength="190" required>
    <label for="senha">Senha</label>
    <input id="senha" name="senha" type="password" minlength="10" maxlength="72" autocomplete="new-password" required>
    <button>Criar administrador</button>
</form>
</main>
</body>
</html>

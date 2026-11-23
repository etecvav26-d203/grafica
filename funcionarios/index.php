<?php
require_once __DIR__ . '/../includes/auth.php';
exigirModulo('funcionarios');
require_once __DIR__ . '/../conexao.php';

$equipe = conexao()->query('SELECT id, nome, email, cargo, ativo FROM funcionarios ORDER BY nome')->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<h1>Equipe</h1>
<p><a class="btn" href="<?= h(url('funcionarios/cadastrar.php')) ?>">Cadastrar funcionário</a></p>
<table>
    <thead><tr><th>Nome</th><th>E-mail</th><th>Cargo</th><th>Situação</th><th>Ações</th></tr></thead>
    <tbody>
    <?php foreach ($equipe as $funcionario): ?>
        <tr>
            <td><?= h($funcionario['nome']) ?></td>
            <td><?= h($funcionario['email']) ?></td>
            <td><?= h($funcionario['cargo']) ?></td>
            <td><?= $funcionario['ativo'] ? 'Ativo' : 'Inativo' ?></td>
            <td><a href="<?= h(url('funcionarios/editar.php?id=' . $funcionario['id'])) ?>">Editar</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</main>
</body>
</html>

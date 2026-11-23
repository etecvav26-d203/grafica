<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'use_strict_mode' => 1,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

function url($path = '')
{
    return '/grafica/' . ltrim($path, '/');
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function usuario()
{
    static $carregado = false;
    static $usuario = null;

    if ($carregado || empty($_SESSION['usuario']['id'])) {
        return $usuario;
    }

    require_once __DIR__ . '/../conexao.php';
    $pdo = conexao();
    $consulta = $pdo->prepare('SELECT id, nome, cargo FROM funcionarios WHERE id = ? AND ativo = 1');
    $consulta->execute([$_SESSION['usuario']['id']]);
    $usuario = $consulta->fetch() ?: null;
    $carregado = true;

    if (!$usuario) {
        unset($_SESSION['usuario'], $_SESSION['csrf']);
    }

    return $usuario;
}

function exigirLogin()
{
    if (!usuario()) {
        header('Location: ' . url('login.php'));
        exit;
    }
}

function exigirCargo($cargos)
{
    exigirLogin();
    if (!in_array(usuario()['cargo'], (array) $cargos, true)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

function modulos()
{
    return [
        'clientes' => ['Clientes', ['administrador', 'gerente', 'atendente']],
        'produtos' => ['Produtos e serviços', ['administrador', 'gerente', 'estoquista']],
        'pedidos' => ['Pedidos', ['administrador', 'gerente', 'atendente', 'producao', 'estoquista']],
        'estoque' => ['Estoque', ['administrador', 'gerente', 'producao', 'estoquista']],
        'financeiro' => ['Financeiro', ['administrador', 'gerente']],
        'relatorios' => ['Relatórios', ['administrador', 'gerente']],
        'funcionarios' => ['Equipe', ['administrador']],
    ];
}

function exigirModulo($modulo)
{
    exigirCargo(modulos()[$modulo][1]);
}

function modulosVisiveis()
{
    $cargo = usuario()['cargo'] ?? null;
    return array_filter(modulos(), static function ($modulo) use ($cargo) {
        return in_array($cargo, $modulo[1], true);
    });
}

function csrf()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function verificarCsrf()
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf(), $token)) {
        http_response_code(419);
        exit('Formulário expirado. Atualize a página e tente novamente.');
    }
}

function campoPost($campo)
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? $valor : '';
}

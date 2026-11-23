<?php
require_once __DIR__ . '/../includes/auth.php';
exigirModulo('pedidos');
require_once __DIR__ . '/../conexao.php';

$pdo = conexao();
$cargoPedido = usuario()['cargo'];
$gestaoPedidos = in_array($cargoPedido, ['administrador', 'gerente'], true);
$podeCriarPedido = $gestaoPedidos || $cargoPedido === 'atendente';
$statusPedidos = [
    'recebido' => 'Recebido',
    'producao' => 'Em produção',
    'pronto' => 'Pronto',
    'entregue' => 'Entregue',
    'cancelado' => 'Cancelado',
];

function realPedido($valor)
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

function inteiroPedido($valor)
{
    return filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
}

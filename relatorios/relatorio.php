<?php
require_once __DIR__ . '/../includes/auth.php';
exigirModulo('relatorios');
require_once __DIR__ . '/../conexao.php';

function dataApm($valor)
{
    if (!is_string($valor) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $valor)) {
        return null;
    }
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $data && $data->format('Y-m-d') === $valor ? $data : null;
}

function dinheiroApm($valor)
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

function dadosApm(PDO $pdo, DateTimeImmutable $inicio, DateTimeImmutable $fim)
{
    $de = $inicio->format('Y-m-d');
    $ate = $fim->modify('+1 day')->format('Y-m-d');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $consulta = $pdo->prepare(
            "SELECT COUNT(*) AS pedidos,
                    COALESCE(SUM(p.status = 'cancelado'), 0) AS cancelados,
                    COALESCE(SUM(CASE WHEN p.status <> 'cancelado' THEN p.total ELSE 0 END), 0) AS vendido,
                    COALESCE(SUM(CASE WHEN p.status <> 'cancelado'
                        THEN GREATEST(p.total - COALESCE(pg.recebido, 0), 0) ELSE 0 END), 0) AS saldo
             FROM pedidos p
             LEFT JOIN (
                 SELECT pedido_id, SUM(valor) AS recebido FROM pagamentos WHERE pago_em < ? GROUP BY pedido_id
             ) pg ON pg.pedido_id = p.id
             WHERE p.criado_em >= ? AND p.criado_em < ?"
        );
        $consulta->execute([$ate, $de, $ate]);
        $resumo = $consulta->fetch();

        $consulta = $pdo->prepare(
            "SELECT pg.forma, COUNT(*) AS quantidade, SUM(pg.valor) AS total
             FROM pagamentos pg WHERE pg.pago_em >= ? AND pg.pago_em < ?
             GROUP BY pg.forma ORDER BY pg.forma"
        );
        $consulta->execute([$de, $ate]);
        $recebimentos = $consulta->fetchAll();
        $consulta = $pdo->prepare('SELECT COALESCE(SUM(valor), 0) FROM pagamentos WHERE pago_em >= ? AND pago_em < ?');
        $consulta->execute([$de, $ate]);
        $resumo['recebido'] = $consulta->fetchColumn();

        $consulta = $pdo->prepare(
            "SELECT pr.nome, pr.tipo, SUM(i.quantidade) AS quantidade, SUM(i.quantidade * i.preco_unitario) AS total
             FROM pedido_itens i JOIN pedidos p ON p.id = i.pedido_id
             JOIN produtos pr ON pr.id = i.produto_id
             WHERE p.criado_em >= ? AND p.criado_em < ? AND p.status <> 'cancelado'
             GROUP BY pr.id, pr.nome, pr.tipo ORDER BY pr.nome, pr.id"
        );
        $consulta->execute([$de, $ate]);
        $itens = $consulta->fetchAll();
        $pdo->commit();
    } catch (Throwable $erro) {
        $pdo->rollBack();
        throw $erro;
    }
    return [$resumo, $recebimentos, $itens];
}

$hoje = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
$inicio = dataApm($_GET['inicio'] ?? $hoje->format('Y-m-01'));
$fim = dataApm($_GET['fim'] ?? $hoje->format('Y-m-d'));
$erroPeriodo = !$inicio || !$fim || $inicio > $fim || $fim->format('Y-m-d') === '9999-12-31';
$formasApm = ['pix' => 'Pix', 'dinheiro' => 'Dinheiro', 'cartao' => 'Cartão'];

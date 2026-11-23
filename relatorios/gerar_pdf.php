<?php
require __DIR__ . '/relatorio.php';
require __DIR__ . '/pdf_apm.php';

if ($erroPeriodo) {
    http_response_code(400);
    exit('Período inválido.');
}

[$resumo, $recebimentos, $itens] = dadosApm(conexao(), $inicio, $fim);
$periodo = $inicio->format('d/m/Y') . ' a ' . $fim->format('d/m/Y');
$linhas = [
    'Resumo',
    'Pedidos válidos: ' . ($resumo['pedidos'] - $resumo['cancelados']),
    'Pedidos cancelados: ' . $resumo['cancelados'],
    'Valor dos pedidos válidos: ' . dinheiroApm($resumo['vendido']),
    'Recebido no período: ' . dinheiroApm($resumo['recebido']),
    'Saldo dos pedidos válidos no fim do período: ' . dinheiroApm($resumo['saldo']),
    '',
    'Pedidos pela data de cadastro e situação atual. Recebimentos pela data de pagamento, inclusive de pedidos anteriores.',
    '',
    'Recebimentos por forma de pagamento',
];
foreach ($recebimentos as $linha) {
    $linhas[] = ($formasApm[$linha['forma']] ?? $linha['forma']) . ' | ' . $linha['quantidade']
        . ' pagamento(s) | ' . dinheiroApm($linha['total']);
}
if (!$recebimentos) {
    $linhas[] = 'Nenhum recebimento no período.';
}

$linhas[] = '';
$linhas[] = 'Produtos e serviços dos pedidos válidos';
foreach ($itens as $linha) {
    $linhas[] = $linha['nome'] . ' | ' . ($linha['tipo'] === 'servico' ? 'Serviço' : 'Produto')
        . ' | ' . $linha['quantidade'] . ' unidade(s) | ' . dinheiroApm($linha['total']);
}
if (!$itens) {
    $linhas[] = 'Nenhum item no período.';
}

$pdf = montarPdfApm($periodo, $linhas);
$arquivo = 'SGG_APM_' . $inicio->format('Y-m-d') . '_a_' . $fim->format('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;

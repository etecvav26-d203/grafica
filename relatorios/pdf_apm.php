<?php

function textoPdfApm($texto)
{
    return '(' . addcslashes($texto, "\\()") . ')';
}

function montarPdfApm($periodo, array $linhas)
{
    $corpo = [];
    foreach ($linhas as $linha) {
        $linha = preg_replace('/[\x00-\x1F\x7F]/', ' ', $linha);
        $linha = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $linha);
        foreach (explode("\n", wordwrap($linha, 83, "\n", true)) as $parte) {
            $corpo[] = $parte;
        }
    }
    $paginas = array_chunk($corpo, 47);
    $objetos = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $referencias = [];
    foreach ($paginas as $indice => $pagina) {
        $id = count($objetos) + 1;
        $referencias[] = "$id 0 R";
        $titulo = textoPdfApm(iconv('UTF-8', 'Windows-1252', 'Relatório para a APM'));
        $conteudo = "0.11 0.19 0.31 rg\nBT /F2 20 Tf 42 789 Td $titulo Tj ET\n";
        $conteudo .= 'BT /F1 10 Tf 42 764 Td ' . textoPdfApm('SGG | Periodo: ' . $periodo) . " Tj ET\n";
        $conteudo .= "0.11 0.19 0.31 RG 42 750 m 553 750 l S\n";
        $conteudo .= "0.10 0.12 0.16 rg\nBT /F1 10 Tf 42 731 Td 14 TL\n";
        foreach ($pagina as $linha) {
            $conteudo .= textoPdfApm($linha) . " Tj T*\n";
        }
        $conteudo .= "ET\nBT /F1 9 Tf 42 40 Td "
            . textoPdfApm('Pagina ' . ($indice + 1) . ' de ' . count($paginas)) . " Tj ET\n";
        $objetos[$id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
            . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ($id + 1) . ' 0 R >>';
        $objetos[$id + 1] = '<< /Length ' . strlen($conteudo) . " >>\nstream\n" . $conteudo . 'endstream';
    }
    $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $referencias) . '] /Count ' . count($paginas) . ' >>';

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objetos as $id => $objeto) {
        $offsets[] = strlen($pdf);
        $pdf .= "$id 0 obj\n$objeto\nendobj\n";
    }
    $xref = strlen($pdf);
    $tamanho = count($objetos) + 1;
    $pdf .= "xref\n0 $tamanho\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size $tamanho /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

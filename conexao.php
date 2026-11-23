<?php

require_once __DIR__ . '/config.php';

function conexao()
{
    static $conexao = null;

    if (!$conexao) {
        try {
            $conexao = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $erro) {
            http_response_code(500);
            exit('Falha na conexão com o banco. Verifique config.php e a importação do SQL.');
        }
    }

    return $conexao;
}


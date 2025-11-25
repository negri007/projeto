<?php
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=banco;charset=utf8mb4",
        "root",
        ""
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Conexão OK!";
} catch (Exception $e) {
    echo "Erro ao conectar: " . $e->getMessage();
}

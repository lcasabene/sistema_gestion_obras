<?php
// Conexión PDO centralizada
// Ajustar según tu entorno
$db_host = '127.0.0.1';
$db_name = 'gestion_obras_1';
$db_user = 'root';
$db_pass = '';
$db_charset = 'utf8mb4';

$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    $pdo->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {
    http_response_code(500);
    echo "Error de conexión a la base de datos.";
    if (defined('APP_ENV') && APP_ENV === 'dev') {
        echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    }
    exit;
}

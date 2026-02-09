<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $codigo = trim($_POST['codigo'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');

    if (empty($codigo) || empty($nombre)) {
        die("Error: El código y el nombre son obligatorios.");
    }

    try {
        if ($id === 0) {
            // INSERTAR
            $sql = "INSERT INTO fuentes_financiamiento (codigo, nombre, activo) VALUES (?, ?, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$codigo, $nombre]);
        } else {
            // ACTUALIZAR
            $sql = "UPDATE fuentes_financiamiento SET codigo = ?, nombre = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$codigo, $nombre, $id]);
        }

        header("Location: fuentes_listado.php?msg=guardado");
        exit;

    } catch (PDOException $e) {
        die("Error de Base de Datos: " . $e->getMessage());
    }
} else {
    header("Location: fuentes_listado.php");
    exit;
}
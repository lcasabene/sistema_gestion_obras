<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // Borrado lógico: No eliminamos el registro, solo lo desactivamos
    $stmt = $pdo->prepare("UPDATE fuentes_financiamiento SET activo = 0 WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: fuentes_listado.php?msg=eliminado");
exit;
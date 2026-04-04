<?php
// organismos_eliminar.php - Baja lógica de organismo
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: organismos_listado.php?msg=error");
    exit;
}

try {
    $pdo->beginTransaction();

    // Desactivar líneas de crédito asociadas
    try {
        $pdo->prepare("UPDATE lineas_credito SET activo = 0 WHERE organismo_id = ?")->execute([$id]);
    } catch (Exception $e) { /* table may not exist */ }

    // Desactivar organismo
    $stmt = $pdo->prepare("UPDATE organismos_financiadores SET activo = 0 WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->commit();
    header("Location: organismos_listado.php?msg=eliminado");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("Error al dar de baja: " . $e->getMessage());
}

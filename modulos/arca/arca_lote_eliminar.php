<?php
// arca_lote_eliminar.php - Elimina un lote de importación y sus comprobantes
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: arca_import.php");
    exit;
}

$lote_id = (int)($_POST['lote_id'] ?? 0);
if ($lote_id <= 0) {
    header("Location: arca_import.php?error=id_invalido");
    exit;
}

try {
    // Verificar que el lote existe y no está eliminado
    $lote = $pdo->prepare("SELECT * FROM lotes_importacion_arca WHERE id = ? AND eliminado = 0");
    $lote->execute([$lote_id]);
    $lote = $lote->fetch();

    if (!$lote) {
        header("Location: arca_import.php?error=lote_no_encontrado");
        exit;
    }

    // Verificar que ningún comprobante del lote está vinculado a una liquidación
    $vinculados = $pdo->prepare("
        SELECT COUNT(*) FROM comprobantes_arca 
        WHERE lote_id = ? AND estado_uso = 'VINCULADO'
    ");
    $vinculados->execute([$lote_id]);
    $count_vinculados = (int)$vinculados->fetchColumn();

    if ($count_vinculados > 0) {
        header("Location: arca_import.php?error=tiene_vinculados&vinculados=$count_vinculados");
        exit;
    }

    $pdo->beginTransaction();

    // Eliminar los comprobantes del lote
    $eliminados = $pdo->prepare("DELETE FROM comprobantes_arca WHERE lote_id = ?");
    $eliminados->execute([$lote_id]);
    $rows_deleted = $eliminados->rowCount();

    // Marcar el lote como eliminado (soft delete)
    $pdo->prepare("UPDATE lotes_importacion_arca SET eliminado = 1 WHERE id = ?")
        ->execute([$lote_id]);

    $pdo->commit();

    header("Location: arca_import.php?ok=lote_eliminado&rows=$rows_deleted");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error eliminando lote ARCA: " . $e->getMessage());
    header("Location: arca_import.php?error=db");
    exit;
}

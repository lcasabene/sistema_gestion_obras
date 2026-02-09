<?php
// modulos/certificados/certificados_eliminar.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        $pdo->beginTransaction();

        // 1. Liberar facturas vinculadas (volverlas a DISPONIBLE)
        $pdo->prepare("UPDATE comprobantes_arca SET estado_uso='DISPONIBLE' WHERE id IN (SELECT comprobante_arca_id FROM certificados_facturas WHERE certificado_id=?)")->execute([$id]);

        // 2. Borrar relaciones (Facturas y Financiamiento)
        // Nota: Si tienes ON DELETE CASCADE en la BD esto se hace solo, pero por seguridad lo hacemos manual
        $pdo->prepare("DELETE FROM certificados_facturas WHERE certificado_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM certificados_financiamiento WHERE certificado_id = ?")->execute([$id]);

        // 3. Borrar el certificado
        $stmt = $pdo->prepare("DELETE FROM certificados WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
        header("Location: certificados_listado.php?msg=deleted");

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error al eliminar: " . $e->getMessage());
    }
} else {
    header("Location: certificados_listado.php?msg=error");
}
?>
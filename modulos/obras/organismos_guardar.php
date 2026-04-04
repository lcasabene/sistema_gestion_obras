<?php
// organismos_guardar.php - Con Líneas de Crédito
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: organismos_listado.php");
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$nombre_organismo = trim($_POST['nombre_organismo'] ?? '');
$descripcion_programa = trim($_POST['descripcion_programa'] ?? '');

if (empty($nombre_organismo)) {
    header("Location: organismos_form.php?id=$id&error=campos_vacios");
    exit;
}

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $sql = "UPDATE organismos_financiadores SET nombre_organismo = ?, descripcion_programa = ? WHERE id = ? AND activo = 1";
        $pdo->prepare($sql)->execute([$nombre_organismo, $descripcion_programa, $id]);
    } else {
        $sql = "INSERT INTO organismos_financiadores (nombre_organismo, descripcion_programa, activo) VALUES (?, ?, 1)";
        $pdo->prepare($sql)->execute([$nombre_organismo, $descripcion_programa]);
        $id = (int)$pdo->lastInsertId();
    }

    // --- LÍNEAS DE CRÉDITO ---
    $linea_ids = $_POST['linea_id'] ?? [];
    $linea_codigos = $_POST['linea_codigo'] ?? [];
    $linea_descripciones = $_POST['linea_descripcion'] ?? [];

    // Obtener IDs existentes para detectar eliminados
    $existentes = $pdo->prepare("SELECT id FROM lineas_credito WHERE organismo_id = ? AND activo = 1");
    $existentes->execute([$id]);
    $ids_existentes = $existentes->fetchAll(PDO::FETCH_COLUMN);

    $ids_enviados = [];
    $stmtInsert = $pdo->prepare("INSERT INTO lineas_credito (organismo_id, codigo, descripcion, activo) VALUES (?, ?, ?, 1)");
    $stmtUpdate = $pdo->prepare("UPDATE lineas_credito SET codigo = ?, descripcion = ? WHERE id = ? AND organismo_id = ?");

    for ($i = 0; $i < count($linea_codigos); $i++) {
        $codigo = trim($linea_codigos[$i] ?? '');
        $desc = trim($linea_descripciones[$i] ?? '');
        $lid = (int)($linea_ids[$i] ?? 0);

        if (empty($codigo)) continue;

        if ($lid > 0 && in_array($lid, $ids_existentes)) {
            $stmtUpdate->execute([$codigo, $desc, $lid, $id]);
            $ids_enviados[] = $lid;
        } else {
            $stmtInsert->execute([$id, $codigo, $desc]);
            $ids_enviados[] = (int)$pdo->lastInsertId();
        }
    }

    // Desactivar líneas eliminadas del formulario
    $ids_borrar = array_diff($ids_existentes, $ids_enviados);
    if (!empty($ids_borrar)) {
        $in = implode(',', array_map('intval', $ids_borrar));
        $pdo->exec("UPDATE lineas_credito SET activo = 0 WHERE id IN ($in)");
    }

    $pdo->commit();
    header("Location: organismos_listado.php?msg=ok");
    exit;

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Error en organismos_guardar: " . $e->getMessage());
    header("Location: organismos_form.php?id=$id&error=db");
    exit;
}
<?php
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_login();

$mensaje = '';
$tipo_alerta = '';
$lote_id_nuevo = null;

// Función para limpiar montos (1.000,00 -> 1000.00)
function limpiarMonto($val) {
    if (!$val) return 0;
    $val = str_replace('.', '', $val);
    $val = str_replace(',', '.', $val);
    return (float)$val;
}

// Detectar si la columna lote_id existe
$tiene_lote_col = false;
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM comprobantes_arca")->fetchAll(), 'Field');
    $tiene_lote_col = in_array('lote_id', $cols);
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_arca'])) {
    if ($_FILES['csv_arca']['error'] === UPLOAD_ERR_OK) {
        $tmpName  = $_FILES['csv_arca']['tmp_name'];
        $fileName = $_FILES['csv_arca']['name'];

        // Detectar delimitador
        $linea1     = fgets(fopen($tmpName, 'r'));
        $delimitador = (strpos($linea1, ';') !== false) ? ';' : ',';

        $handle    = fopen($tmpName, "r");
        $importados = 0;
        $duplicados = 0;
        $errores    = 0;

        // Crear lote de importación
        $lote_id = null;
        if ($tiene_lote_col) {
            try {
                $pdo->prepare("INSERT INTO lotes_importacion_arca (nombre_archivo, usuario, total_importados, total_duplicados, total_errores) VALUES (?, ?, 0, 0, 0)")
                    ->execute([$fileName, $_SESSION['username'] ?? 'sistema']);
                $lote_id = (int)$pdo->lastInsertId();
                $lote_id_nuevo = $lote_id;
            } catch (Exception $e) { /* tabla puede no existir aún */ }
        }

        // SQL VERIFICAR existencia
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM comprobantes_arca 
                     WHERE cuit_emisor = :cuitE AND tipo_comprobante = :tipo 
                     AND punto_venta = :pv AND numero = :nro AND importe_total = :total");

        // SQL INSERTAR (con o sin lote_id)
        if ($lote_id) {
            $sqlInsert = "INSERT INTO comprobantes_arca 
                (lote_id, fecha, tipo_comprobante, punto_venta, numero, cuit_emisor, nombre_emisor, cuit_receptor, importe_neto, importe_iva, importe_total, cae) 
                VALUES (:lote, :fecha, :tipo, :pv, :nro, :cuitE, :nomE, :cuitR, :neto, :iva, :total, :cae)";
        } else {
            $sqlInsert = "INSERT INTO comprobantes_arca 
                (fecha, tipo_comprobante, punto_venta, numero, cuit_emisor, nombre_emisor, cuit_receptor, importe_neto, importe_iva, importe_total, cae) 
                VALUES (:fecha, :tipo, :pv, :nro, :cuitE, :nomE, :cuitR, :neto, :iva, :total, :cae)";
        }
        $stmtInsert = $pdo->prepare($sqlInsert);

        while (($data = fgetcsv($handle, 2000, $delimitador)) !== FALSE) {
            if (stripos($data[0], 'Fecha') !== false) continue;
            if (count($data) < 10) continue;

            try {
                $fechaRaw  = trim($data[0]);
                if (strpos($fechaRaw, '/') !== false) {
                    $d = DateTime::createFromFormat('d/m/Y', $fechaRaw);
                    $fechaFinal = $d ? $d->format('Y-m-d') : null;
                } else {
                    $fechaFinal = $fechaRaw;
                }
                if (!$fechaFinal) continue;

                $tipo_cbte = utf8_encode($data[1]);
                $pto_venta = (int)$data[2];
                $numero    = $data[3];
                $cuit_emi  = preg_replace('/[^0-9]/', '', $data[7]);
                $nom_emi   = utf8_encode($data[8]);
                $cuit_rec  = preg_replace('/[^0-9]/', '', $data[10]);
                $imp_neto  = limpiarMonto($data[24] ?? 0);
                $imp_iva   = limpiarMonto($data[28] ?? 0);
                $imp_total = limpiarMonto($data[29] ?? 0);
                $cae_val   = $data[5];

                $stmtCheck->execute([':cuitE'=>$cuit_emi,':tipo'=>$tipo_cbte,':pv'=>$pto_venta,':nro'=>$numero,':total'=>$imp_total]);
                if ($stmtCheck->fetchColumn() > 0) { $duplicados++; continue; }

                $params = [':fecha'=>$fechaFinal,':tipo'=>$tipo_cbte,':pv'=>$pto_venta,':nro'=>$numero,
                           ':cuitE'=>$cuit_emi,':nomE'=>$nom_emi,':cuitR'=>$cuit_rec,
                           ':neto'=>$imp_neto,':iva'=>$imp_iva,':total'=>$imp_total,':cae'=>$cae_val];
                if ($lote_id) $params[':lote'] = $lote_id;
                $stmtInsert->execute($params);
                $importados++;

            } catch (Exception $e) { $errores++; }
        }
        fclose($handle);

        // Actualizar totales del lote
        if ($lote_id) {
            try {
                $pdo->prepare("UPDATE lotes_importacion_arca SET total_importados=?, total_duplicados=?, total_errores=? WHERE id=?")
                    ->execute([$importados, $duplicados, $errores, $lote_id]);
            } catch (Exception $e) {}
        }

        $mensaje  = "Proceso finalizado.<br>";
        $mensaje .= "<span class='text-success fw-bold'>$importados</span> nuevos comprobantes importados.<br>";
        if ($duplicados > 0) $mensaje .= "<span class='text-warning fw-bold'>$duplicados</span> ya existían (omitidos).<br>";
        if ($errores > 0)    $mensaje .= "<span class='text-danger'>$errores</span> errores de formato.";
        $tipo_alerta = ($importados > 0) ? "success" : "warning";

    } else {
        $mensaje = "Error al subir el archivo.";
        $tipo_alerta = "danger";
    }
}

// Mensajes desde redirect (eliminación de lote)
if (empty($mensaje) && isset($_GET['ok'])) {
    if ($_GET['ok'] === 'lote_eliminado') {
        $rows = (int)($_GET['rows'] ?? 0);
        $mensaje = "<strong>Lote eliminado correctamente.</strong> Se borraron $rows comprobantes.";
        $tipo_alerta = "success";
    }
}
if (empty($mensaje) && isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'tiene_vinculados':
            $v = (int)($_GET['vinculados'] ?? 0);
            $mensaje = "No se puede eliminar: <strong>$v comprobante(s)</strong> de este lote ya fueron usados en liquidaciones.";
            $tipo_alerta = "warning";
            break;
        case 'lote_no_encontrado':
            $mensaje = "Lote no encontrado.";
            $tipo_alerta = "danger";
            break;
        default:
            $mensaje = "Ocurrió un error al eliminar el lote.";
            $tipo_alerta = "danger";
    }
}

// Cargar historial de lotes
$lotes = [];
try {
    $lotes = $pdo->query("
        SELECT l.*,
               (SELECT COUNT(*) FROM comprobantes_arca c 
                WHERE c.lote_id = l.id AND c.estado_uso = 'VINCULADO') AS vinculados
        FROM lotes_importacion_arca l
        WHERE l.eliminado = 0
        ORDER BY l.fecha_importacion DESC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar ARCA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<?php include __DIR__ . '/../../public/_header.php'; ?>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0"><i class="bi bi-cloud-upload"></i> Importar Comprobantes (ARCA)</h3>
            <p class="text-muted small mb-0">Cargue el CSV descargado desde AFIP "Mis Comprobantes Recibidos"</p>
        </div>
        <div>
            <a href="facturas_listado.php" class="btn btn-outline-primary me-2">
                <i class="bi bi-table me-1"></i> Ver listado de facturas
            </a>
            <a href="../../public/menu.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i> Volver
            </a>
        </div>
    </div>
    
    <?php if($mensaje): ?>
        <div class="alert alert-<?= $tipo_alerta ?> shadow-sm">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-bold">Seleccione archivo CSV</label>
                    <input type="file" name="csv_arca" class="form-control" accept=".csv" required>
                    <div class="form-text">
                        Descargue el archivo desde AFIP "Mis Comprobantes Recibidos" (formato CSV).
                        El sistema verificará duplicados automáticamente.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-upload"></i> Subir e Importar
                </button>
            </form>
        </div>
    </div>
    
    <?php if ($tiene_lote_col): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-bold py-3">
            <i class="bi bi-clock-history me-2 text-primary"></i> Historial de Importaciones
        </div>
        <div class="card-body p-0">
            <?php if (empty($lotes)): ?>
                <p class="text-muted text-center py-4 mb-0">No hay importaciones registradas aún.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Fecha</th>
                            <th>Archivo</th>
                            <th>Usuario</th>
                            <th class="text-center">Importados</th>
                            <th class="text-center">Duplicados</th>
                            <th class="text-center">Vinculados</th>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lotes as $lote): ?>
                        <tr <?= ($lote_id_nuevo == $lote['id']) ? 'class="table-success"' : '' ?>>
                            <td class="ps-3 text-nowrap">
                                <?= date('d/m/Y H:i', strtotime($lote['fecha_importacion'])) ?>
                            </td>
                            <td>
                                <span class="small font-monospace text-muted">
                                    <i class="bi bi-file-earmark-text me-1"></i><?= htmlspecialchars($lote['nombre_archivo'] ?? '-') ?>
                                </span>
                            </td>
                            <td class="small"><?= htmlspecialchars($lote['usuario'] ?? '-') ?></td>
                            <td class="text-center">
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <?= $lote['total_importados'] ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($lote['total_duplicados'] > 0): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                        <?= $lote['total_duplicados'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($lote['vinculados'] > 0): ?>
                                    <span class="badge bg-secondary" title="Facturas ya usadas en liquidaciones">
                                        <?= $lote['vinculados'] ?> vinculadas
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">ninguna</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <?php if ($lote['vinculados'] == 0): ?>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmarEliminar(<?= $lote['id'] ?>, <?= $lote['total_importados'] ?>)"
                                            title="Eliminar este lote y sus comprobantes">
                                        <i class="bi bi-trash"></i> Eliminar lote
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small" title="No se puede eliminar: hay facturas vinculadas a liquidaciones">
                                        <i class="bi bi-lock"></i> Protegido
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer bg-white text-muted small">
            <i class="bi bi-info-circle me-1"></i> Solo se pueden eliminar lotes donde ninguna factura haya sido usada en una liquidación.
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info shadow-sm">
        <i class="bi bi-info-circle me-2"></i>
        Para ver el historial de importaciones ejecute la migración <strong>07_arca_lotes.sql</strong> en la base de datos.
    </div>
    <?php endif; ?>

</div>

<form id="formEliminarLote" method="POST" action="arca_lote_eliminar.php">
    <input type="hidden" name="lote_id" id="inputLoteId">
</form>

<script>
function confirmarEliminar(loteId, cantidad) {
    if (confirm('¿Está seguro de eliminar este lote?\n\nSe eliminarán ' + cantidad + ' comprobantes importados.\nEsta acción no se puede deshacer.')) {
        document.getElementById('inputLoteId').value = loteId;
        document.getElementById('formEliminarLote').submit();
    }
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

</body>
</html>
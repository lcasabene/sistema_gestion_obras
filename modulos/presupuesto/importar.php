<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$mensaje = "";

// --- 1. LÓGICA DE ACCIONES (POST) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // A) IMPORTAR ARCHIVO
    if (isset($_POST['importar'])) {
        $file = $_FILES['archivo_csv']['tmp_name'];
        $fecha_version = $_POST['fecha_version']; 
        $es_protegido = isset($_POST['es_protegido']) ? 1 : 0;
        
        if (($handle = fopen($file, "r")) !== FALSE) {
            fgetcsv($handle, 2000, ";"); // Saltar cabecera
            
            $pdo->beginTransaction();
            try {
                // SQL de inserción
                $sql = "INSERT INTO presupuesto_ejecucion (
                    ejer, fecha_listado, tipo_imp, juri, sa, unor, fina, func, subf, inci, 
                    ppal, ppar, spar, fufi, ubge, monto_def, monto_comp, monto_ejec, 
                    monto_disp, monto_sald, monto_reep, tiju, tisa, tide, tiuo, cpn1, 
                    cpn2, cpn3, atn1, atn2, atn3, denominacion1, denominacion2, 
                    denominacion3, imputacion, preventivos, desc_imputacion, 
                    fecha_carga, protegido
                ) VALUES (" . str_repeat('?,', 38) . "?)";
                
                $stmt = $pdo->prepare($sql);

                $limpiarNum = function($n) {
                    if ($n == '-' || trim($n) == '' || $n == ' -   ') return 0;
                    $n = str_replace('.', '', $n);
                    $n = str_replace(',', '.', $n);
                    return (float)$n;
                };

                while (($d = fgetcsv($handle, 2000, ";")) !== FALSE) {
                    if (count($d) < 37) continue; 
                    $params = [
                        $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9],
                        $d[10], $d[11], $d[12], $d[13], $d[14], 
                        $limpiarNum($d[15]), $limpiarNum($d[16]), $limpiarNum($d[17]), 
                        $limpiarNum($d[18]), $limpiarNum($d[19]), $limpiarNum($d[20]), 
                        $d[21], $d[22], $d[23], $d[24], $d[25],
                        $d[26], $d[27], $d[28], $d[29], $d[30],
                        $d[31], $d[32], $d[33], $d[34],
                        $limpiarNum($d[35]), $d[36],
                        $fecha_version,
                        $es_protegido
                    ];
                    $stmt->execute($params);
                }
                $pdo->commit();
                $extra = $es_protegido ? " (Protegida)" : "";
                $mensaje = "<div class='alert alert-success'>Importación del $fecha_version completada con éxito$extra.</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensaje = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
            }
            fclose($handle);
        }
    }

    // B) BORRAR UNA VERSIÓN
    if (isset($_POST['accion']) && $_POST['accion'] === 'borrar') {
        $fecha_target = $_POST['fecha_carga'];
        try {
            // Verificar protección antes de borrar
            $check = $pdo->prepare("SELECT MAX(protegido) FROM presupuesto_ejecucion WHERE fecha_carga = ?");
            $check->execute([$fecha_target]);
            if ($check->fetchColumn() == 1) {
                $mensaje = "<div class='alert alert-warning'>No se puede borrar. Esta versión está protegida.</div>";
            } else {
                $stmt = $pdo->prepare("DELETE FROM presupuesto_ejecucion WHERE fecha_carga = ?");
                $stmt->execute([$fecha_target]);
                $mensaje = "<div class='alert alert-success'>Registros del día $fecha_target eliminados.</div>";
            }
        } catch (Exception $e) {
            $mensaje = "<div class='alert alert-danger'>Error al eliminar: " . $e->getMessage() . "</div>";
        }
    }

    // C) CAMBIAR PROTECCIÓN (CANDADO)
    if (isset($_POST['accion']) && $_POST['accion'] === 'toggle_proteccion') {
        $fecha_target = $_POST['fecha_carga'];
        $estado_actual = (int)$_POST['estado_actual'];
        $nuevo_estado = ($estado_actual == 1) ? 0 : 1;

        try {
            $stmt = $pdo->prepare("UPDATE presupuesto_ejecucion SET protegido = ? WHERE fecha_carga = ?");
            $stmt->execute([$nuevo_estado, $fecha_target]);
            $mensaje = "<div class='alert alert-info'>Estado de protección actualizado.</div>";
        } catch (Exception $e) {
            $mensaje = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
    }
}

// --- 2. CONSULTA PARA EL LISTADO INFERIOR ---
$sql_historial = "SELECT 
            fecha_carga, 
            MAX(fecha_listado) as fecha_doc, 
            COUNT(*) as cantidad, 
            MAX(protegido) as protegido,
            SUM(monto_ejec) as total_ejecutado
        FROM presupuesto_ejecucion 
        GROUP BY fecha_carga 
        ORDER BY fecha_carga DESC";
$historial = $pdo->query($sql_historial)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../public/_header.php';
?>

<div class="container mt-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0 text-primary fw-bold">Gestión de Importaciones</h4>
        <a href="../../public/menu.php" class="btn btn-secondary btn-sm">
            <i class="bi bi-arrow-left-circle me-1"></i> Volver al Menú
        </a>
    </div>

    <?= $mensaje ?>

    <div class="card shadow mb-5">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-cloud-upload"></i> Nueva Carga</h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-5 mb-3">
                        <label class="form-label fw-bold">Fecha de Versión</label>
                        <input type="date" name="fecha_version" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-7 mb-3">
                        <label class="form-label fw-bold">Archivo CSV</label>
                        <input type="file" name="archivo_csv" class="form-control" accept=".csv" required>
                    </div>
                </div>

                <div class="alert alert-light border d-flex align-items-center mb-3 py-2">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="es_protegido" name="es_protegido" value="1">
                        <label class="form-check-label fw-bold text-primary" for="es_protegido">
                            <i class="bi bi-shield-lock"></i> Proteger versión (Oficial)
                        </label>
                    </div>
                    <div class="ms-3 text-muted small border-start ps-3">
                        Protege esta carga contra borrados accidentales.
                    </div>
                </div>

                <button type="submit" name="importar" class="btn btn-success w-100 fw-bold">
                    <i class="bi bi-cloud-arrow-up"></i> Importar Datos
                </button>
            </form>
        </div>
    </div>

    <h5 class="text-secondary border-bottom pb-2 mb-3">Historial de Versiones Cargadas</h5>
    
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Fecha Carga</th>
                            <th>Fecha Doc.</th>
                            <th>Registros</th>
                            <th>Total Ejecutado</th>
                            <th class="text-center">Estado</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historial)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No hay datos cargados aún.</td></tr>
                        <?php else: ?>
                            <?php foreach ($historial as $fila): ?>
                                <tr class="<?= $fila['protegido'] ? 'bg-success bg-opacity-10' : '' ?>">
                                    <td class="ps-4 fw-bold"><?= date('d/m/Y', strtotime($fila['fecha_carga'])) ?></td>
                                    <td><?= $fila['fecha_doc'] ?></td>
                                    <td><span class="badge bg-secondary"><?= number_format($fila['cantidad'], 0, ',', '.') ?></span></td>
                                    <td class="text-muted small">$ <?= number_format($fila['total_ejecutado'], 2, ',', '.') ?></td>
                                    
                                    <td class="text-center">
                                        <?php if ($fila['protegido']): ?>
                                            <span class="badge bg-success"><i class="bi bi-lock-fill"></i> Protegido</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">Borrable</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end gap-2">
                                            
                                            <form method="POST">
                                                <input type="hidden" name="accion" value="toggle_proteccion">
                                                <input type="hidden" name="fecha_carga" value="<?= $fila['fecha_carga'] ?>">
                                                <input type="hidden" name="estado_actual" value="<?= $fila['protegido'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $fila['protegido'] ? 'Desproteger' : 'Proteger' ?>">
                                                    <i class="bi <?= $fila['protegido'] ? 'bi-unlock' : 'bi-lock' ?>"></i>
                                                </button>
                                            </form>

                                            <?php if (!$fila['protegido']): ?>
                                                <form method="POST" onsubmit="return confirm('¿Eliminar todos los datos del <?= $fila['fecha_carga'] ?>? Esta acción no se puede deshacer.');">
                                                    <input type="hidden" name="accion" value="borrar">
                                                    <input type="hidden" name="fecha_carga" value="<?= $fila['fecha_carga'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar definitivamente">
                                                        <i class="bi bi-trash"></i> Eliminar
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-light text-muted border" disabled title="Desprotege para eliminar">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
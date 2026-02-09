<?php
// modulos/presupuesto/presupuesto.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// =================================================================================
// 1. LÓGICA DE FILTROS (Ejercicio -> Versión)
// =================================================================================

// A. Obtener Ejercicios disponibles
try {
    $stmtEjercicios = $pdo->query("SELECT DISTINCT ejer FROM presupuesto_ejecucion WHERE ejer IS NOT NULL ORDER BY ejer DESC");
    $ejercicios = $stmtEjercicios->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $ejercicios = [];
}

// Si no hay ejercicios, usamos el año actual por defecto
$ejercicio_sel = $_GET['ejercicio'] ?? ($ejercicios[0] ?? date('Y'));

// B. Obtener Versiones del Ejercicio seleccionado
$versiones = [];
if (!empty($ejercicio_sel)) {
    try {
        $stmtVersiones = $pdo->prepare("SELECT DISTINCT fecha_carga FROM presupuesto_ejecucion WHERE ejer = ? ORDER BY fecha_carga DESC");
        $stmtVersiones->execute([$ejercicio_sel]);
        $versiones = $stmtVersiones->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // Manejo silencioso o log
    }
}

// Seleccionar versión (la primera por defecto o la que venga por GET)
$version_actual = $_GET['version'] ?? ($versiones[0] ?? null);

// Validar que la versión pertenezca al ejercicio
if (!in_array($version_actual, $versiones) && count($versiones) > 0) {
    $version_actual = $versiones[0];
}

// =================================================================================
// 2. CONSULTAS DE DATOS
// =================================================================================

$registros = [];
if ($version_actual) {
    // Traemos los datos. Nota: Ajusta los nombres de columnas si difieren en tu tabla real.
    // Asumimos que existen las columnas calculadas o las calculamos en el HTML.
    $sql = "SELECT * FROM presupuesto_ejecucion 
            WHERE ejer = ? AND fecha_carga = ? 
            ORDER BY id ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ejercicio_sel, $version_actual]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =================================================================================
// 3. VISTA HTML
// =================================================================================
include __DIR__ . '/../../public/_header.php'; 
?>

<div class="container-fluid my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary"><i class="bi bi-table"></i> Ejecución Presupuestaria</h3>
            <p class="text-muted small mb-0">Visualizando datos históricos por versión de carga.</p>
        </div>
        <div class="d-flex gap-2">
            <form method="GET" class="d-flex gap-2 align-items-center">
                
                <select name="ejercicio" class="form-select form-select-sm fw-bold border-primary" onchange="this.form.submit()">
                    <?php if(empty($ejercicios)): ?>
                        <option value="<?= date('Y') ?>"><?= date('Y') ?></option>
                    <?php else: ?>
                        <?php foreach($ejercicios as $ej): ?>
                            <option value="<?= $ej ?>" <?= $ej == $ejercicio_sel ? 'selected' : '' ?>>
                                Ejercicio <?= $ej ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                
                <select name="version" class="form-select form-select-sm text-dark fw-bold" style="max-width: 250px;" onchange="this.form.submit()">
                    <?php if(empty($versiones)): ?>
                        <option value="">Sin datos</option>
                    <?php else: ?>
                        <?php foreach($versiones as $v): ?>
                            <option value="<?= $v ?>" <?= $v == $version_actual ? 'selected' : '' ?>>
                                Versión: <?= date('d/m/Y H:i', strtotime($v)) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </form>
            
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm d-flex align-items-center">
                <i class="bi bi-arrow-left me-1"></i> Volver
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <?php if(empty($registros)): ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                    No se encontraron registros para la versión seleccionada.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="tablaPresupuesto" class="table table-striped table-hover table-sm align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-primary text-nowrap">
                            <tr>
                                <th>Juri</th>
                                <th>Imputación (Prog-Sub-Part)</th>
                                <th class="text-end">Crédito Vigente</th>
                                <th class="text-end">Preventivo</th>
                                <th class="text-end">Compromiso</th>
                                <th class="text-end">Devengado</th>
                                <th class="text-end">Pagado</th>
                                <th class="text-end">Saldo Disp.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($registros as $r): 
                                // Cálculos seguros (casting a float para evitar errores)
                                $vigente = (float)($r['credito_vigente'] ?? 0);
                                $preventivo = (float)($r['preventivo'] ?? 0);
                                $compromiso = (float)($r['compromiso'] ?? 0);
                                $devengado = (float)($r['devengado'] ?? 0);
                                $pagado = (float)($r['pagado'] ?? 0);
                                $monto_disp = (float)($r['monto_disp'] ?? 0);
                                
                                // Armado de imputación visual
                                $imputacion = ($r['sa'] ?? '').'-'.($r['pro'] ?? '').'-'.($r['sp'] ?? '').'-'.($r['py'] ?? '').'-'.($r['ac'] ?? '').'-'.($r['ob'] ?? '');
                            ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($r['juri'] ?? '-') ?></td>
                                <td>
                                    <div class="font-monospace text-primary"><?= $imputacion ?></div>
                                    <small class="text-muted text-truncate d-block" style="max-width: 250px;">
                                        <?= htmlspecialchars($r['denominacion'] ?? '') ?>
                                    </small>
                                </td>
                                <td class="text-end fw-bold">$<?= number_format($vigente, 2, ',', '.') ?></td>
                                <td class="text-end">$<?= number_format($preventivo, 2, ',', '.') ?></td>
                                <td class="text-end">$<?= number_format($compromiso, 2, ',', '.') ?></td>
                                <td class="text-end">$<?= number_format($devengado, 2, ',', '.') ?></td>
                                <td class="text-end">$<?= number_format($pagado, 2, ',', '.') ?></td>
                                <td class="text-end fw-bold <?= $monto_disp < 0 ? 'text-danger' : 'text-success' ?>">
                                    $<?= number_format($monto_disp, 2, ',', '.') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<script>
    $(document).ready(function() {
        if ($('#tablaPresupuesto').length) {
            $('#tablaPresupuesto').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
                pageLength: 20,
                dom: "<'row'<'col-sm-12 col-md-6'f><'col-sm-12 col-md-6 text-end'B>>" +
                     "<'row'<'col-sm-12'tr>>" +
                     "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                buttons: [
                    {
                        extend: 'excelHtml5',
                        text: '<i class="bi bi-file-excel"></i> Exportar Excel',
                        className: 'btn btn-sm btn-success',
                        title: 'Ejecucion_Presupuestaria_<?= $ejercicio_sel ?>'
                    }
                ]
            });
        }
    });
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
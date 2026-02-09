<?php
// curva_listado.php
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}
require_once __DIR__ . '/../../config/database.php';

$mensaje = '';
$yearView = $_GET['year_fri'] ?? date('Y');

// --- 1. PROCESAR GUARDADO DE ÍNDICES (FRI) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_indices') {
    try {
        if (!isset($_POST['indices']) || !is_array($_POST['indices'])) {
            throw new Exception("No se recibieron datos válidos.");
        }

        $pdo->beginTransaction();
        $anio = (int)$_POST['anio'];
        
        $sql = "INSERT INTO indices_mensuales (anio, mes, porcentaje, descripcion) 
                VALUES (:anio, :mes, :pct, 'Manual')
                ON DUPLICATE KEY UPDATE porcentaje = :pct_upd";
        
        $stmt = $pdo->prepare($sql);

        foreach ($_POST['indices'] as $mes => $datoInput) {
            if ($datoInput === '' || $datoInput === null) $datoInput = 0;
            $valorFinal = str_replace(',', '.', $datoInput);

            $stmt->execute([
                ':anio'     => $anio,
                ':mes'      => $mes,
                ':pct'      => $valorFinal,
                ':pct_upd'  => $valorFinal
            ]);
        }
        
        $pdo->commit();
        $mensaje = "Índices del año $anio guardados correctamente.";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "Error: " . $e->getMessage();
    }
}

// --- 2. CONSULTA PRINCIPAL DE OBRAS (CORREGIDA PARA NUEVA ESTRUCTURA) ---
try {
    // Se cambia 'nro_version' por 'id' y se usa 'fecha_creacion'
    $sqlObras = "
    SELECT 
        o.id AS obra_id, 
        o.denominacion AS obra_denominacion, 
        o.monto_actualizado,
        cv.id AS version_id, 
        cv.fecha_creacion
    FROM obras o
    LEFT JOIN curva_version cv ON cv.obra_id = o.id AND cv.es_vigente = 1
    WHERE o.activo = 1 
    ORDER BY o.denominacion ASC";
    
    $rows = $pdo->query($sqlObras)->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { $rows = []; $error = $e->getMessage(); }


// --- 3. CONSULTA DE ÍNDICES PARA EL MODAL ---
$stmtInd = $pdo->prepare("SELECT mes, porcentaje FROM indices_mensuales WHERE anio = ?");
$stmtInd->execute([$yearView]);
$indicesDb = $stmtInd->fetchAll(PDO::FETCH_KEY_PAIR);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listado de Obras y FRI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        .fri-bar-container { height: 6px; background: #e9ecef; border-radius: 3px; margin-top: 5px; overflow: hidden; }
        .fri-bar { height: 100%; border-radius: 3px; transition: width 0.3s; }
        .table-fri td { vertical-align: middle; }
    </style>
</head>
<body class="bg-light">

<div class="container my-4">
    
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0 text-primary"><i class="bi bi-buildings"></i> Gestión de Curvas</h3>
        
        <div class="d-flex gap-2">
            <button class="btn btn-warning fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#modalFRI">
                <i class="bi bi-graph-up-arrow"></i> Gestionar Índices / FRI Global
            </button>
            <a href="../../public/menu.php" class="btn btn-secondary">Volver al Menú</a>
        </div>
    </div>

    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= $mensaje ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaObras" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Obra</th>
                            <th>Monto Actualizado</th>
                            <th>Estado Curva</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php 
                                // Verificamos si existe ID de versión
                                $tieneCurva = !empty($r['version_id']); 
                                $badgeCls = $tieneCurva ? 'bg-success' : 'bg-secondary';
                                
                                // Formateamos fecha si existe
                                $fechaVer = $tieneCurva ? date('d/m/Y', strtotime($r['fecha_creacion'])) : '';
                                $badgeTxt = $tieneCurva ? "V.{$r['version_id']} ({$fechaVer})" : "Sin Curva";
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($r['obra_denominacion']) ?></div>
                                </td>
                                
                                <td data-order="<?= $r['monto_actualizado'] ?>"> 
                                    $ <?= number_format($r['monto_actualizado'], 2, ',', '.') ?>
                                </td>
                                
                                <td><span class="badge <?= $badgeCls ?>"><?= $badgeTxt ?></span></td>
                                
                                <td class="text-end">
                                        <?php if ($tieneCurva): ?>
                                            <a href="curva_ver.php?version_id=<?= $r['version_id'] ?>" 
                                            class="btn btn-sm btn-info text-white me-1" 
                                            title="Ver Detalle">
                                                <i class="bi bi-eye-fill"></i> Ver
                                            </a>
                                        <?php endif; ?>

                                        <a href="curva_form_generate.php?obra_id=<?= $r['obra_id'] ?>" 
                                        class="btn btn-sm btn-outline-primary"
                                        title="<?= $tieneCurva ? 'Crear Nueva Versión' : 'Crear Primera Curva' ?>">
                                        <?= $tieneCurva ? '<i class="bi bi-plus-lg"></i> Nueva' : 'Generar' ?>
                                        </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFRI" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"> <div class="modal-content">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title fw-bold"><i class="bi bi-cash-coin"></i> Índices de Inflación (FRI)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="save_indices">
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="d-flex align-items-center bg-light p-2 rounded border">
                            <label class="me-2 fw-bold text-muted small">AÑO A EDITAR:</label>
                            <select name="anio" class="form-select form-select-sm w-auto fw-bold text-primary border-0 bg-light" 
                                    onchange="cambiarAnioFRI(this)">
                                <?php 
                                $anioActual = date('Y');
                                for($y = $anioActual - 2; $y <= $anioActual + 10; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $yearView ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <span class="badge bg-info text-dark">Modo Edición</span>
                    </div>

                    <div class="card bg-light border-warning mb-3">
                        <div class="card-body py-2">
                            <div class="row align-items-end gx-2">
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted">Desde Mes</label>
                                    <select id="rangoDesde" class="form-select form-select-sm">
                                        <?php for($m=1; $m<=12; $m++) echo "<option value='$m'>".date('F', mktime(0,0,0,$m,1))."</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted">Hasta Mes</label>
                                    <select id="rangoHasta" class="form-select form-select-sm">
                                        <?php for($m=1; $m<=12; $m++) echo "<option value='$m' ".($m==12?'selected':'').">".date('F', mktime(0,0,0,$m,1))."</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted">Aplicar %</label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" id="rangoValor" class="form-control" step="0.1" placeholder="Ej: 5">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-warning btn-sm w-100 fw-bold" onclick="aplicarRangoModal()">
                                        <i class="bi bi-lightning-fill"></i> Aplicar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive border rounded" style="max-height: 350px; overflow-y: auto;">
                        <table class="table table-sm table-striped mb-0 align-middle">
                            <thead class="table-light sticky-top" style="z-index: 1;">
                                <tr>
                                    <th style="width: 30%;" class="ps-3">Mes</th>
                                    <th style="width: 30%;">Valor</th>
                                    <th style="width: 40%;">Impacto Visual</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $mesesNombres = [1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 
                                                 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'];
                                for ($m = 1; $m <= 12; $m++): 
                                    $val = $indicesDb[$m] ?? 0; 
                                    $color = 'bg-success'; if($val > 2) $color = 'bg-warning'; if($val > 5) $color = 'bg-danger';
                                    $width = min(($val * 10), 100); 
                                ?>
                                <tr>
                                    <td class="ps-3 fw-semibold text-secondary"><?= $mesesNombres[$m] ?></td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.01" class="form-control text-end fw-bold fri-input" 
                                                   id="input-mes-<?= $m ?>"
                                                   name="indices[<?= $m ?>]" 
                                                   value="<?= $val ?>"
                                                   data-mes="<?= $m ?>"
                                                   oninput="actualizarBarra(this)">
                                            <span class="input-group-text bg-white text-muted">%</span>
                                        </div>
                                    </td>
                                    <td class="pe-3">
                                        <div class="fri-bar-container w-100 border">
                                            <div id="bar-<?= $m ?>" class="fri-bar <?= $color ?>" style="width: <?= $width ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
    // --- INICIALIZAR DATATABLES ---
    $(document).ready(function() {
        $('#tablaObras').DataTable({
            // Traducción al español
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
            },
            // Configuración
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Todos"]],
            pageLength: 10,
            columnDefs: [
                { orderable: false, targets: 3 }, // Deshabilita orden en columna "Acciones" (índice 3)
                { targets: 1, type: 'num-fmt' }   // Ayuda a ordenar la columna de Moneda
            ]
        });
    });

    // --- SCRIPTS MODAL FRI ---
    function cambiarAnioFRI(select) {
        const anio = select.value;
        const url = new URL(window.location.href);
        url.searchParams.set('year_fri', anio);
        window.location.href = url.toString();
    }

    function actualizarBarra(input) {
        const val = parseFloat(input.value) || 0;
        const mes = input.getAttribute('data-mes');
        const bar = document.getElementById('bar-' + mes);
        let width = Math.min(val * 10, 100);
        bar.style.width = width + '%';
        bar.className = 'fri-bar';
        if(val <= 2) bar.classList.add('bg-success');
        else if(val <= 5) bar.classList.add('bg-warning');
        else bar.classList.add('bg-danger');
    }

    function aplicarRangoModal() {
        const desde = parseInt(document.getElementById('rangoDesde').value);
        const hasta = parseInt(document.getElementById('rangoHasta').value);
        const valor = parseFloat(document.getElementById('rangoValor').value);

        if (isNaN(valor)) { alert("Por favor ingrese un porcentaje válido."); return; }
        if (desde > hasta) { alert("El mes 'Desde' no puede ser mayor al mes 'Hasta'."); return; }

        for (let m = desde; m <= hasta; m++) {
            const input = document.getElementById('input-mes-' + m);
            if (input) {
                input.value = valor;
                actualizarBarra(input);
            }
        }
    }
    
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('year_fri') && !document.querySelector('.alert-success')) {
            const myModal = new bootstrap.Modal(document.getElementById('modalFRI'));
            myModal.show();
        }
    });
</script>

</body>
</html>
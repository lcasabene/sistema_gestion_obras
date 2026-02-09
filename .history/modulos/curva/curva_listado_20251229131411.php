<?php
// curva_listado.php
session_start();
// if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

require_once __DIR__ . '/../../config/database.php';

$mensaje = '';
$yearView = $_GET['year_fri'] ?? date('Y'); // Año que estamos visualizando en el modal

// --- 1. PROCESAR GUARDADO DE ÍNDICES (FRI) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_indices') {
    try {
        $pdo->beginTransaction();
        
        $anio = (int)$_POST['anio'];
        // Preparamos el UPSERT (Insertar o Actualizar si existe)
        $sql = "INSERT INTO indices_mensuales (anio, mes, porcentaje, descripcion) 
                VALUES (:anio, :mes, :pct, 'Manual')
                ON DUPLICATE KEY UPDATE porcentaje = :pct";
        
        $stmt = $pdo->prepare($sql);
        // Reemplazamos coma por punto antes de guardar
        $valorLimpio = str_replace(',', '.', $valor);

        foreach ($_POST['indices'] as $mes => $valor) {
            $stmt->execute([
                ':anio' => $anio,
                ':mes'  => $mes,
                ':pct'  => $valorLimpio
            ]);
        }
        
        $pdo->commit();
        $mensaje = "Índices del año $anio actualizados correctamente.";
        // Mantenemos el modal abierto visualmente tras recargar (opcional, por ahora recarga simple)
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "Error al guardar índices: " . $e->getMessage();
    }
}

// --- 2. CONSULTA PRINCIPAL DE OBRAS (Tu listado original) ---
// (Simplificado para el ejemplo, asegúrate de mantener tu lógica de JOINs si la tenías)
try {
    $sqlObras = "
    SELECT 
        o.id AS obra_id, o.denominacion AS obra_denominacion, o.monto_actualizado,
        cv.nro_version, cv.fecha_desde, cv.fecha_hasta
    FROM obras o
    LEFT JOIN curva_version cv ON cv.obra_id = o.id AND cv.es_vigente = 1
    WHERE o.activo = 1 ORDER BY o.denominacion ASC";
    
    $rows = $pdo->query($sqlObras)->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { $rows = []; $error = $e->getMessage(); }


// --- 3. CONSULTA DE ÍNDICES PARA EL MODAL ---
// Traemos los índices del año seleccionado para pre-llenar el formulario
$stmtInd = $pdo->prepare("SELECT mes, porcentaje FROM indices_mensuales WHERE anio = ?");
$stmtInd->execute([$yearView]);
$indicesDb = $stmtInd->fetchAll(PDO::FETCH_KEY_PAIR); // Retorna array [mes => porcentaje]

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listado de Obras y FRI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Estilos para la barra visual del FRI */
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
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Obra</th>
                            <th>Monto Actualizado</th>
                            <th>Estado Curva</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No hay obras activas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php 
                                $tieneCurva = !empty($r['nro_version']); 
                                $badgeCls = $tieneCurva ? 'bg-success' : 'bg-secondary';
                                $badgeTxt = $tieneCurva ? "V{$r['nro_version']} Vigente" : "Sin Curva";
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($r['obra_denominacion']) ?></div>
                                </td>
                                <td>$ <?= number_format($r['monto_actualizado'], 2, ',', '.') ?></td>
                                <td><span class="badge <?= $badgeCls ?>"><?= $badgeTxt ?></span></td>
                                <td class="text-end">
                                    <a href="curva_form_generate.php?obra_id=<?= $r['obra_id'] ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                       <?= $tieneCurva ? 'Nueva Versión' : 'Generar Curva' ?>
                                    </a>
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

<div class="modal fade" id="modalFRI" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"> <div class="modal-content">
            
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title fw-bold"><i class="bi bi-cash-coin"></i> Índices de Inflación (FRI)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_indices">
                
                <div class="modal-body">
                    <div class="d-flex justify-content-center align-items-center mb-3 bg-light p-2 rounded">
                        <label class="me-2 fw-bold">Visualizar Año:</label>
                        <select name="anio" class="form-select w-auto fw-bold text-primary" id="selectYearFRI" onchange="cambiarAnioFRI(this)">
                            <?php 
                            $anioActual = date('Y');
                            for($y = $anioActual - 1; $y <= $anioActual + 3; $y++): ?>
                                <option value="<?= $y ?>" <?= $y == $yearView ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <p class="small text-muted text-center mb-3">
                        Estos valores se aplicarán automáticamente a todas las curvas nuevas o recalculadas.
                    </p>

                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-fri mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 40%;">Mes</th>
                                    <th style="width: 30%;">% Inflación</th>
                                    <th style="width: 30%;">Visualización</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $mesesNombres = [1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 
                                                 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'];
                                
                                for ($m = 1; $m <= 12; $m++): 
                                    $val = $indicesDb[$m] ?? 0; // Valor de la BD o 0
                                    
                                    // Cálculo simple para el color de la barra
                                    // 0-2% Verde, 2-5% Amarillo, >5% Rojo
                                    $color = 'bg-success';
                                    if($val > 2) $color = 'bg-warning';
                                    if($val > 5) $color = 'bg-danger';
                                    
                                    // Ancho de la barra (max 100% para valores >= 10%)
                                    $width = min(($val * 10), 100); 
                                ?>
                                <tr>
                                    <td class="fw-semibold text-secondary"><?= $mesesNombres[$m] ?></td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.01" class="form-control text-end fw-bold fri-input" 
                                                   name="indices[<?= $m ?>]" 
                                                   value="<?= $val ?>"
                                                   data-mes="<?= $m ?>"
                                                   oninput="actualizarBarra(this)">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center" style="height: 31px;">
                                            <div class="fri-bar-container w-100">
                                                <div id="bar-<?= $m ?>" class="fri-bar <?= $color ?>" style="width: <?= $width ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script para recargar la página cuando cambias el año en el Modal
    function cambiarAnioFRI(select) {
        const anio = select.value;
        const url = new URL(window.location.href);
        url.searchParams.set('year_fri', anio);
        window.location.href = url.toString();
    }

    // Script para visualización dinámica (las barras se mueven al escribir)
    function actualizarBarra(input) {
        const val = parseFloat(input.value) || 0;
        const mes = input.getAttribute('data-mes');
        const bar = document.getElementById('bar-' + mes);
        
        // Calcular ancho (escala: 10% de inflación = 100% de barra)
        let width = Math.min(val * 10, 100);
        bar.style.width = width + '%';

        // Cambiar color dinámicamente
        bar.className = 'fri-bar'; // Reset
        if(val <= 2) bar.classList.add('bg-success');
        else if(val <= 5) bar.classList.add('bg-warning');
        else bar.classList.add('bg-danger');
    }
    
    // Si hay un parámetro year_fri en la URL, abrimos el modal automáticamente al cargar
    // para que el usuario no sienta que "se cerró" al cambiar el año.
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('year_fri') && !document.querySelector('.alert-success')) {
            // Solo abrir si NO venimos de un guardado exitoso (para no tapar el mensaje de éxito)
            // O si prefieres que se abra siempre:
            const myModal = new bootstrap.Modal(document.getElementById('modalFRI'));
            myModal.show();
        }
    });
</script>

</body>
</html>
<?php
// modulos/curva/curva_ver.php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("Versión no especificada.");

// 1. Obtener Datos de la Obra y Versión
$sqlHead = "SELECT v.*, o.id as obra_real_id, o.denominacion, o.monto_actualizado, o.monto_original, o.anticipo_pct 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id WHERE v.id = ?";
$cabecera = $pdo->prepare($sqlHead);
$cabecera->execute([$versionId]);
$cabecera = $cabecera->fetch(PDO::FETCH_ASSOC);

// 2. Obtener Ítems de la Curva (Planificación)
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$itemsCurva = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. Obtener Certificados Existentes (Realidad) para cruzar datos
// Indexamos por [periodo][tipo]
$sqlCerts = "SELECT id, periodo, tipo, monto_neto_pagar, avance_fisico_mensual, estado, nro_certificado 
             FROM certificados WHERE obra_id = ? AND estado != 'ANULADO'";
$stmtC = $pdo->prepare($sqlCerts);
$stmtC->execute([$cabecera['obra_real_id']]);
$certificadosDB = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$mapaCertificados = [];
foreach ($certificadosDB as $c) {
    $periodo = substr($c['periodo'], 0, 7); // YYYY-MM
    $mapaCertificados[$periodo][$c['tipo']][] = $c;
}

// Helpers
function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtPct($v) { return number_format((float)$v, 2, ',', '.') . '%'; }

// Preparar Gráfico
$labels = []; $dataPlan = []; $dataReal = []; 
$acumPlan = 0; $acumReal = 0;

foreach ($itemsCurva as $i) {
    if (stripos($i['concepto'], 'anticipo') === false) {
        $per = date('Y-m', strtotime($i['periodo']));
        $labels[] = date('m/y', strtotime($i['periodo']));
        
        $acumPlan += $i['porcentaje_fisico'];
        $dataPlan[] = $acumPlan;
        
        // Buscar si hay certificado básico aprobado/pagado para sumar al real
        if(isset($mapaCertificados[$per]['ORDINARIO'])) {
            foreach($mapaCertificados[$per]['ORDINARIO'] as $cert) {
                // Solo sumamos al gráfico si tiene avance físico cargado
                if($cert['avance_fisico_mensual'] > 0) {
                    $acumReal += $cert['avance_fisico_mensual'];
                }
            }
        }
        $dataReal[] = ($acumReal > 0) ? $acumReal : null;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tablero Obra - <?= htmlspecialchars($cabecera['denominacion']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .col-plan { background-color: #f8f9fa; }
        .col-real { background-color: #f0fff4; border-left: 2px solid #198754; }
        .col-redet { background-color: #fff8e1; border-left: 1px dashed #ffc107; }
        .btn-action { font-size: 0.8rem; font-weight: 500; }
        .table-vcenter td { vertical-align: middle; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="text-primary fw-bold mb-0"><i class="bi bi-kanban"></i> Tablero de Certificación</h4>
            <div class="text-muted"><?= htmlspecialchars($cabecera['denominacion']) ?></div>
        </div>
        <div>
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGraph">
                <i class="bi bi-graph-up"></i> Ver/Ocultar Gráfico
            </button>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="collapse show mb-4" id="collapseGraph">
        <div class="card shadow-sm border-0">
            <div class="card-body" style="height: 250px;">
                <canvas id="chartCurva"></canvas>
            </div>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 table-vcenter text-center">
                    <thead class="table-dark text-uppercase small">
                        <tr>
                            <th rowspan="2" style="width: 100px;">Periodo</th>
                            <th rowspan="2">Concepto Planificado</th>
                            <th colspan="2" class="bg-secondary">Planificación (Curva)</th>
                            <th colspan="2" class="bg-success">Certificación Básica</th>
                            <th colspan="2" class="bg-warning text-dark">Redeterminaciones</th>
                        </tr>
                        <tr>
                            <th class="bg-secondary text-white">% Fis.</th>
                            <th class="bg-secondary text-white">Monto ($)</th>
                            
                            <th class="bg-success text-white">Avance Real</th>
                            <th class="bg-success text-white">Gestión</th>

                            <th class="bg-warning text-dark">Monto</th>
                            <th class="bg-warning text-dark">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($itemsCurva as $item): 
                            $per = date('Y-m', strtotime($item['periodo']));
                            $esAnticipo = (stripos($item['concepto'], 'anticipo') !== false);
                            
                            // Buscar certificados cargados para este mes
                            $certsBasicos = $mapaCertificados[$per]['ORDINARIO'] ?? [];
                            $certsRedet   = $mapaCertificados[$per]['REDETERMINACION'] ?? [];
                            $certsAnticipo= $mapaCertificados[$per]['ANTICIPO'] ?? [];

                            // Datos para Modales
                            $planPct = $item['porcentaje_fisico'];
                            $planMonto = $item['neto']; // Usamos neto o monto_base según prefieras
                        ?>
                        <tr>
                            <td class="fw-bold text-muted"><?= date('m/Y', strtotime($per)) ?></td>
                            
                            <td class="text-start small"><?= htmlspecialchars($item['concepto']) ?></td>

                            <td class="col-plan fw-bold text-primary"><?= fmtPct($planPct) ?></td>
                            <td class="col-plan small text-muted">$ <?= fmtM($planMonto) ?></td>

                            <td class="col-real">
                                <?php if($esAnticipo): ?>
                                    <?php if(!empty($certsAnticipo)): ?>
                                        <span class="badge bg-success">Anticipo OK</span>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success btn-action" 
                                            onclick="abrirModal('ANTICIPO', '<?= $per ?>', 0, <?= $cabecera['monto_actualizado'] ?>)">
                                            <i class="bi bi-plus-circle"></i> Cargar Ant.
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if(!empty($certsBasicos)): ?>
                                        <?php foreach($certsBasicos as $cb): ?>
                                            <div class="mb-1">
                                                <span class="badge bg-success bg-opacity-75 text-white">
                                                    <?= fmtPct($cb['avance_fisico_mensual']) ?>
                                                </span>
                                                <a href="../certificados/certificados_form.php?id=<?= $cb['id'] ?>" class="text-decoration-none ms-1" title="Editar">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-real">
                                <?php if(!$esAnticipo): ?>
                                    <button class="btn btn-sm btn-success btn-action shadow-sm" 
                                        onclick="abrirModal('ORDINARIO', '<?= $per ?>', <?= $planPct ?>, <?= $planMonto ?>)">
                                        <i class="bi bi-file-earmark-plus"></i> Certificar
                                    </button>
                                <?php endif; ?>
                            </td>

                            <td class="col-redet">
                                <?php if(!empty($certsRedet)): ?>
                                    <?php foreach($certsRedet as $cr): ?>
                                        <div class="small fw-bold text-dark">$ <?= fmtM($cr['monto_neto_pagar']) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-redet">
                                <button class="btn btn-sm btn-outline-dark btn-action" 
                                    onclick="abrirModal('REDETERMINACION', '<?= $per ?>', 0, 0)">
                                    <i class="bi bi-plus-lg"></i> Redet.
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCertificar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitulo">Nuevo Certificado</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form action="../certificados/certificados_guardar_modal.php" method="POST" id="formModal">
                <div class="modal-body">
                    <input type="hidden" name="obra_id" value="<?= $cabecera['obra_real_id'] ?>">
                    <input type="hidden" name="periodo" id="modalPeriodo">
                    <input type="hidden" name="tipo" id="modalTipo">
                    <input type="hidden" name="fondo_reparo_pct" value="5">
                    <input type="hidden" name="anticipo_pct_obra" value="<?= $cabecera['anticipo_pct'] ?>">
                    
                    <div class="alert alert-info py-2 small mb-3">
                        <div class="d-flex justify-content-between">
                            <span><strong>Periodo:</strong> <span id="lblPeriodo"></span></span>
                            <span id="boxPlanificado" class="d-none"><strong>Planificado:</strong> <span id="lblPlan"></span>%</span>
                        </div>
                    </div>

                    <div id="camposBasico" class="d-none">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Avance Físico del Mes (%)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="avance_fisico" id="inputAvance" class="form-control fw-bold fs-5 text-center" placeholder="0.00" oninput="calcMontoBasico()">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">Monto Contrato: $ <?= fmtM($cabecera['monto_actualizado']) ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Monto Básico Resultante ($)</label>
                            <input type="text" name="monto_basico" id="inputMontoBasico" class="form-control bg-light fw-bold" readonly>
                        </div>
                    </div>

                    <div id="camposRedet" class="d-none">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Monto Redeterminado ($)</label>
                            <input type="number" step="0.01" name="monto_redet" class="form-control fw-bold fs-5" placeholder="0.00">
                            <div class="form-text">Ingrese el monto total de la redeterminación para este periodo.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Concepto / Nro Acta</label>
                            <input type="text" name="concepto_redet" class="form-control" placeholder="Ej: Acta Acuerdo Nº 3">
                        </div>
                    </div>

                    <div id="camposAnticipo" class="d-none">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Porcentaje Anticipo</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="pct_anticipo" class="form-control" value="<?= $cabecera['anticipo_pct'] ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Monto ($)</label>
                            <input type="number" step="0.01" name="monto_anticipo" class="form-control" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="auto_numero" value="1" checked>
                        <label class="form-check-label small text-muted">Generar Nº Certificado automáticamente</label>
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">Guardar Certificado</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Datos globales
const montoObra = <?= $cabecera['monto_actualizado'] ?>;
const labels = <?= json_encode($labels) ?>;
const dataPlan = <?= json_encode($dataPlan) ?>;
const dataReal = <?= json_encode($dataReal) ?>;

// Configurar Modal
function abrirModal(tipo, periodo, planPct, planMonto) {
    document.getElementById('modalTipo').value = tipo;
    document.getElementById('modalPeriodo').value = periodo;
    document.getElementById('lblPeriodo').innerText = periodo;
    document.getElementById('formModal').reset();

    // Reset visibilidad
    document.getElementById('camposBasico').classList.add('d-none');
    document.getElementById('camposRedet').classList.add('d-none');
    document.getElementById('camposAnticipo').classList.add('d-none');
    document.getElementById('boxPlanificado').classList.add('d-none');

    // Configurar según tipo
    const titulo = document.getElementById('modalTitulo');
    
    if(tipo === 'ORDINARIO') {
        titulo.innerText = "Cargar Certificado Básico";
        document.getElementById('camposBasico').classList.remove('d-none');
        document.getElementById('boxPlanificado').classList.remove('d-none');
        document.getElementById('lblPlan').innerText = planPct;
        // Sugerir el % planificado en el input
        document.getElementById('inputAvance').value = planPct; 
        calcMontoBasico(); // Calcular monto inicial
    } 
    else if (tipo === 'REDETERMINACION') {
        titulo.innerText = "Nueva Redeterminación";
        document.getElementById('camposRedet').classList.remove('d-none');
    }
    else if (tipo === 'ANTICIPO') {
        titulo.innerText = "Certificar Anticipo";
        document.getElementById('camposAnticipo').classList.remove('d-none');
    }

    new bootstrap.Modal(document.getElementById('modalCertificar')).show();
}

function calcMontoBasico() {
    let pct = parseFloat(document.getElementById('inputAvance').value) || 0;
    let monto = montoObra * (pct / 100);
    document.getElementById('inputMontoBasico').value = monto.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Inicializar Gráfico
const ctx = document.getElementById('chartCurva').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            { label: 'Planificado', data: dataPlan, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, tension: 0.3 },
            { label: 'Ejecutado', data: dataReal, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', borderWidth: 3, tension: 0.1 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
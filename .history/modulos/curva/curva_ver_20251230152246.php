<?php
// curva_ver.php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("Versión no especificada.");

// --- LOGICA DE GUARDADO DE AVANCE REAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_real'])) {
    try {
        $itemId = $_POST['item_id'];
        $valReal = str_replace(',', '.', $_POST['valor_real']); // Asegurar formato decimal
        
        // Si el valor está vacío, lo guardamos como NULL (para que no grafique ceros en el futuro)
        $valToDb = ($valReal === '') ? NULL : (float)$valReal;

        $stmtUpd = $pdo->prepare("UPDATE curva_items SET porcentaje_real = ? WHERE id = ? AND version_id = ?");
        $stmtUpd->execute([$valToDb, $itemId, $versionId]);
        
        // Redirigir para evitar reenvío de formulario
        header("Location: curva_ver.php?version_id=$versionId");
        exit;
    } catch (Exception $e) {
        $error = "Error al guardar: " . $e->getMessage();
    }
}

// 1. Obtener Cabecera
$sqlHead = "SELECT v.*, o.denominacion, o.monto_actualizado 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cabecera) die("Versión no encontrada.");

// 2. Obtener Ítems
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// --- PREPARAR DATOS PARA EL GRÁFICO (ACUMULADOS) ---
$labels = [];
$dataPlan = [];
$dataReal = [];
$acumPlan = 0;
$acumReal = 0;

foreach ($items as $i) {
    // Solo graficamos certificados (ignoramos anticipo en la curva S de avance físico puro)
    if (stripos($i['concepto'], 'anticipo') === false) {
        $periodoFmt = date('m/y', strtotime($i['periodo']));
        $labels[] = $periodoFmt;
        
        // Planificado
        $acumPlan += $i['porcentaje_fisico'];
        $dataPlan[] = $acumPlan;
        
        // Real (Solo sumamos si existe dato)
        if (!is_null($i['porcentaje_real'])) {
            $acumReal += $i['porcentaje_real'];
            $dataReal[] = $acumReal;
        }
    }
}

// Helpers
function fmtMoneda($val) { return number_format((float)$val, 2, ',', '.'); }
function fmtPct($val) { return ($val === null) ? '-' : number_format((float)$val, 2, ',', '.'); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control Avance - <?= htmlspecialchars($cabecera['denominacion']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .table-view th { background-color: #f8f9fa; font-size: 0.8rem; text-transform: uppercase; text-align: center; vertical-align: middle; }
        .table-view td { font-size: 0.85rem; vertical-align: middle; }
        .num { font-family: 'Consolas', monospace; font-weight: 600; text-align: right; }
        .bg-anticipo { background-color: #fff3cd !important; }
        .text-anticipo { color: #856404; font-weight: bold; }
        .col-real { background-color: #f0fdf4; } /* Verde muy suave para columna Real */
        .cursor-pointer { cursor: pointer; }
    </style>
</head>
<body class="bg-light">

<div class="container my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="text-primary mb-0 fw-bold">
                <i class="bi bi-speedometer2"></i> Control de Certificación
            </h5>
            <div class="text-muted small"><?= htmlspecialchars($cabecera['denominacion']) ?> (V.<?= $cabecera['id'] ?>)</div>
        </div>
        <div>
            <a href="curva_export.php?version_id=<?= $cabecera['id'] ?>" class="btn btn-success btn-sm shadow-sm">
                <i class="bi bi-file-excel"></i> Excel
            </a>
            <a href="curva_listado.php" class="btn btn-outline-secondary btn-sm shadow-sm">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-white fw-bold text-secondary">
            <i class="bi bi-graph-up"></i> Curva S: Planificado vs Real (Acumulado)
        </div>
        <div class="card-body">
            <div style="height: 300px; width: 100%;">
                <canvas id="chartCurvaS"></canvas>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 table-view align-middle">
                    <thead>
                        <tr>
                            <th rowspan="2">Periodo</th>
                            <th rowspan="2">Concepto</th>
                            <th colspan="3" class="table-primary">Avance Físico (%)</th>
                            <th colspan="2">Financiero ($)</th>
                        </tr>
                        <tr>
                            <th class="table-primary text-primary">Planif.</th>
                            <th class="bg-success text-white" style="width: 100px;">REAL</th>
                            <th class="table-primary">Desvío</th>
                            
                            <th>Monto Base Orig.</th>
                            <th>Monto a Cobrar (Neto Plan)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $acumP = 0; $acumR = 0;
                        foreach($items as $i): 
                            $esAnticipo = (stripos($i['concepto'], 'anticipo') !== false);
                            $idItem = $i['id'];
                            
                            // Valores Planificados
                            $pctPlan = $i['porcentaje_fisico'];
                            
                            // Valores Reales
                            $pctReal = $i['porcentaje_real']; // Puede ser NULL
                            
                            // Cálculos
                            $desvio = (!is_null($pctReal) && !$esAnticipo) ? ($pctReal - $pctPlan) : 0;
                            
                            // Estilos Condicionales
                            $colorDesvio = ($desvio < 0) ? 'text-danger' : (($desvio > 0) ? 'text-success' : 'text-muted');
                            $claseFila = $esAnticipo ? 'bg-anticipo' : '';
                        ?>
                        <tr class="<?= $claseFila ?>">
                            <td class="text-center fw-bold text-muted"><?= date('m/Y', strtotime($i['periodo'])) ?></td>
                            <td class="<?= $esAnticipo ? 'text-anticipo' : '' ?>"><?= htmlspecialchars($i['concepto']) ?></td>
                            
                            <td class="text-center fw-bold text-primary bg-light"><?= fmtPct($pctPlan) ?>%</td>
                            
                            <td class="text-center col-real position-relative">
                                <?php if(!$esAnticipo): ?>
                                    <span class="fw-bold <?= is_null($pctReal) ? 'text-muted opacity-50' : 'text-dark' ?>">
                                        <?= fmtPct($pctReal) ?>%
                                    </span>
                                    <button class="btn btn-link btn-sm p-0 ms-2 text-decoration-none" 
                                            onclick="abrirModalCarga(<?= $idItem ?>, '<?= $i['concepto'] ?>', '<?= $pctReal ?>')">
                                        <i class="bi bi-pencil-square text-success"></i>
                                    </button>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center fw-bold <?= $colorDesvio ?> bg-light">
                                <?php if(!$esAnticipo && !is_null($pctReal)): ?>
                                    <?= ($desvio > 0 ? '+' : '') . fmtPct($desvio) ?>%
                                <?php endif; ?>
                            </td>

                            <td class="num text-muted">$ <?= fmtMoneda($i['monto_base']) ?></td>
                            <td class="num text-primary fw-bold">$ <?= fmtMoneda($i['neto']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCarga" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="update_real" value="1">
            <input type="hidden" name="item_id" id="modalItemId">
            
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title fw-bold">Cargar Avance Real</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small text-muted" id="modalConceptoLabel">Certificado</label>
                <div class="input-group">
                    <input type="number" step="0.01" name="valor_real" id="modalValorReal" class="form-control fw-bold text-center" placeholder="0.00" autofocus>
                    <span class="input-group-text">%</span>
                </div>
                <div class="form-text small">Dejar vacío si aún no se certificó.</div>
            </div>
            <div class="modal-footer p-1">
                <button type="submit" class="btn btn-success w-100 btn-sm fw-bold">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // --- 1. CONFIGURACIÓN DEL GRÁFICO (CHART.JS) ---
    const ctx = document.getElementById('chartCurvaS').getContext('2d');
    const labels = <?= json_encode($labels) ?>;
    const dataPlan = <?= json_encode($dataPlan) ?>;
    const dataReal = <?= json_encode($dataReal) ?>;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Curva Teórica (Planificado)',
                    data: dataPlan,
                    borderColor: '#0d6efd', // Azul
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Curva Real (Certificado)',
                    data: dataReal,
                    borderColor: '#198754', // Verde
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    borderWidth: 3,
                    pointRadius: 5,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#198754',
                    tension: 0.1,
                    spanGaps: false // Si hay NULL (futuro), la línea se corta ahí
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100, // La curva S siempre llega al 100%
                    title: { display: true, text: '% Acumulado' }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + '%';
                        }
                    }
                }
            }
        }
    });

    // --- 2. FUNCIONES DEL MODAL ---
    function abrirModalCarga(id, concepto, valorActual) {
        document.getElementById('modalItemId').value = id;
        document.getElementById('modalConceptoLabel').innerText = concepto;
        // Si valorActual es vacio o 0, limpiar input
        document.getElementById('modalValorReal').value = (valorActual && valorActual !== '') ? valorActual : '';
        
        var myModal = new bootstrap.Modal(document.getElementById('modalCarga'));
        myModal.show();
    }
</script>

</body>
</html>
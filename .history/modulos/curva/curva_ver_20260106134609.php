<?php
// modulos/curva/curva_ver.php
session_start();
// Aseguramos que el navegador interprete UTF-8
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("<div class='alert alert-danger m-4'>Error: Versión no especificada.</div>");

// 1. Datos Obra y Versión Actual
$sqlHead = "SELECT v.*, v.monto_presupuesto as monto_version, 
            o.id as obra_real_id, o.denominacion, o.monto_actualizado, o.monto_original, o.anticipo_pct, e.cuit as empresa_cuit 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id 
            LEFT JOIN empresas e ON o.empresa_id = e.id
            WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cabecera) die("<div class='alert alert-danger m-4'>No se encontró la versión de curva solicitada.</div>");

// --- LISTA DE VERSIONES ---
$sqlVersiones = "SELECT id, fecha_creacion, es_vigente FROM curva_version WHERE obra_id = ? ORDER BY id DESC";
$stmtVersiones = $pdo->prepare($sqlVersiones);
$stmtVersiones->execute([$cabecera['obra_real_id']]);
$todasLasVersiones = $stmtVersiones->fetchAll(PDO::FETCH_ASSOC);

// --- MONTO VIGENTE ---
$montoVisualizar = ($cabecera['monto_version'] > 0) ? $cabecera['monto_version'] : (($cabecera['monto_original'] > 0) ? $cabecera['monto_original'] : $cabecera['monto_original']);

// 2. Planificación (Items)
// CORRECCIÓN 1: ORDEN ABSOLUTO DEL ANTICIPO
// Primero ordenamos por el CASE (si es anticipo va primero con 0, el resto 1).
// Luego por periodo, y finalmente por ID. Esto fuerza al anticipo a subir al inicio de la tabla.
$sqlItems = "SELECT * FROM curva_items 
             WHERE version_id = ? 
             ORDER BY 
                CASE WHEN concepto LIKE '%nticipo%' THEN 0 ELSE 1 END ASC,
                periodo ASC, 
                id ASC";

$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$itemsCurva = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. Certificados Reales
$sqlCerts = "SELECT * FROM certificados WHERE obra_id = ? AND estado != 'ANULADO' ORDER BY periodo ASC, nro_certificado ASC";
$stmtC = $pdo->prepare($sqlCerts);
$stmtC->execute([$cabecera['obra_real_id']]);
$certificadosDB = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$mapaCertificados = [];
$ultimoPeriodoReal = null; 

foreach ($certificadosDB as $c) {
    $periodo = substr($c['periodo'], 0, 7);
    $mapaCertificados[$periodo][$c['tipo']][] = $c;
    
    if ($ultimoPeriodoReal === null || $periodo > $ultimoPeriodoReal) {
        $ultimoPeriodoReal = $periodo;
    }
}

// Helpers Formato
function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtPct($v) { return number_format((float)$v, 2, ',', '.') . '%'; }
function fmtFri($v) { return number_format((float)$v, 4, ',', '.'); }

// --- LÓGICA GRÁFICO ---
// Nota: Al cambiar el orden SQL, el gráfico conectará los puntos en ese orden.
// Si el anticipo tiene fecha posterior al inicio pero se fuerza primero, el gráfico podría hacer un trazo "hacia atrás".
// Generalmente el anticipo tiene avance físico 0%, por lo que no impacta visualmente la curva S física, 
// pero ten en cuenta que el eje X seguirá el orden de la tabla.
$labels = []; 
$dataPlan = []; 
$dataReal = []; 
$acumPlan = 0; 
$acumReal = 0;
$mesesProcesadosReal = []; 

foreach ($itemsCurva as $i) {
    $per = date('Y-m', strtotime($i['periodo']));
    $labelPer = date('m/y', strtotime($i['periodo']));
    
    $labels[] = $labelPer;
    
    $acumPlan += $i['porcentaje_fisico'];
    $dataPlan[] = $acumPlan;
    
    if ($ultimoPeriodoReal !== null && $per <= $ultimoPeriodoReal) {
        $avanceMes = 0; 
        if (!in_array($per, $mesesProcesadosReal)) {
            if(isset($mapaCertificados[$per]['ORDINARIO'])) {
                foreach($mapaCertificados[$per]['ORDINARIO'] as $cert) {
                    $avanceMes += $cert['avance_fisico_mensual'];
                }
            }
            $mesesProcesadosReal[] = $per;
            $acumReal += $avanceMes;
        }
        $dataReal[] = $acumReal;
    } else {
        $dataReal[] = null;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tablero - <?= htmlspecialchars($cabecera['denominacion']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .table-vcenter td { vertical-align: middle; }
        .nro-cert { font-size: 0.85em; font-weight: 700; color: #0d6efd; background: #e7f1ff; padding: 2px 6px; border-radius: 4px; border: 1px solid #cce5ff; white-space: nowrap; display: inline-block; margin-right: 4px;}
        .input-fufi { text-align: right; }
        .modal-body { min-height: 400px; }
        .col-plan { background-color: #f8f9fa; border-right: 1px solid #dee2e6; font-size: 0.8rem; padding: 4px !important; white-space: nowrap; width: 1%; }
        .col-basico { background-color: #f0fdf4; } 
        .col-redet { background-color: #fff8e1; }
        .col-action-basico { background-color: #e8f5e9; text-align: center; width: 50px; }
        .col-action-redet { background-color: #fff3cd; text-align: center; width: 50px; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="text-primary fw-bold mb-0"><i class="bi bi-kanban"></i> Gestión de Certificados</h4>
            <div class="text-muted small">
                Obra: <strong><?= htmlspecialchars($cabecera['denominacion']) ?></strong> | 
                Base: <span class="badge bg-primary text-white">$ <?= fmtM($montoVisualizar) ?></span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white fw-bold">Versión:</span>
                <select class="form-select form-select-sm fw-bold border-secondary" style="max-width: 200px;" onchange="window.location.href='curva_ver.php?version_id='+this.value">
                    <?php foreach($todasLasVersiones as $ver): 
                        $fecha = date('d/m/Y', strtotime($ver['fecha_creacion']));
                        $esActual = ($ver['id'] == $versionId);
                        $classVigente = ($ver['es_vigente']) ? 'text-success fw-bold' : 'text-muted';
                    ?>
                        <option value="<?= $ver['id'] ?>" <?= $esActual ? 'selected' : '' ?> class="<?= $classVigente ?>">
                            V.<?= $ver['id'] ?> - <?= $fecha . (($ver['es_vigente']) ? ' (VIGENTE)' : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="curva_export.php?version_id=<?= $versionId ?>" class="btn btn-success btn-sm shadow-sm"><i class="bi bi-file-excel"></i> Excel</a>
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGraph"><i class="bi bi-graph-up"></i> Gráfico</button>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="collapse show mb-4" id="collapseGraph">
        <div class="card shadow-sm border-0"><div class="card-body" style="height: 400px;"><canvas id="chartCurva"></canvas></div></div>
    </div>

    <div class="card shadow border-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 table-vcenter text-center">
                <thead class="table-dark text-uppercase small">
                    <tr>
                        <th rowspan="2" style="vertical-align: middle;">Periodo</th>
                        <th rowspan="2" style="vertical-align: middle; background-color: #495057;">Concepto</th>
                        <th colspan="5" class="bg-secondary text-white">Planificación (Original)</th>
                        <th colspan="4" class="bg-success">Básico / Anticipo</th>
                        <th colspan="3" class="bg-warning text-dark">Redeterminaciones</th>
                    </tr>
                    <tr>
                        <th class="bg-light text-dark small">Fis %</th>
                        <th class="bg-light text-dark small">Básico</th>
                        <th class="bg-light text-dark small text-primary">FRI</th> 
                        <th class="bg-light text-dark small">Redet.</th>
                        <th class="bg-light text-dark small fw-bold">Total</th>
                        
                        <th>ID</th>
                        <th>% Real</th>
                        <th>Monto</th>
                        <th class="bg-white text-dark"><i class="bi bi-gear"></i></th> 
                        
                        <th>FRI</th>
                        <th>Monto</th>
                        <th class="bg-white text-dark"><i class="bi bi-gear"></i></th> 
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($itemsCurva as $item): 
                        $per = date('Y-m', strtotime($item['periodo']));
                        $esPlanAnticipo = (stripos($item['concepto'], 'anticipo') !== false);
                        
                        // Inicialización limpia para evitar duplicidad visual
                        $certsBasicos = [];
                        $certsAnticipo = [];
                        $certsRedet = []; 

                        if ($esPlanAnticipo) {
                            // En fila Anticipo: Mostrar solo datos de anticipo y sus redeterminaciones (si las hubiera cargadas con ese tipo o fecha)
                            $certsAnticipo = $mapaCertificados[$per]['ANTICIPO'] ?? [];
                            // OPCIONAL: Si deseas ver Redeterminaciones en la fila de anticipo, descomenta la siguiente línea, 
                            // pero ten cuidado de que no sean las mismas redet del certificado de obra.
                             $certsRedet = $mapaCertificados[$per]['REDETERMINACION'] ?? []; 
                        } else {
                            // En fila Obra: Mostrar Certificados Ordinarios y Redeterminaciones
                            $certsBasicos = $mapaCertificados[$per]['ORDINARIO'] ?? [];
                            $certsRedet = $mapaCertificados[$per]['REDETERMINACION'] ?? [];
                        }
                        
                        $planPct = $item['porcentaje_fisico'];
                        $montoBase = $item['monto_base'] ?? 0;
                        $montoRedet = $item['redeterminacion'] ?? 0;
                        $montoNeto = $item['neto'] ?? ($montoBase + $montoRedet);
                        $friOriginal = $item['fri'] ?? 1.0000;
                    ?>
                    <tr>
                        <td class="fw-bold text-secondary"><?= date('m/y', strtotime($per)) ?></td>
                        <td class="text-start small fst-italic text-muted"><?= $item['concepto'] ?></td>
                        
                        <td class="col-plan fw-bold text-primary"><?= fmtPct($planPct) ?></td>
                        <td class="col-plan text-muted">$ <?= fmtM($montoBase) ?></td>
                        <td class="col-plan text-dark"><?= fmtFri($friOriginal) ?></td>
                        <td class="col-plan text-muted">$ <?= fmtM($montoRedet) ?></td>
                        <td class="col-plan fw-bold text-dark border-end" style="background-color: #e2e3e5;">$ <?= fmtM($montoNeto) ?></td>

                        <td class="col-basico">
                            <?php 
                            if($esPlanAnticipo && !empty($certsAnticipo)): foreach($certsAnticipo as $ca): ?><span class="nro-cert">#<?= $ca['nro_certificado'] ?></span><?php endforeach; 
                            elseif(!empty($certsBasicos)): foreach($certsBasicos as $cb): ?><div class="mb-1"><span class="nro-cert">#<?= $cb['nro_certificado'] ?></span></div><?php endforeach; 
                            else: ?> - <?php endif; ?>
                        </td>
                        <td class="col-basico">
                            <?php 
                            if($esPlanAnticipo): ?><span class="badge bg-secondary">Anticipo</span><?php 
                            elseif(!empty($certsBasicos)): foreach($certsBasicos as $cb): ?><div class="fw-bold text-success mb-1"><?= fmtPct($cb['avance_fisico_mensual']) ?></div><?php endforeach; 
                            else: ?> - <?php endif; ?>
                        </td>
                        <td class="col-basico">
                            <?php 
                            if($esPlanAnticipo && !empty($certsAnticipo)): ?><div class="small fw-bold">$ <?= fmtM($certsAnticipo[0]['monto_bruto']) ?></div><?php 
                            elseif(!empty($certsBasicos)): foreach($certsBasicos as $cb): ?><div class="small fw-bold mb-1">$ <?= fmtM($cb['monto_basico']) ?></div><?php endforeach; 
                            else: ?> - <?php endif; ?>
                        </td>

                        <td class="col-action-basico border-end">
                            <?php if($esPlanAnticipo): ?>
                                <?php if(empty($certsAnticipo)): ?>
                                    <button class="btn btn-sm btn-outline-primary py-0 px-1" title="Cargar Anticipo" onclick="abrirModalNew('ANTICIPO', '<?= $per ?>', 0)"><i class="bi bi-plus-lg"></i></button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-primary py-0 px-1" title="Editar Anticipo" onclick="abrirModalEdit(<?= $certsAnticipo[0]['id'] ?>)"><i class="bi bi-pencil-fill"></i></button>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if(empty($certsBasicos)): ?>
                                    <button class="btn btn-sm btn-success py-0 px-1 shadow-sm" title="Cargar Certificado" onclick="abrirModalNew('ORDINARIO', '<?= $per ?>', <?= $planPct ?>)"><i class="bi bi-plus-lg"></i></button>
                                <?php else: foreach($certsBasicos as $cb): ?>
                                    <button class="btn btn-sm btn-outline-success py-0 px-1 mb-1 d-block mx-auto" title="Editar Certificado" onclick="abrirModalEdit(<?= $cb['id'] ?>)"><i class="bi bi-pencil-square"></i></button>
                                <?php endforeach; endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="col-redet">
                            <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): echo "<div class='text-fri mb-1'>".fmtFri($cr['fri'])."</div>"; endforeach; endif; ?>
                        </td>
                        <td class="col-redet">
                            <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): echo "<div class='small fw-bold text-dark mb-1'>$ ".fmtM($cr['monto_redeterminado'])."</div>"; endforeach; endif; ?>
                        </td>
                        
                        <td class="col-action-redet">
                            <div class="d-flex justify-content-center gap-1 flex-wrap">
                                <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): ?>
                                    <button class="btn btn-sm btn-warning py-0 px-1 text-dark" title="Editar Redet." onclick="abrirModalEdit(<?= $cr['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                <?php endforeach; endif; ?>
                                <button class="btn btn-sm btn-outline-warning text-dark py-0 px-1" title="Agregar Redet." onclick="abrirModalNew('REDETERMINACION', '<?= $per ?>', 0)"><i class="bi bi-plus"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const montoObra = <?= json_encode((float)$montoVisualizar) ?>;
const pctAnticipoObra = <?= json_encode((float)$cabecera['anticipo_pct']) ?>;

const ctx = document.getElementById('chartCurva').getContext('2d');
new Chart(ctx, { 
    type: 'line', 
    data: { 
        labels: <?= json_encode($labels) ?>, 
        datasets: [ 
            { label: 'Plan', data: <?= json_encode($dataPlan) ?>, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true }, 
            { label: 'Real', data: <?= json_encode($dataReal) ?>, borderColor: '#198754' } 
        ] 
    }, 
    options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, grace: '5%' } }, plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + context.parsed.y.toLocaleString('es-AR') + '%'; } } } } } 
});

function abrirModalNew(tipo, periodo, planPct) {
    resetModal(tipo, periodo, 0);
    document.getElementById('modalTitulo').innerText = "Nuevo: " + tipo + " (" + periodo + ")";
    document.getElementById('inputNroCert').value = ""; 
    
    if(tipo==='ORDINARIO' || tipo==='ANTICIPO') {
        document.querySelector('.div-ordinario').classList.remove('d-none');
        document.getElementById('lblInputAvance').innerText = (tipo==='ANTICIPO') ? "% Anticipo Financiero" : "% Avance Físico";
        if(tipo==='ORDINARIO') {
            document.getElementById('inputAvance').value = planPct;
            document.getElementById('lblPlanificado').innerText = "(Plan: " + planPct + "%)";
            calcBasico();
        }
    }
    if(tipo==='ANTICIPO') document.getElementById('inputAnticipoPct').value = 0; 
    loadFuentesBase(true);
    new bootstrap.Modal(document.getElementById('modalCert')).show();
}

function abrirModalEdit(id) {
    fetch('../certificados/api_get_certificado.php?id=' + id)
    .then(r => r.json())
    .then(data => {
        resetModal(data.tipo, data.periodo, id);
        document.getElementById('modalTitulo').innerText = "Editar: " + data.tipo + " (CO N° " + data.nro_certificado + ")";
        document.getElementById('inputNroCert').value = data.nro_certificado;
        if(data.tipo==='ORDINARIO' || data.tipo==='ANTICIPO') {
            document.querySelector('.div-ordinario').classList.remove('d-none');
            document.getElementById('lblInputAvance').innerText = (data.tipo==='ANTICIPO') ? "% Anticipo Financiero" : "% Avance Físico";
            if(data.tipo==='ORDINARIO') document.getElementById('inputAvance').value = data.avance_fisico_mensual;
        }
        if(data.tipo==='REDETERMINACION') document.getElementById('inputFri').value = data.fri;
        document.getElementById('inputMontoBruto').value = fmtM(data.monto_bruto);
        document.getElementById('checkSustituido').checked = (data.fondo_reparo_sustituido == 1);
        document.getElementById('inputAnticipoPct').value = data.anticipo_pct_aplicado;
        document.getElementById('inputMultas').value = fmtM(data.multas_monto);
        if(data.fuentes) renderFuentes(data.fuentes);
        calcDeducciones();
        new bootstrap.Modal(document.getElementById('modalCert')).show();
    });
}

function resetModal(tipo, periodo, id) {
    document.getElementById('formModal').reset();
    document.getElementById('modalCertId').value = id;
    document.getElementById('modalTipo').value = tipo;
    document.getElementById('modalPeriodo').value = periodo;
    document.getElementById('containerFufi').innerHTML = '';
    document.querySelectorAll('.div-ordinario, .div-redet').forEach(e => e.classList.add('d-none'));
    if(tipo==='ORDINARIO' || tipo==='ANTICIPO') document.querySelectorAll('.div-ordinario').forEach(e => e.classList.remove('d-none'));
    if(tipo==='REDETERMINACION') document.querySelectorAll('.div-redet').forEach(e => e.classList.remove('d-none'));
    document.getElementById('inputAnticipoPct').value = pctAnticipoObra;
}

function calcBasico() {
    let pct = parseFloat(document.getElementById('inputAvance').value) || 0;
    let monto = montoObra * (pct / 100);
    document.getElementById('inputMontoBruto').value = fmtM(monto);
    calcDeducciones();
}

function calcDeducciones() {
    let bruto = parseM(document.getElementById('inputMontoBruto').value);
    let tipo = document.getElementById('modalTipo').value;
    if(document.activeElement.id === 'inputMontoBruto' && montoObra > 0) {
        let pct = (bruto / montoObra) * 100;
        document.getElementById('inputAvance').value = pct.toFixed(2);
    }
    let fr = 0;
    if(!document.getElementById('checkSustituido').checked) fr = bruto * 0.05;
    document.getElementById('inputFondoReparo').value = fmtM(fr);
    let ant = 0;
    if(tipo !== 'ANTICIPO') {
        let pctAnt = parseFloat(document.getElementById('inputAnticipoPct').value) || 0;
        ant = bruto * (pctAnt / 100);
    } else { document.getElementById('inputAnticipoPct').value = 0; }
    document.getElementById('inputAnticipoMonto').value = fmtM(ant);
    let mul = parseM(document.getElementById('inputMultas').value);
    let neto = bruto - fr - ant - mul;
    document.getElementById('lblNeto').innerText = "$ " + fmtM(neto);
    document.getElementById('inputNeto').value = neto.toFixed(2);
}

function recalcularFuentes(force = false) {
    let baseCalculo = parseM(document.getElementById('inputMontoBruto').value) || 0;
    document.querySelectorAll('#containerFufi .row').forEach(row => {
        let pct = parseFloat(row.querySelector('input[name="fuente_pct[]"]').value) || 0;
        let monto = baseCalculo * (pct/100);
        row.querySelector('input[name="fuente_monto[]"]').value = fmtM(monto);
    });
}

function loadFuentesBase(recalc) {
    fetch('../../modulos/certificados/api_get_fuentes.php?obra_id=<?= $cabecera['obra_real_id'] ?>')
    .then(r => r.json()).then(data => {
        let render = data.map(f => ({ fuente_id: f.fuente_id, codigo: f.codigo, nombre: f.nombre, porcentaje: f.porcentaje, monto_asignado: 0 }));
        renderFuentes(render);
        if(recalc) recalcularFuentes();
    });
}
function renderFuentes(lista) {
    const div = document.getElementById('containerFufi');
    div.innerHTML = '';
    lista.forEach(f => {
        let cod = f.codigo ? `<span class="badge bg-secondary me-1">${f.codigo}</span>` : '';
        let val = (f.monto_asignado !== undefined && f.monto_asignado !== null) ? f.monto_asignado : 0;
        div.innerHTML += `<div class="row mb-1 g-1 align-items-center small"><div class="col-7">${cod} ${f.nombre} (${f.porcentaje}%)<input type="hidden" name="fuente_id[]" value="${f.fuente_id}"><input type="hidden" name="fuente_pct[]" value="${f.porcentaje}"></div><div class="col-5"><input type="text" name="fuente_monto[]" class="input-fufi monto form-control form-control-sm" value="${fmtM(val)}"></div></div>`;
    });
    bindMasks();
}

function parseM(v) { if(!v) return 0; let clean = v.toString().replace(/\./g,'').replace(',','.'); return parseFloat(clean) || 0; }
function fmtM(v) { return parseFloat(v).toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
function bindMasks() { document.querySelectorAll('.monto').forEach(el => { el.removeEventListener('blur', onBlurMonto); el.removeEventListener('focus', onFocusMonto); el.addEventListener('blur', onBlurMonto); el.addEventListener('focus', onFocusMonto); }); }
function onBlurMonto() { this.value = fmtM(parseM(this.value)); }
function onFocusMonto() { let val = parseM(this.value); this.value = (val === 0) ? '' : val; }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
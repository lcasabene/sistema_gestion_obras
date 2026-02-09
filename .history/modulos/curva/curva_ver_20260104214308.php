<?php
// modulos/curva/curva_ver.php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("Versión no especificada.");

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

if (!$cabecera) die("No se encontró la versión de curva.");

// --- LISTA DE TODAS LAS VERSIONES (Para el selector) ---
$sqlVersiones = "SELECT id, fecha_creacion, es_vigente FROM curva_version WHERE obra_id = ? ORDER BY id DESC";
$stmtVersiones = $pdo->prepare($sqlVersiones);
$stmtVersiones->execute([$cabecera['obra_real_id']]);
$todasLasVersiones = $stmtVersiones->fetchAll(PDO::FETCH_ASSOC);

// --- LÓGICA DE MONTO VIGENTE PARA ESTA VISUALIZACIÓN ---
// Usamos el monto guardado en LA VERSIÓN (snapshot histórico) para ser fieles a lo que se planificó en ese momento.
// Si por alguna razón es 0 (datos viejos), usamos el de la obra.
$montoVisualizar = ($cabecera['monto_version'] > 0) ? $cabecera['monto_version'] : (($cabecera['monto_actualizado'] > 0) ? $cabecera['monto_actualizado'] : $cabecera['monto_original']);

// 2. Planificación
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$itemsCurva = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. Certificados Reales
$sqlCerts = "SELECT * FROM certificados WHERE obra_id = ? AND estado != 'ANULADO' ORDER BY periodo ASC, nro_certificado ASC";
$stmtC = $pdo->prepare($sqlCerts);
$stmtC->execute([$cabecera['obra_real_id']]);
$certificadosDB = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$mapaCertificados = [];
foreach ($certificadosDB as $c) {
    $periodo = substr($c['periodo'], 0, 7);
    $mapaCertificados[$periodo][$c['tipo']][] = $c;
}

function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtPct($v) { return number_format((float)$v, 2, ',', '.') . '%'; }
function fmtFri($v) { return number_format((float)$v, 4, ',', '.'); }

// Gráfico
$labels = []; $dataPlan = []; $dataReal = []; $acumPlan = 0; $acumReal = 0;
foreach ($itemsCurva as $i) {
    if (stripos($i['concepto'], 'anticipo') === false) {
        $per = date('Y-m', strtotime($i['periodo']));
        $labels[] = date('m/y', strtotime($i['periodo']));
        $acumPlan += $i['porcentaje_fisico'];
        $dataPlan[] = $acumPlan;
        
        $avanceMes = 0; $hubo = false;
        if(isset($mapaCertificados[$per]['ORDINARIO'])) {
            foreach($mapaCertificados[$per]['ORDINARIO'] as $cert) {
                $avanceMes += $cert['avance_fisico_mensual'];
                $hubo = true;
            }
        }
        if ($hubo) { $acumReal += $avanceMes; $dataReal[] = $acumReal; } else { $dataReal[] = null; }
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
        .table-vcenter td { vertical-align: middle; font-size: 0.9rem; }
        .col-plan { background-color: #f8f9fa; border-right: 2px solid #dee2e6; }
        .col-basico { background-color: #f0fdf4; } 
        .col-redet { background-color: #fff8e1; }
        .nro-cert { font-size: 0.85em; font-weight: 700; color: #0d6efd; background: #e7f1ff; padding: 2px 6px; border-radius: 4px; border: 1px solid #cce5ff; white-space: nowrap; display: inline-block; margin-right: 4px;}
        .input-fufi { text-align: right; }
        .modal-body { min-height: 400px; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="text-primary fw-bold mb-0"><i class="bi bi-kanban"></i> Gestión de Certificados</h4>
            <div class="text-muted small">
                Obra: <strong><?= htmlspecialchars($cabecera['denominacion']) ?></strong> | 
                Base Curva: <span class="badge bg-primary text-white" style="font-size: 0.9em;">$ <?= fmtM($montoVisualizar) ?></span> |
                Anticipo: <strong><?= $cabecera['anticipo_pct'] ?>%</strong>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white fw-bold">Versión:</span>
                <select class="form-select form-select-sm fw-bold border-secondary" style="max-width: 200px;" onchange="window.location.href='curva_ver.php?version_id='+this.value">
                    <?php foreach($todasLasVersiones as $ver): 
                        $fecha = date('d/m/Y', strtotime($ver['fecha_creacion']));
                        $esActual = ($ver['id'] == $versionId);
                        $txtVigente = ($ver['es_vigente']) ? ' (VIGENTE)' : '';
                        $classVigente = ($ver['es_vigente']) ? 'text-success fw-bold' : 'text-muted';
                    ?>
                        <option value="<?= $ver['id'] ?>" <?= $esActual ? 'selected' : '' ?> class="<?= $classVigente ?>">
                            V.<?= $ver['id'] ?> - <?= $fecha . $txtVigente ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <a href="curva_export.php?version_id=<?= $versionId ?>" class="btn btn-success btn-sm shadow-sm">
                <i class="bi bi-file-excel"></i> Excel
            </a>
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGraph">
                <i class="bi bi-graph-up"></i> Gráfico
            </button>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="collapse show mb-4" id="collapseGraph">
        <div class="card shadow-sm border-0"><div class="card-body" style="height: 250px;"><canvas id="chartCurva"></canvas></div></div>
    </div>

    <div class="card shadow border-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 table-vcenter text-center">
                <thead class="table-dark text-uppercase small">
                    <tr><th rowspan="2">Periodo</th><th colspan="2" class="bg-secondary">Planificación</th><th colspan="4" class="bg-success">Básico / Anticipo</th><th colspan="3" class="bg-warning text-dark">Redeterminaciones</th></tr>
                    <tr><th>% Fis</th><th>Monto</th><th>ID</th><th>% Real</th><th>Monto</th><th>Acción</th><th>FRI</th><th>Monto</th><th>Acción</th></tr>
                </thead>
                <tbody>
                    <?php foreach($itemsCurva as $item): 
                        $per = date('Y-m', strtotime($item['periodo']));
                        $esAnticipo = (stripos($item['concepto'], 'anticipo') !== false);
                        $certsBasicos = $mapaCertificados[$per]['ORDINARIO'] ?? [];
                        $certsRedet = $mapaCertificados[$per]['REDETERMINACION'] ?? [];
                        $certsAnticipo = $mapaCertificados[$per]['ANTICIPO'] ?? [];
                        $planPct = $item['porcentaje_fisico'];
                    ?>
                    <tr>
                        <td class="fw-bold text-secondary"><?= date('m/Y', strtotime($per)) ?></td>
                        <td class="col-plan fw-bold text-primary"><?= fmtPct($item['porcentaje_fisico']) ?></td>
                        <td class="col-plan text-muted small border-end">$ <?= fmtM($item['neto']) ?></td>

                        <td class="col-basico">
                            <?php if($esAnticipo && !empty($certsAnticipo)): foreach($certsAnticipo as $ca): ?><span class="nro-cert">CO Nº <?= $ca['nro_certificado'] ?></span><?php endforeach; elseif(!empty($certsBasicos)): foreach($certsBasicos as $cb): ?><div class="mb-1"><span class="nro-cert">CO Nº <?= $cb['nro_certificado'] ?></span></div><?php endforeach; else: ?> - <?php endif; ?>
                        </td>
                        <td class="col-basico">
                            <?php if($esAnticipo): ?><span class="badge bg-secondary">Anticipo</span><?php elseif(!empty($certsBasicos)): foreach($certsBasicos as $cb): ?><div class="fw-bold text-success mb-1"><?= fmtPct($cb['avance_fisico_mensual']) ?></div><?php endforeach; else: ?> - <?php endif; ?>
                        </td>
                        <td class="col-basico">
                            <?php if($esAnticipo): ?>
                                <?php if(!empty($certsAnticipo)): ?>
                                    <div class="small fw-bold">$ <?= fmtM($certsAnticipo[0]['monto_bruto']) ?></div>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if(!empty($certsBasicos)): ?>
                                    <?php foreach($certsBasicos as $cb): ?>
                                        <div class="small fw-bold mb-1">$ <?= fmtM($cb['monto_basico']) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-basico border-end">
                            <?php if($esAnticipo): ?>
                                <?php if(empty($certsAnticipo)): ?><button class="btn btn-sm btn-outline-primary py-0" onclick="abrirModalNew('ANTICIPO', '<?= $per ?>', 0)"><i class="bi bi-plus"></i></button><?php else: ?><button class="btn btn-sm btn-link py-0" onclick="abrirModalEdit(<?= $certsAnticipo[0]['id'] ?>)"><i class="bi bi-pencil"></i></button><?php endif; ?>
                            <?php else: ?>
                                <?php if(empty($certsBasicos)): ?><button class="btn btn-sm btn-success py-0 shadow-sm" onclick="abrirModalNew('ORDINARIO', '<?= $per ?>', <?= $planPct ?>)"><i class="bi bi-plus-lg"></i></button><?php else: foreach($certsBasicos as $cb): ?><button class="btn btn-sm btn-outline-success py-0 mb-1 d-block mx-auto" onclick="abrirModalEdit(<?= $cb['id'] ?>)"><i class="bi bi-pencil-square"></i></button><?php endforeach; endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="col-redet"><?php if(!empty($certsRedet)): foreach($certsRedet as $cr): echo "<div class='text-fri mb-1'>FRI: ".fmtFri($cr['fri'])."</div>"; endforeach; endif; ?></td>
                        <td class="col-redet"><?php if(!empty($certsRedet)): foreach($certsRedet as $cr): echo "<div class='small fw-bold text-dark mb-1'>$ ".fmtM($cr['monto_redeterminado'])."</div>"; endforeach; endif; ?></td>
                        <td class="col-redet">
                            <div class="d-flex justify-content-center gap-1 flex-wrap">
                                <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): ?><button class="btn btn-sm btn-link py-0 text-dark" onclick="abrirModalEdit(<?= $cr['id'] ?>)"><i class="bi bi-pencil"></i></button><?php endforeach; endif; ?>
                                <button class="btn btn-sm btn-outline-dark py-0" onclick="abrirModalNew('REDETERMINACION', '<?= $per ?>', 0)"><i class="bi bi-plus"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCert" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="modalTitulo">Certificado</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form action="../certificados/certificados_guardar_modal.php" method="POST" id="formModal">
                <input type="hidden" name="obra_id" value="<?= $cabecera['obra_real_id'] ?>">
                <input type="hidden" name="periodo" id="modalPeriodo">
                <input type="hidden" name="tipo" id="modalTipo">
                <input type="hidden" name="cert_id" id="modalCertId" value="0">
                <input type="hidden" id="cuit_empresa" value="<?= $cabecera['empresa_cuit'] ?>">
                <input type="hidden" id="data_anticipo_pct_obra" value="<?= $cabecera['anticipo_pct'] ?>">

                <div class="modal-body">
                    <div class="row align-items-center mb-3">
                        <div class="col-md-6"><div class="alert alert-secondary py-1 small mb-0 fw-bold"><i class="bi bi-info-circle"></i> Obra: $ <?= fmtM($montoVisualizar) ?></div></div>
                        <div class="col-md-6"><div class="input-group input-group-sm"><span class="input-group-text bg-dark text-white fw-bold">N° Cert.</span><input type="number" name="nro_certificado" id="inputNroCert" class="form-control fw-bold" placeholder="Auto"></div></div>
                    </div>

                    <div class="row mb-3 bg-light p-2 rounded mx-0 border">
                        <div class="col-12 border-bottom mb-2 pb-1 text-muted small text-uppercase fw-bold">1. Valores Principales</div>
                        <div class="col-md-6 div-ordinario d-none">
                            <label class="form-label small fw-bold" id="lblInputAvance">% Avance</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="avance_fisico" id="inputAvance" class="form-control fw-bold text-center" oninput="calcBasico()">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-primary" id="lblPlanificado"></small>
                        </div>
                        <div class="col-md-3 div-redet d-none"><label class="form-label small fw-bold">FRI</label><input type="number" step="0.0001" name="fri" id="inputFri" class="form-control text-center" placeholder="1.0000"></div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Monto Bruto ($)</label>
                            <input type="text" name="monto_bruto" id="inputMontoBruto" class="form-control fw-bold text-end monto" required oninput="calcDeducciones()">
                        </div>
                    </div>

                    <div class="row mb-3 mx-0">
                        <div class="col-12 text-muted small text-uppercase fw-bold border-bottom mb-2">2. Deducciones</div>
                        <div class="col-md-4 mb-2"><label class="small">Fondo Reparo</label><div class="input-group input-group-sm mb-1"><span class="input-group-text">5%</span><div class="input-group-text bg-white"><input class="form-check-input mt-0" type="checkbox" name="fondo_reparo_sustituido" id="checkSustituido" value="1" onchange="calcDeducciones()"><label class="small ms-1 mb-0" for="checkSustituido">Sust.</label></div></div><input type="text" name="fondo_reparo_monto" id="inputFondoReparo" class="form-control form-control-sm text-end text-danger monto" readonly></div>
                        <div class="col-md-4 mb-2"><label class="small">Dev. Anticipo</label><div class="input-group input-group-sm mb-1"><span class="input-group-text text-muted"><?= $cabecera['anticipo_pct'] ?>%</span><input type="number" step="0.01" name="anticipo_pct_aplicado" id="inputAnticipoPct" class="form-control form-control-sm" value="<?= $cabecera['anticipo_pct'] ?>" oninput="calcDeducciones()"></div><input type="text" name="anticipo_descuento" id="inputAnticipoMonto" class="form-control form-control-sm text-end text-danger monto" readonly></div>
                        <div class="col-md-4 mb-2"><label class="small">Multas / Otros</label><input type="text" name="multas_monto" id="inputMultas" class="form-control form-control-sm text-end text-danger monto" value="0,00" oninput="calcDeducciones()"></div>
                    </div>

                    <div class="row mb-3 mx-0">
                        <div class="col-12 border-bottom mb-2 pb-1 d-flex justify-content-between align-items-center">
                            <span class="text-muted small text-uppercase fw-bold">3. Fuentes de Financiamiento</span>
                            <button type="button" class="btn btn-link btn-sm py-0" onclick="recalcularFuentes()">Autocalcular</button>
                        </div>
                        <div class="col-12" id="containerFufi"></div>
                    </div>

                    <div class="alert alert-primary py-2 d-flex justify-content-between align-items-center mb-0">
                        <span class="fw-bold">NETO A PAGAR:</span>
                        <h4 class="mb-0 fw-bold" id="lblNeto">$ 0,00</h4>
                        <input type="hidden" name="monto_neto" id="inputNeto">
                    </div>
                </div>
                <div class="modal-footer bg-light p-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Datos: Usamos montoVisualizar (PHP) para que el JS use el valor de la versión actual
const montoObra = <?= $montoVisualizar ?>;
const pctAnticipoObra = <?= $cabecera['anticipo_pct'] ?>;

// --- CHART.JS CONFIGURACIÓN "EFECTO LUPA" ---
const ctx = document.getElementById('chartCurva').getContext('2d');
new Chart(ctx, { 
    type: 'line', 
    data: { 
        labels: <?= json_encode($labels) ?>, 
        datasets: [ 
            { 
                label: 'Plan', 
                data: <?= json_encode($dataPlan) ?>, 
                borderColor: '#0d6efd', 
                backgroundColor: 'rgba(13,110,253,0.1)', 
                fill: true 
            }, 
            { 
                label: 'Real', 
                data: <?= json_encode($dataReal) ?>, 
                borderColor: '#198754' 
            } 
        ] 
    }, 
    options: { 
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: false, 
                grace: '5%'        
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y.toLocaleString('es-AR') + '%';
                    }
                }
            }
        }
    } 
});

// --- MODALES ---
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
        
        // Renderizar fuentes antes de calcular
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

// --- CÁLCULOS ---
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

    // 1. Fondo Reparo
    let fr = 0;
    if(!document.getElementById('checkSustituido').checked) fr = bruto * 0.05;
    document.getElementById('inputFondoReparo').value = fmtM(fr);

    // 2. Dev. Anticipo
    let ant = 0;
    if(tipo !== 'ANTICIPO') {
        let pctAnt = parseFloat(document.getElementById('inputAnticipoPct').value) || 0;
        ant = bruto * (pctAnt / 100);
    } else {
        document.getElementById('inputAnticipoPct').value = 0;
    }
    document.getElementById('inputAnticipoMonto').value = fmtM(ant);

    // 3. Multas
    let mul = parseM(document.getElementById('inputMultas').value);

    let neto = bruto - fr - ant - mul;
    document.getElementById('lblNeto').innerText = "$ " + fmtM(neto);
    // IMPORTANTE: Guardamos el neto en formato máquina para JS (sin comas ni puntos de mil)
    document.getElementById('inputNeto').value = neto.toFixed(2);
    
    recalcularFuentes(); 
}

function recalcularFuentes() {
    // CORRECCIÓN: Usamos parseFloat porque inputNeto ya tiene punto decimal (viene de toFixed)
    let neto = parseFloat(document.getElementById('inputNeto').value) || 0;
    
    document.querySelectorAll('#containerFufi .row').forEach(row => {
        let pct = parseFloat(row.querySelector('input[name="fuente_pct[]"]').value) || 0;
        let monto = neto * (pct/100);
        row.querySelector('input[name="fuente_monto[]"]').value = fmtM(monto);
    });
}

// --- FUENTES ---
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
        div.innerHTML += `
            <div class="row mb-1 g-1 align-items-center small">
                <div class="col-7">${cod} ${f.nombre} (${f.porcentaje}%)
                    <input type="hidden" name="fuente_id[]" value="${f.fuente_id}">
                    <input type="hidden" name="fuente_pct[]" value="${f.porcentaje}">
                </div>
                <div class="col-5">
                    <input type="text" name="fuente_monto[]" class="input-fufi monto form-control form-control-sm" value="${fmtM(val)}">
                </div>
            </div>`;
    });
    bindMasks();
}

// --- UTILS ---
function parseM(v) { 
    if(!v) return 0;
    let clean = v.toString().replace(/\./g,'').replace(',','.'); 
    return parseFloat(clean) || 0; 
}
function fmtM(v) { 
    return parseFloat(v).toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); 
}
function bindMasks() { 
    document.querySelectorAll('.monto').forEach(el => { 
        el.removeEventListener('blur', onBlurMonto);
        el.removeEventListener('focus', onFocusMonto);
        el.addEventListener('blur', onBlurMonto); 
        el.addEventListener('focus', onFocusMonto); 
    }); 
}
function onBlurMonto() { this.value = fmtM(parseM(this.value)); }
function onFocusMonto() { 
    let val = parseM(this.value);
    this.value = (val === 0) ? '' : val; 
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
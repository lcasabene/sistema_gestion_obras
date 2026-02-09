<?php
// modulos/curva/curva_ver.php
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("<div class='alert alert-danger m-4'>Error: Versión no especificada.</div>");

// --------------------------------------------------------------------------
// 1. DATOS CABECERA (OBRA Y VERSIÓN)
// --------------------------------------------------------------------------
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

// Monto base para cálculos visuales
$montoVisualizar = ($cabecera['monto_version'] > 0) ? $cabecera['monto_version'] : (($cabecera['monto_original'] > 0) ? $cabecera['monto_original'] : $cabecera['monto_original']);

// --------------------------------------------------------------------------
// 2. LISTA DE VERSIONES (Para el selector)
// --------------------------------------------------------------------------
$sqlVersiones = "SELECT id, fecha_creacion, es_vigente FROM curva_version WHERE obra_id = ? ORDER BY id DESC";
$stmtVersiones = $pdo->prepare($sqlVersiones);
$stmtVersiones->execute([$cabecera['obra_real_id']]);
$todasLasVersiones = $stmtVersiones->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------------------------------
// 3. PLANIFICACIÓN (ITEMS CURVA)
// --------------------------------------------------------------------------
$sqlItems = "SELECT * FROM curva_items 
             WHERE version_id = ? 
             ORDER BY 
                CASE WHEN concepto LIKE '%nticipo%' THEN 0 ELSE 1 END ASC,
                periodo ASC, 
                id ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$itemsCurva = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// Extraemos los IDs para búsqueda eficiente
$idsItems = array_column($itemsCurva, 'id');
$idsString = !empty($idsItems) ? implode(',', array_map('intval', $idsItems)) : '0';

// --------------------------------------------------------------------------
// 4. CERTIFICADOS REALES (LÓGICA POR ID DE VINCULACIÓN)
// --------------------------------------------------------------------------
$mapaCertificados = []; 
$ultimoPeriodoReal = null; 

if (!empty($idsItems)) {
    // Buscamos certificados vinculados estrictamente a estos items
    $sqlCerts = "SELECT * FROM certificados 
                 WHERE estado != 'ANULADO' 
                 AND curva_item_id IN ($idsString) 
                 ORDER BY nro_certificado ASC";
    
    $stmtC = $pdo->query($sqlCerts);
    while ($row = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        // Agrupamos: [ID_ITEM] => [TIPO] => [Lista]
        $mapaCertificados[$row['curva_item_id']][$row['tipo']][] = $row;
        
        // Guardamos la última fecha real registrada para el corte del gráfico
        $per = substr($row['periodo'], 0, 7);
        if ($ultimoPeriodoReal === null || $per > $ultimoPeriodoReal) {
            $ultimoPeriodoReal = $per;
        }
    }
}

// Helpers de Formato
function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtPct($v) { return number_format((float)$v, 2, ',', '.') . '%'; }
function fmtFri($v) { return number_format((float)$v, 4, ',', '.'); }

// --------------------------------------------------------------------------
// 5. DATOS PARA GRÁFICO
// --------------------------------------------------------------------------
$labels = []; $dataPlan = []; $dataReal = []; 
$acumPlan = 0; $acumReal = 0;

foreach ($itemsCurva as $i) {
    if (stripos($i['concepto'], 'anticipo') !== false) continue; // Ignoramos anticipo

    $per = date('Y-m', strtotime($i['periodo']));
    $labels[] = date('m/y', strtotime($i['periodo']));
    
    // 1. Acumulado Planificado (Siempre se muestra hasta el final)
    $acumPlan += $i['porcentaje_fisico'];
    $dataPlan[] = $acumPlan;
    
    // 2. Acumulado Real (Cálculo)
    $avanceItem = 0;
    if (isset($mapaCertificados[$i['id']]['ORDINARIO'])) {
        foreach ($mapaCertificados[$i['id']]['ORDINARIO'] as $c) {
            $avanceItem += $c['avance_fisico_mensual'];
        }
    }
    $acumReal += $avanceItem;

    // 3. Lógica de Corte (CORREGIDA)
    // Solo agregamos el dato al array si existe un "último periodo real" 
    // y el periodo actual es menor o igual a ese último.
    // De lo contrario, enviamos null para que la línea se corte.
    if ($ultimoPeriodoReal && $per <= $ultimoPeriodoReal) {
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
        .modal-body { min-height: 250px; }
        .col-plan { background-color: #f8f9fa; border-right: 1px solid #dee2e6; font-size: 0.8rem; padding: 4px !important; white-space: nowrap; width: 1%; }
        .col-basico { background-color: #f0fdf4; } 
        .col-redet { background-color: #fff8e1; }
        .col-action { width: 50px; text-align: center; }
        .debug-id { font-size: 0.65rem; color: #999; background: #f8f9fa; border: 1px solid #ddd; width: 30px; text-align: center; display: inline-block; margin-right: 3px; }
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
        <div class="card shadow-sm border-0">
            <div class="card-body" style="height: 250px;">
                <canvas id="chartCurva"></canvas>
            </div>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 table-vcenter text-center">
                <thead class="table-dark text-uppercase small">
                    <tr>
                        <th rowspan="2" style="vertical-align: middle;">Periodo</th>
                        <th rowspan="2" style="vertical-align: middle; background-color: #495057;">Concepto</th>
                        <th colspan="5" class="bg-secondary text-white">Planificación (Meta)</th>
                        <th colspan="4" class="bg-success">Ejecución (Básico)</th>
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
                        
                        // ID DE VINCULACIÓN (CLAVE)
                        $itemId = $item['id'];
                        
                        // RECUPERAR DATOS POR ID
                        $certsBasicos  = $mapaCertificados[$itemId]['ORDINARIO'] ?? [];
                        $certsAnticipo = $mapaCertificados[$itemId]['ANTICIPO'] ?? [];
                        $certsRedet    = $mapaCertificados[$itemId]['REDETERMINACION'] ?? [];
                        
                        $certsPrincipal = $esPlanAnticipo ? $certsAnticipo : $certsBasicos;
                        $tipoPrincipal  = $esPlanAnticipo ? 'ANTICIPO' : 'ORDINARIO';

                        // Valores Plan
                        $planPct = $item['porcentaje_fisico'];
                        $montoBase = $item['monto_base'] ?? 0;
                        $montoRedet = $item['redeterminacion'] ?? 0;
                        $montoNeto = ($montoBase + $montoRedet);
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
                            <?php if(!empty($certsPrincipal)): foreach($certsPrincipal as $cb): ?>
                                <div class="mb-1"><span class="nro-cert" title="<?= $cb['estado'] ?>">#<?= $cb['nro_certificado'] ?></span></div>
                            <?php endforeach; else: ?> - <?php endif; ?>
                        </td>
                        <td class="col-basico">
                            <?php if($esPlanAnticipo): ?>
                                <span class="badge bg-secondary">Anticipo</span>
                            <?php elseif(!empty($certsPrincipal)): foreach($certsPrincipal as $cb): ?>
                                <div class="fw-bold text-success mb-1"><?= fmtPct($cb['avance_fisico_mensual']) ?></div>
                            <?php endforeach; else: ?> - <?php endif; ?>
                        </td>
                        <td class="col-basico">
                            <?php if(!empty($certsPrincipal)): foreach($certsPrincipal as $cb): ?>
                                <div class="small fw-bold mb-1">$ <?= fmtM($cb['monto_bruto']) ?></div>
                            <?php endforeach; else: ?> - <?php endif; ?>
                        </td>

                        <td class="col-action col-basico border-end">
                            <?php if(empty($certsPrincipal)): ?>
                                <button class="btn btn-sm btn-success py-0 px-1 shadow-sm" title="Cargar Certificado" 
                                        onclick="abrirModalNew('<?= $tipoPrincipal ?>', '<?= $per ?>', <?= $planPct ?>, <?= $itemId ?>)">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            <?php else: foreach($certsPrincipal as $cb): ?>
                                <button class="btn btn-sm btn-outline-success py-0 px-1 mb-1 d-block mx-auto" title="Editar" 
                                        onclick="abrirModalEdit(<?= $cb['id'] ?>, <?= $itemId ?>)">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            <?php endforeach; endif; ?>
                        </td>

                        <td class="col-redet">
                            <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): ?>
                                <div class="text-fri mb-1"><?= fmtFri($cr['fri']) ?></div>
                            <?php endforeach; endif; ?>
                        </td>
                        <td class="col-redet">
                            <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): ?>
                                <div class="small fw-bold text-dark mb-1">$ <?= fmtM($cr['monto_redeterminado']) ?></div>
                            <?php endforeach; endif; ?>
                        </td>
                        
                        <td class="col-action col-redet">
                            <div class="d-flex justify-content-center gap-1 flex-wrap">
                                <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): ?>
                                    <button class="btn btn-sm btn-warning py-0 px-1 text-dark" onclick="abrirModalEdit(<?= $cr['id'] ?>, <?= $itemId ?>)"><i class="bi bi-pencil"></i></button>
                                <?php endforeach; endif; ?>
                                <button class="btn btn-sm btn-outline-warning text-dark py-0 px-1" title="Agregar Redet." 
                                        onclick="abrirModalNew('REDETERMINACION', '<?= $per ?>', 0, <?= $itemId ?>)">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCertificadoUpd" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="modalTitulo">Certificado</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form action="certificados_guardar_modal.php" method="POST" id="formCertificadoUpd">
                <input type="hidden" name="obra_id" value="<?= $cabecera['obra_real_id'] ?>">
                <input type="hidden" name="version_prev_id" value="<?= $versionId ?>">
                
                <input type="hidden" name="periodo" id="chk_modalPeriodo">
                <input type="hidden" name="tipo" id="chk_modalTipo">
                <input type="hidden" name="cert_id" id="chk_modalCertId" value="0">
                
                <input type="hidden" name="curva_item_id" id="chk_modalCurvaItemId">

                <input type="hidden" id="cuit_empresa" value="<?= $cabecera['empresa_cuit'] ?>">
                <input type="hidden" id="data_anticipo_pct_obra" value="<?= $cabecera['anticipo_pct'] ?>">
                
                <div class="modal-body">
                    <div class="row mb-2">
                        <div class="col-12 text-end">
                            <label class="small text-muted me-1">Link ID:</label>
                            <input type="text" id="debug_link_id" class="debug-id" disabled>
                        </div>
                    </div>

                    <div class="row align-items-center mb-3">
                        <div class="col-md-6"><div class="alert alert-secondary py-1 small mb-0 fw-bold">Obra: $ <?= fmtM($montoVisualizar) ?></div></div>
                        <div class="col-md-6"><div class="input-group input-group-sm"><span class="input-group-text bg-dark text-white fw-bold">N° Cert.</span><input type="number" name="nro_certificado" id="chk_inputNroCert" class="form-control fw-bold" placeholder="Auto"></div></div>
                    </div>

                    <div class="row mb-3 bg-light p-2 rounded mx-0 border">
                        <div class="col-12 border-bottom mb-2 pb-1 text-muted small text-uppercase fw-bold">1. Valores Principales</div>
                        
                        <div class="col-md-6 div-ordinario d-none">
                            <label class="form-label small fw-bold" id="lblInputAvance">% Avance</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="avance_fisico" id="chk_inputAvance" class="form-control fw-bold text-center" oninput="calcBasico()">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-primary" id="lblPlanificado"></small>
                        </div>
                        
                        <div class="col-md-3 div-redet d-none">
                            <label class="form-label small fw-bold">FRI</label>
                            <input type="number" step="0.0001" name="fri" id="chk_inputFri" class="form-control text-center" placeholder="1.0000">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Monto Bruto ($)</label>
                            <input type="text" name="monto_bruto" id="chk_inputMontoBruto" class="form-control fw-bold text-end monto" required oninput="calcDeducciones()">
                        </div>
                    </div>

                    <div class="row mb-3 mx-0">
                        <div class="col-12 text-muted small text-uppercase fw-bold border-bottom mb-2">2. Deducciones</div>
                        <div class="col-md-4 mb-2">
                            <label class="small">Fondo Reparo</label>
                            <div class="input-group input-group-sm mb-1">
                                <span class="input-group-text">5%</span>
                                <div class="input-group-text bg-white">
                                    <input class="form-check-input mt-0" type="checkbox" name="fondo_reparo_sustituido" id="chk_checkSustituido" value="1" onchange="calcDeducciones()">
                                    <label class="small ms-1 mb-0" for="chk_checkSustituido">Sust.</label>
                                </div>
                            </div>
                            <input type="text" name="fondo_reparo_monto" id="chk_inputFondoReparo" class="form-control form-control-sm text-end text-danger monto" readonly>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small">Dev. Anticipo</label>
                            <div class="input-group input-group-sm mb-1">
                                <span class="input-group-text text-muted"><?= $cabecera['anticipo_pct'] ?>%</span>
                                <input type="number" step="0.01" name="anticipo_pct_aplicado" id="chk_inputAnticipoPct" class="form-control form-control-sm" value="<?= $cabecera['anticipo_pct'] ?>" oninput="calcDeducciones()">
                            </div>
                            <input type="text" name="anticipo_descuento" id="chk_inputAnticipoMonto" class="form-control form-control-sm text-end text-danger monto" readonly>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small">Multas / Otros</label>
                            <input type="text" name="multas_monto" id="chk_inputMultas" class="form-control form-control-sm text-end text-danger monto" value="0,00" oninput="calcDeducciones()">
                        </div>
                    </div>
                    
                    <div class="alert alert-primary py-2 d-flex justify-content-between align-items-center mb-0">
                        <span class="fw-bold">NETO A PAGAR:</span>
                        <h4 class="mb-0 fw-bold" id="lblNeto">$ 0,00</h4>
                        <input type="hidden" name="monto_neto" id="chk_inputNeto">
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
document.addEventListener('DOMContentLoaded', function() {
    // Gráfico
    const ctx = document.getElementById('chartCurva');
    if(ctx) {
        new Chart(ctx.getContext('2d'), { 
            type: 'line', 
            data: { 
                labels: <?= json_encode($labels) ?>, 
                datasets: [ 
                    { label: 'Plan', data: <?= json_encode($dataPlan) ?>, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, tension: 0.1 }, 
                    { label: 'Real', data: <?= json_encode($dataReal) ?>, borderColor: '#198754', tension: 0.1, spanGaps: false } 
                ] 
            }, 
            options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true } } } 
        });
    }
    bindMasks();
});

const montoObraRef = <?= json_encode((float)$montoVisualizar) ?>;
const MODAL_ID = 'modalCertificadoUpd';
const FORM_ID = 'formCertificadoUpd';

// Funciones
function abrirModalNew(tipo, periodo, planPct, itemId) {
    resetModal(tipo, periodo, 0, itemId);
    document.getElementById('modalTitulo').innerText = "Nuevo: " + tipo + " (" + periodo + ")";
    document.getElementById('chk_inputNroCert').value = ""; 
    
    toggleCampos(tipo);

    if(tipo==='ORDINARIO' && planPct > 0) {
        document.getElementById('chk_inputAvance').value = planPct;
        document.getElementById('lblPlanificado').innerText = "(Plan: " + planPct + "%)";
        calcBasico();
    }
    
    let el = document.getElementById(MODAL_ID);
    if(el) new bootstrap.Modal(el).show();
}

function abrirModalEdit(id, itemId) {
    fetch('../certificados/api_get_certificado.php?id=' + id)
    .then(r => r.json())
    .then(data => {
        resetModal(data.tipo, data.periodo, id, itemId);
        
        document.getElementById('modalTitulo').innerText = "Editar: " + data.tipo + " #" + data.nro_certificado;
        document.getElementById('chk_inputNroCert').value = data.nro_certificado;
        document.getElementById('chk_inputMontoBruto').value = fmtM(data.monto_bruto);
        document.getElementById('chk_inputAvance').value = data.avance_fisico_mensual;
        if(data.tipo === 'REDETERMINACION') document.getElementById('chk_inputFri').value = data.fri;
        
        document.getElementById('chk_checkSustituido').checked = (data.fondo_reparo_sustituido == 1);
        document.getElementById('chk_inputAnticipoPct').value = data.anticipo_pct_aplicado;
        document.getElementById('chk_inputMultas').value = fmtM(data.multas_monto);
        
        toggleCampos(data.tipo);
        calcDeducciones();
        
        let el = document.getElementById(MODAL_ID);
        if(el) new bootstrap.Modal(el).show();
    })
    .catch(err => alert("Error al cargar: " + err));
}

function resetModal(tipo, periodo, id, itemId) {
    let f = document.getElementById(FORM_ID);
    if(f) f.reset();
    
    document.getElementById('chk_modalCertId').value = id;
    document.getElementById('chk_modalTipo').value = tipo;
    document.getElementById('chk_modalPeriodo').value = periodo;
    document.getElementById('chk_modalCurvaItemId').value = itemId; // IMPORTANTE
    document.getElementById('debug_link_id').value = itemId;

    document.getElementById('lblPlanificado').innerText = "";
    
    let pctObra = document.getElementById('data_anticipo_pct_obra');
    if(pctObra) document.getElementById('chk_inputAnticipoPct').value = pctObra.value;
}

function toggleCampos(tipo) {
    const divOrd = document.querySelectorAll('.div-ordinario');
    const divRed = document.querySelectorAll('.div-redet');
    divOrd.forEach(e => e.classList.add('d-none'));
    divRed.forEach(e => e.classList.add('d-none'));

    if(tipo==='ORDINARIO' || tipo==='ANTICIPO') {
        divOrd.forEach(e => e.classList.remove('d-none'));
        document.getElementById('lblInputAvance').innerText = (tipo==='ANTICIPO') ? "% Anticipo" : "% Avance Físico";
        if(tipo === 'ANTICIPO') document.getElementById('chk_inputAnticipoPct').value = 0;
    } 
    if(tipo==='REDETERMINACION') divRed.forEach(e => e.classList.remove('d-none'));
}

function calcBasico() {
    let pct = parseFloat(document.getElementById('chk_inputAvance').value) || 0;
    let monto = montoObraRef * (pct / 100);
    document.getElementById('chk_inputMontoBruto').value = fmtM(monto);
    calcDeducciones();
}

function calcDeducciones() {
    let bruto = parseM(document.getElementById('chk_inputMontoBruto').value);
    let tipo = document.getElementById('chk_modalTipo').value;
    
    if(document.activeElement.id === 'chk_inputMontoBruto' && montoObraRef > 0 && tipo === 'ORDINARIO') {
        let pct = (bruto / montoObraRef) * 100;
        document.getElementById('chk_inputAvance').value = pct.toFixed(2);
    }
    
    let fr = 0;
    if(!document.getElementById('chk_checkSustituido').checked) fr = bruto * 0.05;
    document.getElementById('chk_inputFondoReparo').value = fmtM(fr);
    
    let ant = 0;
    if(tipo !== 'ANTICIPO') {
        let pctAnt = parseFloat(document.getElementById('chk_inputAnticipoPct').value) || 0;
        ant = bruto * (pctAnt / 100);
    }
    document.getElementById('chk_inputAnticipoMonto').value = fmtM(ant);
    
    let mul = parseM(document.getElementById('chk_inputMultas').value);
    document.getElementById('lblNeto').innerText = "$ " + fmtM(bruto - fr - ant - mul);
    document.getElementById('chk_inputNeto').value = (bruto - fr - ant - mul).toFixed(2);
}

function parseM(v) { if(!v) return 0; let clean = v.toString().replace(/\./g,'').replace(',','.'); return parseFloat(clean) || 0; }
function fmtM(v) { return parseFloat(v).toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

function bindMasks() { 
    document.querySelectorAll('.monto').forEach(el => { 
        el.addEventListener('blur', function() { this.value = fmtM(parseM(this.value)); });
        el.addEventListener('focus', function() { let val = parseM(this.value); if(val===0) this.value=''; else this.value=val; }); 
    }); 
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
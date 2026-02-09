<?php
// modulos/curva/curva_ver.php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("Versión no especificada.");

// 1. Obtener Datos de la Obra y Versión
$sqlHead = "SELECT v.*, o.id as obra_real_id, o.denominacion, o.monto_actualizado, o.monto_original, o.anticipo_pct, e.cuit as empresa_cuit 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id 
            LEFT JOIN empresas e ON o.empresa_id = e.id
            WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Obtener Ítems de la Curva (Planificación)
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$itemsCurva = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. Obtener Certificados Existentes (Realidad)
$sqlCerts = "SELECT * FROM certificados WHERE obra_id = ? AND estado != 'ANULADO' ORDER BY id ASC";
$stmtC = $pdo->prepare($sqlCerts);
$stmtC->execute([$cabecera['obra_real_id']]);
$certificadosDB = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Mapeamos certificados por [periodo][tipo]
$mapaCertificados = [];
foreach ($certificadosDB as $c) {
    $periodo = substr($c['periodo'], 0, 7); // YYYY-MM
    $mapaCertificados[$periodo][$c['tipo']][] = $c;
}

// Helpers de formato
function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtPct($v) { return number_format((float)$v, 2, ',', '.') . '%'; }
function fmtFri($v) { return number_format((float)$v, 4, ',', '.'); }

// --- LÓGICA DEL GRÁFICO (Planificado vs Real) ---
$labels = []; 
$dataPlan = []; 
$dataReal = []; 
$acumPlan = 0; 
$acumReal = 0;

foreach ($itemsCurva as $i) {
    // Ignoramos anticipos para la curva física
    if (stripos($i['concepto'], 'anticipo') === false) {
        $per = date('Y-m', strtotime($i['periodo']));
        $labels[] = date('m/y', strtotime($i['periodo']));
        
        // Acumulado Planificado
        $acumPlan += $i['porcentaje_fisico'];
        $dataPlan[] = $acumPlan;
        
        // Acumulado Real (Buscamos si hubo certificación básica ese mes)
        $avanceMes = 0;
        $huboCertificado = false;
        
        if(isset($mapaCertificados[$per]['ORDINARIO'])) {
            foreach($mapaCertificados[$per]['ORDINARIO'] as $cert) {
                $avanceMes += $cert['avance_fisico_mensual'];
                $huboCertificado = true;
            }
        }
        
        if ($huboCertificado) {
            $acumReal += $avanceMes;
            $dataReal[] = $acumReal;
        } else {
            // Si no hay certificado cargado, cortamos la línea del gráfico
            $dataReal[] = null; 
        }
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
        .text-fri { font-family: monospace; font-size: 0.85rem; color: #d63384; }
        .input-fufi { border: 1px solid #ced4da; padding: 2px 5px; font-size: 0.9rem; text-align: right; width: 100%; border-radius: 4px; }
        .item-factura { transition: background 0.2s; }
        .item-factura:hover { background-color: #f8f9fa; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="text-primary fw-bold mb-0"><i class="bi bi-kanban"></i> Gestión de Certificados</h4>
            <div class="text-muted small">
                Obra: <strong><?= htmlspecialchars($cabecera['denominacion']) ?></strong> | 
                Monto Vigente: <span class="badge bg-primary text-white" style="font-size: 0.9em;">$ <?= fmtM($cabecera['monto_actualizado']) ?></span> |
                Anticipo: <strong><?= $cabecera['anticipo_pct'] ?>%</strong>
            </div>
        </div>
        <div>
            <a href="curva_export.php?version_id=<?= $versionId ?>" class="btn btn-success btn-sm shadow-sm me-1">
                <i class="bi bi-file-excel"></i> Excel
            </a>
            
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGraph">
                <i class="bi bi-graph-up"></i> Gráfico
            </button>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="collapse show mb-4" id="collapseGraph">
        <div class="card shadow-sm border-0">
            <div class="card-body" style="height: 300px;">
                <canvas id="chartCurva"></canvas>
            </div>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 table-vcenter text-center">
                <thead class="table-dark text-uppercase small">
                    <tr>
                        <th rowspan="2">Periodo</th>
                        <th colspan="2" class="bg-secondary">Planificación</th>
                        <th colspan="3" class="bg-success">Certificado Básico</th>
                        <th colspan="3" class="bg-warning text-dark">Redeterminaciones</th>
                    </tr>
                    <tr>
                        <th class="bg-secondary text-white small">% Fis.</th>
                        <th class="bg-secondary text-white small">Monto ($)</th>
                        
                        <th class="bg-success text-white small">% Real</th>
                        <th class="bg-success text-white small">Monto</th>
                        <th class="bg-success text-white small">Acción</th>

                        <th class="bg-warning text-dark small">FRI</th>
                        <th class="bg-warning text-dark small">Monto</th>
                        <th class="bg-warning text-dark small">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($itemsCurva as $item): 
                        $per = date('Y-m', strtotime($item['periodo']));
                        $esAnticipo = (stripos($item['concepto'], 'anticipo') !== false);
                        
                        $certsBasicos = $mapaCertificados[$per]['ORDINARIO'] ?? [];
                        $certsRedet = $mapaCertificados[$per]['REDETERMINACION'] ?? [];
                        $certsAnticipo = $mapaCertificados[$per]['ANTICIPO'] ?? [];
                        
                        // Datos para sugerir al cargar nuevo
                        $planPct = $item['porcentaje_fisico'];
                    ?>
                    <tr>
                        <td class="fw-bold text-secondary"><?= date('m/Y', strtotime($per)) ?></td>

                        <td class="col-plan fw-bold text-primary"><?= fmtPct($item['porcentaje_fisico']) ?></td>
                        <td class="col-plan text-muted small border-end">$ <?= fmtM($item['neto']) ?></td>

                        <td class="col-basico">
                            <?php if($esAnticipo): ?>
                                <span class="badge bg-secondary">Anticipo</span>
                            <?php else: ?>
                                <?php if(!empty($certsBasicos)): ?>
                                    <?php foreach($certsBasicos as $cb): ?>
                                        <div class="fw-bold text-success"><?= fmtPct($cb['avance_fisico_mensual']) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?> - <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-basico">
                             <?php if(!empty($certsBasicos)): ?>
                                <?php foreach($certsBasicos as $cb): ?>
                                    <div class="small fw-bold">$ <?= fmtM($cb['monto_basico']) ?></div>
                                <?php endforeach; ?>
                            <?php elseif(!empty($certsAnticipo)): ?>
                                <div class="small fw-bold">$ <?= fmtM($certsAnticipo[0]['monto_basico']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="col-basico border-end">
                            <?php if($esAnticipo): ?>
                                <?php if(empty($certsAnticipo)): ?>
                                    <button class="btn btn-sm btn-outline-primary py-0" 
                                        onclick="abrirModalNew('ANTICIPO', '<?= $per ?>', 0)">
                                        <i class="bi bi-plus"></i> Cargar
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-link py-0" 
                                        onclick="abrirModalEdit(<?= $certsAnticipo[0]['id'] ?>)">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if(empty($certsBasicos)): ?>
                                    <button class="btn btn-sm btn-success py-0 shadow-sm" 
                                        onclick="abrirModalNew('ORDINARIO', '<?= $per ?>', <?= $planPct ?>)">
                                        <i class="bi bi-plus-lg"></i> Certificar
                                    </button>
                                <?php else: ?>
                                    <?php foreach($certsBasicos as $cb): ?>
                                        <button class="btn btn-sm btn-outline-success py-0" 
                                            onclick="abrirModalEdit(<?= $cb['id'] ?>)">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="col-redet">
                            <?php if(!empty($certsRedet)): ?>
                                <?php foreach($certsRedet as $cr): ?>
                                    <div class="text-fri">FRI: <?= fmtFri($cr['fri']) ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-redet">
                            <?php if(!empty($certsRedet)): ?>
                                <?php foreach($certsRedet as $cr): ?>
                                    <div class="small fw-bold text-dark">$ <?= fmtM($cr['monto_redeterminado']) ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-redet">
                            <div class="d-flex justify-content-center gap-1">
                                <?php if(!empty($certsRedet)): ?>
                                    <?php foreach($certsRedet as $cr): ?>
                                        <button class="btn btn-sm btn-link py-0 text-dark" title="Editar Redet." 
                                            onclick="abrirModalEdit(<?= $cr['id'] ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-dark py-0" 
                                    onclick="abrirModalNew('REDETERMINACION', '<?= $per ?>', 0)" 
                                    title="Agregar otra Redeterminación">
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
                <input type="hidden" id="data_monto_contrato" value="<?= $cabecera['monto_actualizado'] ?>">

                <div class="modal-body">
                    
                    <div class="alert alert-secondary py-1 small text-center mb-3 fw-bold">
                        <i class="bi bi-info-circle"></i> Monto Obra Vigente: <span class="text-primary fs-6">$ <?= fmtM($cabecera['monto_actualizado']) ?></span>
                    </div>

                    <div class="row mb-3 bg-light p-2 rounded mx-0 border">
                        <div class="col-12 border-bottom mb-2 pb-1 text-muted small text-uppercase fw-bold">1. Valores Principales</div>
                        
                        <div class="col-md-6 div-ordinario d-none">
                            <label class="form-label small fw-bold">% Avance Físico</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="avance_fisico" id="inputAvance" class="form-control fw-bold text-center" oninput="calcBasico()">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-primary" id="lblPlanificado"></small>
                        </div>
                        
                        <div class="col-md-3 div-redet d-none">
                            <label class="form-label small fw-bold">FRI (Factor)</label>
                            <input type="number" step="0.0001" name="fri" id="inputFri" class="form-control text-center" placeholder="1.0000">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Monto Bruto ($)</label>
                            <input type="text" name="monto_bruto" id="inputMontoBruto" class="form-control fw-bold text-end monto" required oninput="calcDeducciones()">
                        </div>
                    </div>

                    <div class="row mb-3 mx-0">
                        <div class="col-12 text-muted small text-uppercase fw-bold border-bottom mb-2">2. Deducciones</div>
                        
                        <div class="col-md-4 mb-2">
                            <label class="small">Fondo Reparo</label>
                            <div class="input-group input-group-sm mb-1">
                                <span class="input-group-text">5%</span>
                                <div class="input-group-text bg-white">
                                    <input class="form-check-input mt-0" type="checkbox" name="fondo_reparo_sustituido" id="checkSustituido" value="1" onchange="calcDeducciones()">
                                    <label class="small ms-1 mb-0" for="checkSustituido">Sustituido</label>
                                </div>
                            </div>
                            <input type="text" name="fondo_reparo_monto" id="inputFondoReparo" class="form-control form-control-sm text-end text-danger monto" readonly>
                        </div>

                        <div class="col-md-4 mb-2">
                            <label class="small">Dev. Anticipo</label>
                            <div class="input-group input-group-sm mb-1">
                                <span class="input-group-text text-muted"><?= $cabecera['anticipo_pct'] ?>%</span>
                                <input type="number" step="0.01" name="anticipo_pct_aplicado" id="inputAnticipoPct" class="form-control form-control-sm" value="<?= $cabecera['anticipo_pct'] ?>" oninput="calcDeducciones()">
                            </div>
                            <input type="text" name="anticipo_descuento" id="inputAnticipoMonto" class="form-control form-control-sm text-end text-danger monto" readonly>
                        </div>

                        <div class="col-md-4 mb-2">
                            <label class="small">Multas / Otros</label>
                            <input type="text" name="multas_monto" id="inputMultas" class="form-control form-control-sm text-end text-danger monto" value="0,00" oninput="calcDeducciones()">
                        </div>
                    </div>

                    <div class="row mb-3 mx-0">
                        <div class="col-12 border-bottom mb-2 pb-1 d-flex justify-content-between align-items-center">
                            <span class="text-muted small text-uppercase fw-bold">3. Fuentes de Financiamiento</span>
                            <button type="button" class="btn btn-link btn-sm py-0" onclick="recalcularFuentes()" style="font-size:0.8rem">Autocalcular</button>
                        </div>
                        <div class="col-12" id="containerFufi"></div>
                    </div>

                    <div class="row mb-3 mx-0 border-top pt-2">
                        <div class="col-12 d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small text-uppercase fw-bold">4. Facturas Vinculadas</span>
                            <button type="button" class="btn btn-sm btn-outline-primary py-0" onclick="abrirModalArca()">
                                <i class="bi bi-search"></i> Buscar Factura
                            </button>
                        </div>
                        <div class="col-12" id="containerFacturas">
                            <div class="text-center text-muted small py-2 fst-italic" id="msgNoFacturas">Sin facturas vinculadas</div>
                        </div>
                        <div class="col-12 text-end small border-top mt-1 pt-1">
                            <span class="me-2">Total Facturado: <strong id="lblTotalFact">$ 0,00</strong></span>
                            <span class="badge bg-warning text-dark d-none" id="lblAlertaFact">Difiere del Neto</span>
                        </div>
                    </div>

                    <div class="alert alert-primary py-2 d-flex justify-content-between align-items-center mb-0">
                        <span class="fw-bold">NETO A PAGAR:</span>
                        <h4 class="mb-0 fw-bold" id="lblNeto">$ 0,00</h4>
                        <input type="hidden" name="monto_neto" id="inputNeto">
                    </div>
                </div>
                <div class="modal-footer bg-light p-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalArca" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h6 class="modal-title">Facturas Disponibles (AFIP)</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2" id="bodyArca"></div>
        </div>
    </div>
</div>

<script>
// --- DATOS GLOBALES ---
const montoObra = <?= $cabecera['monto_actualizado'] ?>;
const pctAnticipoObra = <?= $cabecera['anticipo_pct'] ?>;
let fuentesConfig = []; 

// --- GRÁFICO (Chart.js) ---
const ctx = document.getElementById('chartCurva').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            { label: 'Planificado', data: <?= json_encode($dataPlan) ?>, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, tension: 0.3 },
            { label: 'Real Acumulado', data: <?= json_encode($dataReal) ?>, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', borderWidth: 3, tension: 0.1, spanGaps: false }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});

// --- FUNCIONES DEL MODAL ---

// 1. Abrir Nuevo
function abrirModalNew(tipo, periodo, planPct) {
    resetModal(tipo, periodo, 0);
    document.getElementById('modalTitulo').innerText = "Nuevo: " + tipo + " (" + periodo + ")";
    
    if(tipo === 'ORDINARIO') {
        document.getElementById('inputAvance').value = planPct;
        document.getElementById('lblPlanificado').innerText = "(Plan: " + planPct + "%)";
        calcBasico(); 
    }
    
    // Cargar FUFI base de la obra
    loadFuentesBase(true);
    new bootstrap.Modal(document.getElementById('modalCert')).show();
}

// 2. Abrir Edición (Fetch Data)
function abrirModalEdit(id) {
    fetch('../certificados/api_get_certificado.php?id=' + id)
    .then(r => r.json())
    .then(data => {
        if(data.error) { alert(data.error); return; }
        
        resetModal(data.tipo, data.periodo, id);
        document.getElementById('modalTitulo').innerText = "Editar: " + data.tipo;
        
        // Llenar campos específicos
        if(data.tipo === 'ORDINARIO') document.getElementById('inputAvance').value = data.avance_fisico_mensual;
        if(data.tipo === 'REDETERMINACION') document.getElementById('inputFri').value = data.fri;
        
        // Llenar montos y deducciones
        document.getElementById('inputMontoBruto').value = fmtM(data.monto_bruto);
        document.getElementById('checkSustituido').checked = (data.fondo_reparo_sustituido == 1);
        document.getElementById('inputAnticipoPct').value = data.anticipo_pct_aplicado;
        document.getElementById('inputMultas').value = fmtM(data.multas_monto);
        
        // Recalcular visuales
        calcDeducciones();
        
        // Renderizar Fuentes guardadas
        if(data.fuentes) renderFuentes(data.fuentes);
        
        // Renderizar Facturas guardadas
        if(data.facturas) {
            data.facturas.forEach(f => agregarFacturaUI(f.comprobante_arca_id, f.numero, f.importe_total));
        }
        
        new bootstrap.Modal(document.getElementById('modalCert')).show();
    });
}

function resetModal(tipo, periodo, id) {
    document.getElementById('formModal').reset();
    document.getElementById('modalCertId').value = id;
    document.getElementById('modalTipo').value = tipo;
    document.getElementById('modalPeriodo').value = periodo;
    
    // Limpiar contenedores dinámicos
    document.getElementById('containerFufi').innerHTML = '';
    document.getElementById('containerFacturas').innerHTML = '<div class="text-center text-muted small py-2 fst-italic" id="msgNoFacturas">Sin facturas vinculadas</div>';
    document.getElementById('lblTotalFact').innerText = '$ 0,00';
    document.getElementById('lblAlertaFact').classList.add('d-none');
    
    // Visibilidad
    document.querySelectorAll('.div-ordinario, .div-redet').forEach(e => e.classList.add('d-none'));
    if(tipo === 'ORDINARIO') document.querySelectorAll('.div-ordinario').forEach(e => e.classList.remove('d-none'));
    if(tipo === 'REDETERMINACION') document.querySelectorAll('.div-redet').forEach(e => e.classList.remove('d-none'));
    
    // Resetear deducciones default
    document.getElementById('inputAnticipoPct').value = pctAnticipoObra;
}

// --- LOGICA DE FACTURAS ---
function abrirModalArca() {
    let cuit = document.getElementById('cuit_empresa').value;
    if(!cuit) { alert("Empresa sin CUIT."); return; }
    
    document.getElementById('bodyArca').innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div> Cargando...</div>';
    new bootstrap.Modal(document.getElementById('modalArca')).show();
    
    fetch('../../modulos/certificados/api_get_facturas.php?cuit=' + cuit)
    .then(r => r.text())
    .then(html => { document.getElementById('bodyArca').innerHTML = html; });
}

function seleccionarFactura(id, num, monto) {
    if(document.querySelector(`input[name="facturas_arca[]"][value="${id}"]`)) return;
    agregarFacturaUI(id, num, monto);
    bootstrap.Modal.getInstance(document.getElementById('modalArca')).hide();
}

function agregarFacturaUI(id, num, monto) {
    document.getElementById('msgNoFacturas').classList.add('d-none');
    let div = document.createElement('div');
    div.className = 'd-flex justify-content-between align-items-center border rounded p-1 mb-1 bg-white small item-factura';
    div.innerHTML = `
        <span><i class="bi bi-receipt"></i> ${num}</span>
        <span class="fw-bold text-success">$ ${fmtM(monto)}</span>
        <button type="button" class="btn btn-link text-danger p-0 ms-2" onclick="removerFactura(this)"><i class="bi bi-x-circle"></i></button>
        <input type="hidden" name="facturas_arca[]" value="${id}">
        <input type="hidden" class="val-factura" value="${monto}">
    `;
    document.getElementById('containerFacturas').appendChild(div);
    validarFacturas();
}

function removerFactura(btn) {
    btn.closest('.item-factura').remove();
    if(document.querySelectorAll('.item-factura').length === 0) document.getElementById('msgNoFacturas').classList.remove('d-none');
    validarFacturas();
}

function validarFacturas() {
    let total = 0;
    document.querySelectorAll('.val-factura').forEach(i => total += parseFloat(i.value));
    document.getElementById('lblTotalFact').innerText = '$ ' + fmtM(total);
    
    let neto = parseM(document.getElementById('inputNeto').value);
    let diff = Math.abs(neto - total);
    let alerta = document.getElementById('lblAlertaFact');
    
    (diff > 100 && total > 0) ? alerta.classList.remove('d-none') : alerta.classList.add('d-none');
}

// --- LOGICA DE CÁLCULOS (Montos, Deducciones, Fuentes) ---
function calcBasico() {
    let pct = parseFloat(document.getElementById('inputAvance').value) || 0;
    let monto = montoObra * (pct / 100);
    document.getElementById('inputMontoBruto').value = fmtM(monto);
    calcDeducciones();
}

function calcDeducciones() {
    let bruto = parseM(document.getElementById('inputMontoBruto').value);
    
    // 1. Fondo Reparo
    let fr = document.getElementById('checkSustituido').checked ? 0 : (bruto * 0.05);
    document.getElementById('inputFondoReparo').value = fmtM(fr);

    // 2. Dev. Anticipo
    let pctAnt = parseFloat(document.getElementById('inputAnticipoPct').value) || 0;
    let ant = bruto * (pctAnt / 100);
    document.getElementById('inputAnticipoMonto').value = fmtM(ant);

    // 3. Multas
    let mul = parseM(document.getElementById('inputMultas').value);

    let neto = bruto - fr - ant - mul;
    document.getElementById('lblNeto').innerText = "$ " + fmtM(neto);
    document.getElementById('inputNeto').value = neto.toFixed(2);
    
    validarFacturas();
}

function loadFuentesBase(recalc) {
    fetch('../../modulos/certificados/api_get_fuentes.php?obra_id=<?= $cabecera['obra_real_id'] ?>')
    .then(r => r.json())
    .then(data => {
        let render = data.map(f => ({ fuente_id: f.fuente_id, nombre: f.nombre, porcentaje: f.porcentaje, monto_asignado: 0 }));
        renderFuentes(render);
        if(recalc) recalcularFuentes();
    });
}

function renderFuentes(lista) {
    const div = document.getElementById('containerFufi');
    div.innerHTML = '';
    lista.forEach(f => {
        div.innerHTML += `
            <div class="row mb-1 g-1 align-items-center small">
                <div class="col-7">${f.nombre} (${f.porcentaje}%)
                    <input type="hidden" name="fuente_id[]" value="${f.fuente_id}">
                    <input type="hidden" name="fuente_pct[]" value="${f.porcentaje}">
                </div>
                <div class="col-5">
                    <input type="text" name="fuente_monto[]" class="input-fufi monto" value="${fmtM(f.monto_asignado)}">
                </div>
            </div>`;
    });
    bindMasks();
}

function recalcularFuentes() {
    let neto = parseM(document.getElementById('inputNeto').value);
    document.querySelectorAll('#containerFufi .row').forEach(row => {
        let pct = parseFloat(row.querySelector('input[name="fuente_pct[]"]').value) || 0;
        row.querySelector('input[name="fuente_monto[]"]').value = fmtM(neto * (pct/100));
    });
}

// UTILS
function parseM(v) { return parseFloat((v||'0').toString().replace(/\./g,'').replace(',','.')) || 0; }
function fmtM(v) { return v.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
function bindMasks() {
    document.querySelectorAll('.monto').forEach(el => {
        el.addEventListener('blur', function() { this.value = fmtM(parseM(this.value)); });
        el.addEventListener('focus', function() { this.value = parseM(this.value); });
    });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// modulos/curva/curva_ver.php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("Versión no especificada.");

// 1. Obtener Datos de la Obra y Versión
// Traemos 'anticipo_pct' de la obra para calcular la devolución automática
$sqlHead = "SELECT v.*, o.id as obra_real_id, o.denominacion, o.monto_actualizado, o.monto_original, o.anticipo_pct 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Obtener Ítems de la Curva (Lo Planificado)
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$itemsCurva = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. Obtener Certificados Existentes (Lo Real)
// Indexamos por [periodo][tipo] para ubicarlos fácil en la tabla
$sqlCerts = "SELECT * FROM certificados WHERE obra_id = ? AND estado != 'ANULADO'";
$stmtC = $pdo->prepare($sqlCerts);
$stmtC->execute([$cabecera['obra_real_id']]);
$certificadosDB = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$mapaCertificados = [];
foreach ($certificadosDB as $c) {
    $periodo = substr($c['periodo'], 0, 7); // YYYY-MM
    $mapaCertificados[$periodo][$c['tipo']][] = $c;
}

// Helpers de formato
function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtPct($v) { return number_format((float)$v, 2, ',', '.') . '%'; }
function fmtFri($v) { return number_format((float)$v, 4, ',', '.'); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tablero de Certificación - <?= htmlspecialchars($cabecera['denominacion']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        .table-vcenter td { vertical-align: middle; font-size: 0.9rem; }
        .col-plan { background-color: #f8f9fa; border-right: 2px solid #dee2e6; }
        .col-basico { background-color: #f0fdf4; } /* Verde claro */
        .col-redet { background-color: #fff8e1; } /* Amarillo claro */
        .text-fri { font-family: monospace; font-size: 0.85rem; color: #d63384; }
        .input-mini { width: 80px; text-align: right; padding: 2px 5px; font-size: 0.9rem; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="text-primary fw-bold mb-0"><i class="bi bi-kanban"></i> Gestión de Certificados</h4>
            <div class="text-muted small">
                Obra: <?= htmlspecialchars($cabecera['denominacion']) ?> | 
                Anticipo Obra: <strong><?= $cabecera['anticipo_pct'] ?>%</strong>
            </div>
        </div>
        <div>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 table-vcenter text-center">
                <thead class="table-dark text-uppercase small">
                    <tr>
                        <th rowspan="2" style="width: 100px;">Periodo</th>
                        <th colspan="2" class="bg-secondary border-end">Planificación</th>
                        <th colspan="3" class="bg-success border-end">Certificado Básico</th>
                        <th colspan="3" class="bg-warning text-dark">Redeterminaciones</th>
                    </tr>
                    <tr>
                        <th class="bg-secondary text-white small">% Fis.</th>
                        <th class="bg-secondary text-white small border-end">Monto ($)</th>
                        
                        <th class="bg-success text-white small">% Real</th>
                        <th class="bg-success text-white small">Monto Bruto</th>
                        <th class="bg-success text-white small border-end">Acción</th>

                        <th class="bg-warning text-dark small">FRI</th>
                        <th class="bg-warning text-dark small">Monto Redet.</th>
                        <th class="bg-warning text-dark small">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($itemsCurva as $item): 
                        $per = date('Y-m', strtotime($item['periodo']));
                        $esAnticipo = (stripos($item['concepto'], 'anticipo') !== false);
                        
                        // Certificados cargados
                        $certsBasicos = $mapaCertificados[$per]['ORDINARIO'] ?? [];
                        $certsRedet   = $mapaCertificados[$per]['REDETERMINACION'] ?? [];
                        $certsAnticipo= $mapaCertificados[$per]['ANTICIPO'] ?? [];
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
                                        onclick="abrirModal('ANTICIPO', '<?= $per ?>', 0, 0)">
                                        <i class="bi bi-plus"></i> Cargar
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-link py-0" onclick="editarCert(<?= $certsAnticipo[0]['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if(empty($certsBasicos)): ?>
                                    <button class="btn btn-sm btn-success py-0 shadow-sm" 
                                        onclick="abrirModal('ORDINARIO', '<?= $per ?>', <?= $item['porcentaje_fisico'] ?>, <?= $item['neto'] ?>)">
                                        <i class="bi bi-plus-lg"></i> Certificar
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-success py-0" onclick="editarCert(<?= $certsBasicos[0]['id'] ?>)">
                                        <i class="bi bi-pencil-square"></i> Editar
                                    </button>
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
                            <?php if(empty($certsRedet)): ?>
                                <button class="btn btn-sm btn-outline-dark py-0" 
                                    onclick="abrirModal('REDETERMINACION', '<?= $per ?>', 0, 0)">
                                    <i class="bi bi-plus"></i> Redet.
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-link py-0 text-dark" onclick="editarCert(<?= $certsRedet[0]['id'] ?>)"><i class="bi bi-pencil"></i></button>
                            <?php endif; ?>
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
                <h5 class="modal-title fw-bold" id="modalTitulo">Nuevo Certificado</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form action="../certificados/certificados_guardar_modal.php" method="POST" id="formModal">
                <input type="hidden" name="obra_id" value="<?= $cabecera['obra_real_id'] ?>">
                <input type="hidden" name="periodo" id="modalPeriodo">
                <input type="hidden" name="tipo" id="modalTipo">
                <input type="hidden" name="cert_id" id="modalCertId" value="0"> <input type="hidden" id="data_anticipo_pct_obra" value="<?= $cabecera['anticipo_pct'] ?>">
                <input type="hidden" id="data_monto_contrato" value="<?= $cabecera['monto_actualizado'] ?>">

                <div class="modal-body">
                    
                    <div class="row mb-3 bg-light p-2 rounded mx-0 border">
                        <div class="col-12 border-bottom mb-2 pb-1 text-muted small text-uppercase fw-bold">1. Valores Principales</div>
                        
                        <div class="col-md-6 div-ordinario d-none">
                            <label class="form-label small fw-bold">% Avance Físico del Mes</label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="avance_fisico" id="inputAvance" class="form-control fw-bold text-center" oninput="calcBasico()">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-primary" id="lblPlanificado"></small>
                        </div>
                        
                        <div class="col-md-3 div-redet d-none">
                            <label class="form-label small fw-bold">FRI Aplicado</label>
                            <input type="number" step="0.0001" name="fri" id="inputFri" class="form-control text-center" placeholder="1.0000">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Monto Bruto ($)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" name="monto_bruto" id="inputMontoBruto" class="form-control fw-bold text-end monto" required oninput="calcDeducciones()">
                            </div>
                            <div class="form-text small div-redet d-none">Puede ingresar el monto total manual o calcular por FRI.</div>
                        </div>
                    </div>

                    <div class="row mb-3 mx-0">
                        <div class="col-12 text-muted small text-uppercase fw-bold border-bottom mb-2">2. Deducciones</div>
                        
                        <div class="col-md-4 mb-2">
                            <label class="form-label small">Fondo Reparo</label>
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
                            <label class="form-label small">Dev. Anticipo</label>
                            <div class="input-group input-group-sm mb-1">
                                <span class="input-group-text text-muted" title="% Anticipo de Obra"><?= $cabecera['anticipo_pct'] ?>%</span>
                                <input type="number" step="0.01" name="anticipo_pct_aplicado" id="inputAnticipoPct" class="form-control form-control-sm" value="<?= $cabecera['anticipo_pct'] ?>" oninput="calcDeducciones()">
                            </div>
                            <input type="text" name="anticipo_descuento" id="inputAnticipoMonto" class="form-control form-control-sm text-end text-danger monto" readonly>
                        </div>

                        <div class="col-md-4 mb-2">
                            <label class="form-label small">Multas / Otros</label>
                            <input type="text" name="multas_monto" id="inputMultas" class="form-control form-control-sm text-end text-danger monto" value="0,00" oninput="calcDeducciones()">
                        </div>
                    </div>

                    <div class="alert alert-primary py-2 d-flex justify-content-between align-items-center mb-0">
                        <span class="fw-bold">NETO A PAGAR:</span>
                        <h4 class="mb-0 fw-bold" id="lblNeto">$ 0,00</h4>
                        <input type="hidden" name="monto_neto" id="inputNeto">
                    </div>

                </div>
                <div class="modal-footer bg-light p-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold"><i class="bi bi-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// --- LOGICA JS ---
const montoContrato = parseFloat(document.getElementById('data_monto_contrato').value) || 0;
const pctAnticipoObra = parseFloat(document.getElementById('data_anticipo_pct_obra').value) || 0;

function abrirModal(tipo, periodo, planPct, planMonto) {
    // Reset form
    document.getElementById('formModal').reset();
    document.getElementById('modalCertId').value = "0"; // Nuevo
    document.getElementById('modalTipo').value = tipo;
    document.getElementById('modalPeriodo').value = periodo;
    
    // Títulos y visibilidad
    const titulo = document.getElementById('modalTitulo');
    const divOrd = document.querySelectorAll('.div-ordinario');
    const divRedet = document.querySelectorAll('.div-redet');
    
    divOrd.forEach(e => e.classList.add('d-none'));
    divRedet.forEach(e => e.classList.add('d-none'));

    if(tipo === 'ORDINARIO') {
        titulo.innerText = "Certificado Básico " + periodo;
        divOrd.forEach(e => e.classList.remove('d-none'));
        
        // Sugerir Planificado
        document.getElementById('inputAvance').value = planPct;
        document.getElementById('lblPlanificado').innerText = "(Plan: " + planPct + "%)";
        calcBasico();
    } 
    else if (tipo === 'REDETERMINACION') {
        titulo.innerText = "Redeterminación " + periodo;
        divRedet.forEach(e => e.classList.remove('d-none'));
        document.getElementById('inputMontoBruto').value = '';
    }
    else if (tipo === 'ANTICIPO') {
        titulo.innerText = "Certificado de Anticipo";
    }

    // Resetear deducciones default
    document.getElementById('inputAnticipoPct').value = pctAnticipoObra;
    document.getElementById('checkSustituido').checked = false;
    
    calcDeducciones();
    new bootstrap.Modal(document.getElementById('modalCert')).show();
}

function calcBasico() {
    let pct = parseFloat(document.getElementById('inputAvance').value) || 0;
    let monto = montoContrato * (pct / 100);
    document.getElementById('inputMontoBruto').value = monto.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    calcDeducciones();
}

function calcDeducciones() {
    let bruto = parseM(document.getElementById('inputMontoBruto').value);
    
    // 1. Fondo Reparo (5%)
    let fr = 0;
    if(!document.getElementById('checkSustituido').checked) {
        fr = bruto * 0.05;
    }
    document.getElementById('inputFondoReparo').value = fmtM(fr);

    // 2. Dev Anticipo
    let pctAnt = parseFloat(document.getElementById('inputAnticipoPct').value) || 0;
    let ant = bruto * (pctAnt / 100);
    document.getElementById('inputAnticipoMonto').value = fmtM(ant);

    // 3. Multas
    let mul = parseM(document.getElementById('inputMultas').value);

    // Neto
    let neto = bruto - fr - ant - mul;
    if(neto < 0) neto = 0;

    document.getElementById('lblNeto').innerText = "$ " + fmtM(neto);
    document.getElementById('inputNeto').value = neto.toFixed(2);
}

// Helpers Formato
function parseM(v) { return parseFloat((v||'0').toString().replace(/\./g,'').replace(',','.')) || 0; }
function fmtM(v) { return v.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

// Input Mask
document.querySelectorAll('.monto').forEach(el => {
    el.addEventListener('blur', function() { this.value = fmtM(parseM(this.value)); });
    el.addEventListener('focus', function() { this.value = parseM(this.value); });
});

// Función Editar (Redirecciona al form completo para ediciones complejas, o podríamos llenar el modal)
function editarCert(id) {
    window.location.href = '../certificados/certificados_form.php?id=' + id;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
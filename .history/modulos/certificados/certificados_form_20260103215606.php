<?php
// modulos/certificados/certificados_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;
// Si venimos de la curva u obra, capturamos estos datos
$obra_preseleccionada = $_GET['obra_id'] ?? 0;
$periodo_preseleccionado = $_GET['periodo'] ?? ''; 

// --- GUARDADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $f = function($v){ return (float)str_replace(',','.', str_replace('.','',$v)); };
        
        $montoBasico = $f($_POST['monto_basico']);
        // El monto redeterminado viene del input (que puede ser readonly o editado si se permite)
        $montoRedet = $f($_POST['monto_redeterminado']);
        $montoBruto = $montoBasico + $montoRedet;
        $fri = $f($_POST['fri']);

        // 1. Cabecera
        $sql = "INSERT INTO certificados (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion, 
                monto_basico, fri, monto_redeterminado, monto_bruto,
                fondo_reparo_pct, fondo_reparo_monto, 
                anticipo_pct_aplicado, anticipo_descuento, multas_monto, 
                monto_neto_pagar, avance_fisico_mensual, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'BORRADOR')
                ON DUPLICATE KEY UPDATE 
                obra_id=?, empresa_id=?, nro_certificado=?, tipo=?, periodo=?, fecha_medicion=?, 
                monto_basico=?, fri=?, monto_redeterminado=?, monto_bruto=?,
                fondo_reparo_pct=?, fondo_reparo_monto=?, 
                anticipo_pct_aplicado=?, anticipo_descuento=?, multas_monto=?, 
                monto_neto_pagar=?, avance_fisico_mensual=?";
        
        $params = [
            $_POST['obra_id'], $_POST['empresa_id'], $_POST['nro_certificado'], $_POST['tipo'], $_POST['periodo'], $_POST['fecha_medicion'],
            $montoBasico, $fri, $montoRedet, $montoBruto,
            $_POST['fondo_reparo_pct'], $f($_POST['fondo_reparo_monto']),
            $_POST['anticipo_pct_aplicado'], $f($_POST['anticipo_descuento']), $f($_POST['multas_monto']), 
            $f($_POST['monto_neto_pagar']), $_POST['avance_fisico_mensual']
        ];
        
        $pdo->prepare($sql)->execute(array_merge($params, $params));
        if ($id == 0) $id = $pdo->lastInsertId();

        // 2. Fuentes Financiamiento (Manuales: Guardamos exactamente lo que el usuario envió)
        $pdo->prepare("DELETE FROM certificados_financiamiento WHERE certificado_id = ?")->execute([$id]);
        if (isset($_POST['fuente_id'])) {
            $stmtFu = $pdo->prepare("INSERT INTO certificados_financiamiento (certificado_id, fuente_id, porcentaje, monto_asignado) VALUES (?, ?, ?, ?)");
            foreach ($_POST['fuente_id'] as $k => $fid) {
                // Aquí usamos el monto que viene del input editable
                $montoFufi = $f($_POST['fuente_monto'][$k]);
                $stmtFu->execute([$id, $fid, $_POST['fuente_pct'][$k], $montoFufi]);
            }
        }

        // 3. Facturas
        $pdo->prepare("DELETE FROM certificados_facturas WHERE certificado_id = ?")->execute([$id]);
        if (isset($_POST['facturas_arca'])) {
            $stmtFac = $pdo->prepare("INSERT INTO certificados_facturas (certificado_id, comprobante_arca_id) VALUES (?, ?)");
            foreach ($_POST['facturas_arca'] as $fid) {
                $stmtFac->execute([$id, $fid]);
                $pdo->prepare("UPDATE comprobantes_arca SET estado_uso='VINCULADO' WHERE id=?")->execute([$fid]);
            }
        }

        $pdo->commit();
        header("Location: certificados_listado.php?obra_id=" . $_POST['obra_id']); 
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// --- CARGA DE DATOS ---
$cert = [
    'obra_id' => $obra_preseleccionada, 
    'empresa_id'=>'', 'nro_certificado'=>'1', 'tipo' => 'ORDINARIO',
    'periodo'=> ($periodo_preseleccionado ?: date('Y-m')),
    'monto_basico'=>0, 'fri'=>1.0000, 'monto_redeterminado'=>0,
    'fondo_reparo_pct'=>5, 'fondo_reparo_monto'=>0,
    'anticipo_pct_aplicado'=>0, 'anticipo_descuento'=>0, 
    'multas_monto'=>0, 'monto_neto_pagar'=>0, 'avance_fisico_mensual'=>0
];

$itemsFuentes = [];
$itemsFacturas = [];
$obraAnticipoPct = 0; 
$fuentesPreCargadas = false; // Flag para saber si usamos datos de DB o calculamos

if ($id > 0) {
    $cert = $pdo->query("SELECT * FROM certificados WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    // Cargamos fuentes guardadas tal cual estaban (para respetar ediciones manuales)
    $itemsFuentes = $pdo->query("SELECT cf.*, ff.nombre FROM certificados_financiamiento cf JOIN fuentes_financiamiento ff ON cf.fuente_id = ff.id WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $itemsFacturas = $pdo->query("SELECT c.*, a.numero, a.importe_total FROM certificados_facturas c JOIN comprobantes_arca a ON c.comprobante_arca_id = a.id WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $obra_preseleccionada = $cert['obra_id'];
    $fuentesPreCargadas = true;
} elseif ($obra_preseleccionada > 0) {
    // Lógica para nuevo certificado
    $stmtUltimo = $pdo->prepare("SELECT nro_certificado, empresa_id FROM certificados WHERE obra_id = ? ORDER BY nro_certificado DESC LIMIT 1");
    $stmtUltimo->execute([$obra_preseleccionada]);
    $ultimo = $stmtUltimo->fetch();
    if($ultimo) {
        $cert['nro_certificado'] = $ultimo['nro_certificado'] + 1;
        $cert['empresa_id'] = $ultimo['empresa_id'];
    } else {
        $stmtObra = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
        $stmtObra->execute([$obra_preseleccionada]);
        $resObra = $stmtObra->fetch();
        if($resObra) $cert['empresa_id'] = $resObra['empresa_id'];
    }
}

// Datos Obra extra
if($obra_preseleccionada > 0){
    $stmtO = $pdo->prepare("SELECT anticipo_pct FROM obras WHERE id = ?");
    $stmtO->execute([$obra_preseleccionada]);
    $res = $stmtO->fetch();
    if($res) $obraAnticipoPct = $res['anticipo_pct'];
    if($id == 0) $cert['anticipo_pct_aplicado'] = $obraAnticipoPct;
}

$obras = $pdo->query("SELECT id, denominacion, empresa_id FROM obras WHERE activo=1")->fetchAll();
$empresas = $pdo->query("SELECT id, razon_social, cuit FROM empresas WHERE activo=1")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<style>
    .input-fufi { border: 1px solid #ced4da; border-radius: 4px; padding: 2px 5px; width: 100%; text-align: right; background-color: #fff; }
    .input-fufi:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
    .bg-curva-info { background-color: #e2e3e5; color: #41464b; font-size: 0.85rem; padding: 5px 10px; border-radius: 5px; display: inline-block; margin-top: 5px;}
</style>

<form method="POST" id="formCert">
<input type="hidden" id="obraAnticipoPctDef" value="<?= $obraAnticipoPct ?>">
<input type="hidden" id="fuentesPreCargadas" value="<?= $fuentesPreCargadas ? '1' : '0' ?>">

<div class="container my-4">
    <div class="d-flex justify-content-between mb-3 align-items-center">
        <h3><?= $id>0 ? 'Editar Certificado' : 'Nuevo Certificado' ?></h3>
        <a href="certificados_listado.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <div class="card mb-3 shadow-sm border-top border-4 border-primary">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-bold">Obra</label>
                    <?php if($obra_preseleccionada > 0 && $id == 0): ?>
                        <input type="hidden" name="obra_id" id="selectObra" value="<?= $cert['obra_id'] ?>">
                        <?php 
                            $nombreObra = "Obra no encontrada";
                            foreach($obras as $o) if($o['id'] == $cert['obra_id']) $nombreObra = $o['denominacion'];
                        ?>
                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($nombreObra) ?>" readonly>
                    <?php else: ?>
                        <select name="obra_id" id="selectObra" class="form-select" required onchange="cambioObra(this)">
                            <option value="">Seleccione...</option>
                            <?php foreach($obras as $o): ?>
                                <option value="<?= $o['id'] ?>" data-empid="<?= $o['empresa_id'] ?>" <?= $cert['obra_id']==$o['id']?'selected':'' ?>><?= $o['denominacion'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Empresa</label>
                    <select name="empresa_id" id="selectEmpresa" class="form-select bg-light" required>
                        <option value="">Seleccione...</option>
                        <?php foreach($empresas as $e): ?>
                            <option value="<?= $e['id'] ?>" data-cuit="<?= $e['cuit'] ?>" <?= $cert['empresa_id']==$e['id']?'selected':'' ?>><?= $e['razon_social'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold">Tipo</label>
                    <select name="tipo" class="form-select fw-bold border-primary">
                        <option value="ORDINARIO" <?= $cert['tipo']=='ORDINARIO'?'selected':'' ?>>ORDINARIO (Mensual)</option>
                        <option value="ANTICIPO" <?= $cert['tipo']=='ANTICIPO'?'selected':'' ?>>ANTICIPO</option>
                        <option value="REDETERMINACION" <?= $cert['tipo']=='REDETERMINACION'?'selected':'' ?>>REDETERMINACIÓN</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Nº Cert.</label>
                    <input type="number" name="nro_certificado" class="form-control fw-bold text-center" value="<?= $cert['nro_certificado'] ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Periodo</label>
                    <input type="month" name="periodo" id="inputPeriodo" class="form-control" value="<?= $cert['periodo'] ?>" required onchange="checkCurva()">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha Medición</label>
                    <input type="date" name="fecha_medicion" class="form-control" value="<?= $cert['fecha_medicion'] ?? date('Y-m-d') ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Avance Físico Mensual</label>
                    <div class="input-group">
                        <input type="number" name="avance_fisico_mensual" id="inputAvance" step="0.01" class="form-control fw-bold" value="<?= $cert['avance_fisico_mensual'] ?>">
                        <span class="input-group-text">%</span>
                    </div>
                    <div id="infoCurva" class="d-none mt-1">
                        <span class="badge bg-info text-dark border">
                            <i class="bi bi-graph-up"></i> Planificado: <span id="valCurva">0</span>%
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="card mb-3 shadow-sm h-100">
                <div class="card-header bg-light fw-bold">Cálculo del Certificado</div>
                <div class="card-body">
                    
                    <div class="mb-4">
                        <label class="form-label text-muted text-uppercase small fw-bold">1. Certificado Básico</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">$</span>
                            <input type="text" name="monto_basico" id="montoBasico" class="form-control monto text-end fw-bold" 
                                   value="<?= number_format($cert['monto_basico'],2,',','.') ?>" oninput="calcTotales()">
                        </div>
                    </div>
                    
                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="text-muted text-uppercase small fw-bold mb-0">2. Redeterminación</label>
                                <?php if($id > 0): ?>
                                    <a href="redeterminaciones_form.php?cert_id=<?= $id ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-list-check"></i> Cargar Detalles
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Guardar primero para detallar</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="row g-2 align-items-center">
                                <div class="col-md-4">
                                    <label class="small text-muted">FRI (Opcional)</label>
                                    <input type="number" step="0.0001" name="fri" id="inputFri" class="form-control text-center" 
                                           value="<?= $cert['fri'] ?>" placeholder="1.0000" oninput="calcRedetPorFri()">
                                </div>
                                <div class="col-md-8">
                                    <label class="small text-muted">Monto Redeterminado Total</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="text" name="monto_redeterminado" id="montoRedet" class="form-control monto text-end" 
                                               value="<?= number_format($cert['monto_redeterminado'],2,',','.') ?>" oninput="calcTotales()">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h6 class="text-muted border-bottom pb-2">3. Deducciones</h6>
                    
                    <div class="row align-items-center mb-2">
                        <div class="col-md-5">
                            <label class="small fw-bold">Fondo Reparo</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="checkSustituido" onclick="toggleFondoReparo()">
                                <label class="form-check-label small" for="checkSustituido">Sustituido</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group input-group-sm">
                                <input type="number" name="fondo_reparo_pct" id="frPct" class="form-control" value="<?= $cert['fondo_reparo_pct'] ?>" onchange="calcTotales()">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="fondo_reparo_monto" id="frMonto" class="form-control form-control-sm text-end text-danger monto" readonly value="<?= number_format($cert['fondo_reparo_monto'],2,',','.') ?>">
                        </div>
                    </div>

                    <div class="row align-items-center mb-2">
                        <div class="col-md-5">
                            <label class="small fw-bold">Devolución Anticipo</label>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" name="anticipo_pct_aplicado" id="antPct" class="form-control" 
                                       value="<?= $cert['anticipo_pct_aplicado'] ?>" oninput="calcAnticipo()">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="anticipo_descuento" id="antMonto" class="form-control form-control-sm text-end text-danger monto" 
                                   value="<?= number_format($cert['anticipo_descuento'],2,',','.') ?>" oninput="calcNeto()">
                        </div>
                    </div>

                    <div class="row align-items-center mb-3">
                        <div class="col-md-8"><label class="small fw-bold">Multas / Sanciones</label></div>
                        <div class="col-md-4">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text text-danger">$</span>
                                <input type="text" name="multas_monto" id="multasMonto" class="form-control text-end text-danger monto" 
                                       value="<?= number_format($cert['multas_monto'],2,',','.') ?>" oninput="calcNeto()">
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-primary d-flex justify-content-between align-items-center mt-3 mb-0">
                        <span class="fw-bold">A PAGAR (NETO):</span>
                        <input type="hidden" name="monto_neto_pagar" id="inputNetoHidden" value="<?= $cert['monto_neto_pagar'] ?>">
                        <span id="txtNeto" class="fs-3 fw-bold">$ 0,00</span>
                    </div>

                    <div class="mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-muted fw-bold">Imputación por Fuente (Editable)</small>
                            <button type="button" class="btn btn-sm btn-link py-0" style="font-size:0.8rem" onclick="distribuirFuentes(true)">
                                <i class="bi bi-arrow-clockwise"></i> Recalcular
                            </button>
                        </div>
                        <div id="bodyFuentes" class="bg-light p-2 rounded small border">
                            <?php if(!empty($itemsFuentes)): ?>
                                <?php foreach($itemsFuentes as $iff): ?>
                                <div class="row mb-1 align-items-center g-1 fila-fufi">
                                    <div class="col-6">
                                        <?= htmlspecialchars($iff['nombre']) ?>
                                        <input type="hidden" name="fuente_id[]" value="<?= $iff['fuente_id'] ?>">
                                        <input type="hidden" name="fuente_pct[]" value="<?= $iff['porcentaje'] ?>">
                                    </div>
                                    <div class="col-6">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">$</span>
                                            <input type="text" name="fuente_monto[]" class="form-control text-end monto input-fufi-val" 
                                                   value="<?= number_format($iff['monto_asignado'],2,',','.') ?>">
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted text-center py-2">Sin fuentes asignadas o pendientes de cálculo.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card mb-3 shadow-sm border-info h-100">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="bi bi-receipt"></i> Facturación</span>
                    <button type="button" class="btn btn-sm btn-light text-info fw-bold" onclick="abrirModalArca()">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <div class="card-body bg-light position-relative">
                    <div id="listaFacturas" class="vstack gap-2">
                        <?php foreach($itemsFacturas as $if): ?>
                            <div class="card border-0 shadow-sm p-2 d-flex flex-row justify-content-between align-items-center item-factura">
                                <div><div class="fw-bold">Factura <?= $if['numero'] ?></div></div>
                                <div class="text-end fw-bold text-success">$ <?= number_format($if['importe_total'],2,',','.') ?>
                                <button type="button" class="btn btn-link btn-sm text-danger p-0" onclick="removerFactura(this)">x</button></div>
                                <input type="hidden" name="facturas_arca[]" value="<?= $if['comprobante_arca_id'] ?>">
                                <input type="hidden" class="monto-arca" value="<?= $if['importe_total'] ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 pt-3 border-top d-flex justify-content-between fw-bold">
                        <span>Total:</span> <span id="totalArca">$ 0,00</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-lg w-100 mt-3 shadow"><i class="bi bi-save"></i> Guardar</button>
</div>
</form>

<div class="modal fade" id="modalArca" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white"><h5 class="modal-title">Facturas</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="contenidoArca"></div>
        </div>
    </div>
</div>

<script>
let configFuentes = []; 

function cambioObra(select) {
    let opt = select.options[select.selectedIndex];
    if(opt.dataset.empid) document.getElementById('selectEmpresa').value = opt.dataset.empid;
    cargarConfigObra(select.value);
    checkCurva();
}

function cargarConfigObra(id) {
    if(!id) return;
    fetch('api_get_fuentes.php?obra_id=' + id).then(r => r.json()).then(data => {
        configFuentes = data;
        // Solo recalculamos si no hay fuentes cargadas (para no pisar datos en edición)
        if(document.querySelectorAll('.fila-fufi').length === 0) distribuirFuentes();
    });
}

function checkCurva() {
    let oid = document.getElementById('selectObra').value;
    let per = document.getElementById('inputPeriodo').value;
    let info = document.getElementById('infoCurva');
    
    if(!oid || !per) { info.classList.add('d-none'); return; }

    fetch('api_get_curva.php?obra_id='+oid+'&periodo='+per)
        .then(r => r.json())
        .then(data => {
            if(data.existe) {
                document.getElementById('valCurva').innerText = data.planificado.toLocaleString('es-AR');
                info.classList.remove('d-none');
            } else {
                info.classList.add('d-none');
            }
        });
}

// CÁLCULOS
function parseM(v) { return parseFloat((v||'0').toString().replace(/\./g,'').replace(',','.')) || 0; }
function fmtM(v) { return v.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

function calcRedetPorFri() {
    let basico = parseM(document.getElementById('montoBasico').value);
    let fri = parseFloat(document.getElementById('inputFri').value) || 1;
    if(fri < 1) fri = 1;
    let redet = basico * (fri - 1);
    document.getElementById('montoRedet').value = fmtM(redet);
    calcTotales();
}

function calcAnticipo() {
    let basico = parseM(document.getElementById('montoBasico').value);
    let pct = parseFloat(document.getElementById('antPct').value) || 0;
    document.getElementById('antMonto').value = fmtM(basico * (pct / 100));
    calcNeto();
}

function calcTotales() {
    let basico = parseM(document.getElementById('montoBasico').value);
    let redet = parseM(document.getElementById('montoRedet').value);
    let bruto = basico + redet;
    
    // Fondo Reparo
    let frPct = parseFloat(document.getElementById('frPct').value) || 0;
    document.getElementById('frMonto').value = fmtM(bruto * (frPct / 100));
    
    calcAnticipo(); // Llama a calcNeto
}

function calcNeto() {
    let basico = parseM(document.getElementById('montoBasico').value);
    let redet = parseM(document.getElementById('montoRedet').value);
    let bruto = basico + redet;
    let fr = parseM(document.getElementById('frMonto').value);
    let ant = parseM(document.getElementById('antMonto').value);
    let mul = parseM(document.getElementById('multasMonto').value);
    
    let neto = bruto - fr - ant - mul;
    if(neto < 0) neto = 0;

    document.getElementById('txtNeto').innerText = '$ ' + fmtM(neto);
    document.getElementById('inputNetoHidden').value = neto.toFixed(2);
    
    // Validar facturas
    validarArca(); 
}

function distribuirFuentes(forzar = false) {
    let container = document.getElementById('bodyFuentes');
    // Si ya hay inputs y no estamos forzando, NO hacemos nada (respetar edición manual)
    if(container.querySelectorAll('input').length > 0 && !forzar) return;

    let neto = parseM(document.getElementById('inputNetoHidden').value);
    container.innerHTML = '';
    
    if(configFuentes.length === 0) { container.innerHTML = '<div class="text-muted text-center">Sin configuración</div>'; return; }

    configFuentes.forEach(f => {
        let montoF = neto * (f.porcentaje / 100);
        let div = document.createElement('div');
        div.className = "row mb-1 align-items-center g-1 fila-fufi";
        div.innerHTML = `
            <div class="col-6 small">${f.nombre} (${f.porcentaje}%)
                <input type="hidden" name="fuente_id[]" value="${f.fuente_id}">
                <input type="hidden" name="fuente_pct[]" value="${f.porcentaje}">
            </div>
            <div class="col-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="text" name="fuente_monto[]" class="form-control text-end monto input-fufi-val" 
                           value="${fmtM(montoF)}">
                </div>
            </div>`;
        container.appendChild(div);
    });
    // Rebindear mascara montos a los nuevos inputs
    document.querySelectorAll('.monto').forEach(el => {
        el.addEventListener('blur', function() { this.value = fmtM(parseM(this.value)); });
        el.addEventListener('focus', function() { this.value = parseM(this.value); });
    });
}

// Helpers Deducciones/ARCA (Igual que antes)
function toggleFondoReparo() {
    let chk = document.getElementById('checkSustituido');
    let inp = document.getElementById('frPct');
    if(chk.checked) { inp.dataset.old = inp.value; inp.value = 0; inp.readOnly = true; } 
    else { inp.value = inp.dataset.old || 5; inp.readOnly = false; }
    calcTotales();
}
function abrirModalArca() {
    let emp = document.getElementById('selectEmpresa');
    let cuit = emp.options[emp.selectedIndex].dataset.cuit;
    if(!cuit) { alert('Sin CUIT'); return; }
    new bootstrap.Modal(document.getElementById('modalArca')).show();
    fetch('api_get_facturas.php?cuit=' + cuit).then(r=>r.text()).then(h=>document.getElementById('contenidoArca').innerHTML=h);
}
function seleccionarFactura(id, n, m) {
    let div = document.getElementById('listaFacturas');
    if(div.innerHTML.includes('value="'+id+'"')) return;
    div.innerHTML += `<div class="card border-0 shadow-sm p-2 d-flex flex-row justify-content-between align-items-center item-factura mb-2">
        <div><div class="fw-bold">Fac ${n}</div></div><div class="text-end fw-bold text-success">$ ${fmtM(parseFloat(m))}
        <button type="button" class="btn btn-link btn-sm text-danger p-0" onclick="removerFactura(this)">x</button></div>
        <input type="hidden" name="facturas_arca[]" value="${id}">
        <input type="hidden" class="monto-arca" value="${m}"></div>`;
    bootstrap.Modal.getInstance(document.getElementById('modalArca')).hide();
    validarArca();
}
function removerFactura(btn) { btn.closest('.item-factura').remove(); validarArca(); }
function validarArca() {
    let total = 0; document.querySelectorAll('.monto-arca').forEach(e=>total+=parseFloat(e.value));
    document.getElementById('totalArca').innerText = '$ '+fmtM(total);
}

document.addEventListener('DOMContentLoaded', function() {
    // Inicializar
    let oid = document.getElementById('selectObra').value;
    if(oid) {
        cargarConfigObra(oid);
        checkCurva();
    }
    // Mascara inputs
    document.querySelectorAll('.monto').forEach(el => {
        el.addEventListener('blur', function() { this.value = fmtM(parseM(this.value)); });
        el.addEventListener('focus', function() { this.value = parseM(this.value); });
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
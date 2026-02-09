<?php
// modulos/certificados/certificados_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;
$obra_preseleccionada = $_GET['obra_id'] ?? 0;

// --- LÓGICA DE GUARDADO (BACKEND) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $f = function($v){ return (float)str_replace(',','.', str_replace('.','',$v)); };
        
        $montoBasico = $f($_POST['monto_basico']);
        $montoRedet = 0; 
        
        // 1. Guardar Cabecera
        $sql = "INSERT INTO certificados (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion, 
                monto_basico, fondo_reparo_pct, fondo_reparo_monto, anticipo_descuento, multas_monto, 
                monto_neto_pagar, avance_fisico_mensual, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'BORRADOR')
                ON DUPLICATE KEY UPDATE 
                obra_id=?, empresa_id=?, nro_certificado=?, tipo=?, periodo=?, fecha_medicion=?, monto_basico=?, 
                fondo_reparo_pct=?, fondo_reparo_monto=?, anticipo_descuento=?, multas_monto=?, 
                monto_neto_pagar=?, avance_fisico_mensual=?";
        
        $params = [
            $_POST['obra_id'], $_POST['empresa_id'], $_POST['nro_certificado'], $_POST['tipo'], $_POST['periodo'], $_POST['fecha_medicion'],
            $montoBasico, $_POST['fondo_reparo_pct'], $f($_POST['fondo_reparo_monto']), $f($_POST['anticipo_descuento']), 
            $f($_POST['multas_monto']), $f($_POST['monto_neto_pagar']), $_POST['avance_fisico_mensual']
        ];
        
        $pdo->prepare($sql)->execute(array_merge($params, $params));
        if ($id == 0) $id = $pdo->lastInsertId();

        // 2. Items Redeterminación
        $pdo->prepare("DELETE FROM certificados_items WHERE certificado_id = ?")->execute([$id]);
        if (isset($_POST['redet_monto'])) {
            $stmtIt = $pdo->prepare("INSERT INTO certificados_items (certificado_id, concepto, monto, tipo) VALUES (?, ?, ?, 'REDETERMINACION')");
            foreach ($_POST['redet_monto'] as $k => $monto) {
                if ($f($monto) > 0) {
                    $stmtIt->execute([$id, $_POST['redet_concepto'][$k], $f($monto)]);
                    $montoRedet += $f($monto);
                }
            }
        }
        
        // Actualizar Totales Brutos
        $pdo->prepare("UPDATE certificados SET monto_redeterminaciones=?, monto_bruto=? WHERE id=?")
            ->execute([$montoRedet, ($montoBasico + $montoRedet), $id]);

        // 3. Fuentes Financiamiento (Ahora guardamos lo que el usuario editó manualmente)
        $pdo->prepare("DELETE FROM certificados_financiamiento WHERE certificado_id = ?")->execute([$id]);
        if (isset($_POST['fuente_id'])) {
            $stmtFu = $pdo->prepare("INSERT INTO certificados_financiamiento (certificado_id, fuente_id, porcentaje, monto_asignado) VALUES (?, ?, ?, ?)");
            foreach ($_POST['fuente_id'] as $k => $fid) {
                // Notar que ahora tomamos fuente_monto del POST, que puede haber sido editado a mano
                $stmtFu->execute([$id, $fid, $_POST['fuente_pct'][$k], $f($_POST['fuente_monto'][$k])]);
            }
        }

        // 4. Facturas ARCA
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
        $error = "Error al guardar: " . $e->getMessage();
    }
}

// --- CARGA DE DATOS ---
$cert = [
    'obra_id' => $obra_preseleccionada, 
    'empresa_id'=>'', 
    'nro_certificado'=>'1', 
    'tipo' => 'ORDINARIO',
    'periodo'=>date('Y-m'),
    'monto_basico'=>0, 'fondo_reparo_pct'=>5, 'fondo_reparo_monto'=>0,
    'anticipo_descuento'=>0, 'multas_monto'=>0, 'monto_neto_pagar'=>0, 'avance_fisico_mensual'=>0
];

$itemsRedet = [];
$itemsFuentes = [];
$itemsFacturas = [];
$obraAnticipoPct = 0; // Para guardar el % de anticipo de la obra

if ($id > 0) {
    $cert = $pdo->query("SELECT * FROM certificados WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $itemsRedet = $pdo->query("SELECT * FROM certificados_items WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $itemsFuentes = $pdo->query("SELECT * FROM certificados_financiamiento WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $itemsFacturas = $pdo->query("SELECT c.*, a.numero, a.importe_total FROM certificados_facturas c JOIN comprobantes_arca a ON c.comprobante_arca_id = a.id WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $obra_preseleccionada = $cert['obra_id'];
} elseif ($obra_preseleccionada > 0) {
    // Si es nuevo, buscar sugerencias
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

// Traer datos extra de la obra (Anticipo original)
if($obra_preseleccionada > 0){
    $stmtO = $pdo->prepare("SELECT anticipo_pct FROM obras WHERE id = ?");
    $stmtO->execute([$obra_preseleccionada]);
    $res = $stmtO->fetch();
    if($res) $obraAnticipoPct = $res['anticipo_pct'];
}

// Listas
$obras = $pdo->query("SELECT id, denominacion, empresa_id FROM obras WHERE activo=1")->fetchAll();
$empresas = $pdo->query("SELECT id, razon_social, cuit FROM empresas WHERE activo=1")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<style>
    .input-edit-fufi { background-color: #fcfcfc; border: 1px dashed #ccc; padding: 2px 5px; width: 120px; text-align: right; }
    .input-edit-fufi:focus { background-color: #fff; border: 1px solid #0d6efd; outline: none; }
</style>

<?php if(isset($error)): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<form method="POST" id="formCert">
<input type="hidden" id="obraAnticipoPct" value="<?= $obraAnticipoPct ?>">

<div class="container my-4">
    
    <div class="d-flex justify-content-between mb-3 align-items-center">
        <div>
            <h3 class="mb-0"><?= $id>0 ? 'Editar Certificado' : 'Nuevo Certificado' ?></h3>
            <span class="text-muted small">Vinculado a Planificación y Financiamiento</span>
        </div>
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
                    <label class="form-label">Empresa Contratista</label>
                    <select name="empresa_id" id="selectEmpresa" class="form-select bg-light" required>
                        <option value="">Seleccione...</option>
                        <?php foreach($empresas as $e): ?>
                            <option value="<?= $e['id'] ?>" data-cuit="<?= $e['cuit'] ?>" <?= $cert['empresa_id']==$e['id']?'selected':'' ?>>
                                <?= $e['razon_social'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label text-primary fw-bold">Tipo</label>
                    <select name="tipo" class="form-select fw-bold border-primary">
                        <option value="ORDINARIO" <?= $cert['tipo']=='ORDINARIO'?'selected':'' ?>>ORDINARIO</option>
                        <option value="ANTICIPO" <?= $cert['tipo']=='ANTICIPO'?'selected':'' ?>>ANTICIPO</option>
                        <option value="REDETERMINACION" <?= $cert['tipo']=='REDETERMINACION'?'selected':'' ?>>REDETERMINACION</option>
                        <option value="ADICIONAL" <?= $cert['tipo']=='ADICIONAL'?'selected':'' ?>>ADICIONAL</option>
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
                    <label class="form-label">F. Medición</label>
                    <input type="date" name="fecha_medicion" class="form-control" value="<?= $cert['fecha_medicion'] ?? date('Y-m-d') ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Avance Físico Mensual</label>
                    <div class="input-group">
                        <span class="input-group-text bg-warning-subtle" id="infoCurva" title="Valor Planificado en Curva" style="display:none">
                            <i class="bi bi-graph-up me-1"></i> <span id="valCurva">0</span>%
                        </span>
                        <input type="number" name="avance_fisico_mensual" step="0.01" class="form-control fw-bold" value="<?= $cert['avance_fisico_mensual'] ?>">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text small" id="msgCurva"></div>
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
                        <label class="form-label text-muted">Monto Básico / Certificado Origen</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">$</span>
                            <input type="text" name="monto_basico" id="montoBasico" class="form-control monto text-end fw-bold" 
                                   value="<?= number_format($cert['monto_basico'],2,',','.') ?>" oninput="calcTotales()">
                        </div>
                    </div>
                    
                    <div class="card bg-light border-0 mb-3">
                        <div class="card-body p-2">
                            <label class="fw-bold mb-2 small text-uppercase text-muted">Redeterminaciones</label>
                            <div id="listaRedet">
                                <?php foreach($itemsRedet as $ir): ?>
                                <div class="input-group mb-2 item-redet">
                                    <input type="text" name="redet_concepto[]" class="form-control form-control-sm" value="<?= $ir['concepto'] ?>">
                                    <span class="input-group-text py-0">$</span>
                                    <input type="text" name="redet_monto[]" class="form-control form-control-sm monto-redet text-end" 
                                           value="<?= number_format($ir['monto'],2,',','.') ?>" oninput="calcTotales()">
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.parentElement.remove(); calcTotales()">&times;</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-decoration-none px-0" onclick="agregarRedet()">
                                <i class="bi bi-plus-circle"></i> Agregar Redet.
                            </button>
                        </div>
                    </div>

                    <h6 class="text-muted mt-4 border-bottom pb-2">Deducciones</h6>
                    
                    <div class="row align-items-center mb-2">
                        <div class="col-md-5">
                            <label class="small fw-bold">Fondo Reparo</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="checkSustituido" onclick="toggleFondoReparo()">
                                <label class="form-check-label small" for="checkSustituido">Sustituido (Póliza)</label>
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
                        <div class="col-md-8">
                            <label class="small fw-bold">Devolución Anticipo</label>
                            <span class="badge bg-warning text-dark ms-2" title="% Anticipo de la Obra">
                                Obra: <?= number_format($obraAnticipoPct, 2) ?>%
                            </span>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text text-danger">$</span>
                                <input type="text" name="anticipo_descuento" id="antMonto" class="form-control text-end text-danger monto" 
                                       value="<?= number_format($cert['anticipo_descuento'],2,',','.') ?>" oninput="calcNeto()">
                            </div>
                            <button type="button" class="btn btn-link btn-sm p-0 small" onclick="sugerirAnticipo()">
                                Calcular sugerido
                            </button>
                        </div>
                    </div>

                    <div class="row align-items-center mb-3">
                        <div class="col-md-8">
                            <label class="small fw-bold">Multas / Sanciones</label>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text text-danger">$</span>
                                <input type="text" name="multas_monto" id="multasMonto" class="form-control text-end text-danger monto" 
                                       value="<?= number_format($cert['multas_monto'],2,',','.') ?>" oninput="calcNeto()">
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-primary d-flex justify-content-between align-items-center mt-3 mb-0">
                        <span class="fw-bold">NETO A PAGAR:</span>
                        <input type="hidden" name="monto_neto_pagar" id="inputNetoHidden" value="<?= $cert['monto_neto_pagar'] ?>">
                        <span id="txtNeto" class="fs-3 fw-bold">$ 0,00</span>
                    </div>

                    <div class="mt-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted fw-bold">Imputación por Fuente</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0" style="font-size: 0.75rem" onclick="forzarRecalculoFuentes()">
                                <i class="bi bi-arrow-repeat"></i> Restaurar Automático
                            </button>
                        </div>
                        <div id="bodyFuentes" class="bg-light p-2 rounded small mt-1">
                            </div>
                        <div id="warnManual" class="text-warning small d-none"><i class="bi bi-pencil"></i> Editado manualmente</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card mb-3 shadow-sm border-info h-100">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="bi bi-receipt"></i> Facturación ARCA</span>
                    <button type="button" class="btn btn-sm btn-light text-info fw-bold shadow-sm" onclick="abrirModalArca()">
                        <i class="bi bi-search"></i> Vincular
                    </button>
                </div>
                <div class="card-body bg-light position-relative">
                    <?php if(empty($itemsFacturas)): ?>
                        <div id="emptyArca" class="text-center text-muted py-4 opacity-50">
                            <i class="bi bi-qr-code-scan fs-1"></i>
                            <p class="small mb-0">Sin comprobantes vinculados</p>
                        </div>
                    <?php endif; ?>

                    <div id="listaFacturas" class="vstack gap-2">
                        <?php foreach($itemsFacturas as $if): ?>
                            <div class="card border-0 shadow-sm p-2 d-flex flex-row justify-content-between align-items-center item-factura">
                                <div>
                                    <div class="fw-bold text-dark">Factura <?= $if['numero'] ?></div>
                                    <small class="text-muted">Importado</small>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-success">$ <?= number_format($if['importe_total'],2,',','.') ?></div>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 text-decoration-none" onclick="removerFactura(this)">Quit</button>
                                </div>
                                <input type="hidden" name="facturas_arca[]" value="<?= $if['comprobante_arca_id'] ?>">
                                <input type="hidden" class="monto-arca" value="<?= $if['importe_total'] ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-3 pt-3 border-top">
                        <div class="d-flex justify-content-between fw-bold">
                            <span>Total Facturado:</span>
                            <span id="totalArca" class="text-dark">$ 0,00</span>
                        </div>
                        <div id="alertaArca" class="alert alert-warning mt-2 d-none small py-1 text-center">
                            <i class="bi bi-exclamation-triangle-fill"></i> Montos difieren
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 mt-3 shadow"><i class="bi bi-save"></i> Guardar Certificado</button>
</div>
</form>

<div class="modal fade" id="modalArca" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Seleccionar Facturas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="contenidoArca"></div>
        </div>
    </div>
</div>

<script>
// --- CONFIGURACIÓN Y ESTADO ---
let configFuentes = []; 
let fuentesEditadasManualmente = false;

// Al cargar, si ya es edición, asumimos que los datos de fuentes vienen de DB (itemsFuentes)
// PERO como JS recalcula todo al iniciar, necesitamos cargar la config base
// Y luego sobreescribir con los valores reales si existen.
// Para simplificar: En edición, cargamos config, calculamos, y si los valores difieren de DB es porque hubo edición manual o redondeo.

function cambioObra(select) {
    let opt = select.options[select.selectedIndex];
    // Auto seleccionar empresa
    let empId = opt.getAttribute('data-empid');
    if(empId) document.getElementById('selectEmpresa').value = empId;
    
    // Obtener config financiamiento
    cargarConfigObra(select.value);
    
    // Checkear curva
    checkCurva();
}

function cargarConfigObra(id) {
    if(!id) return;
    fetch('api_get_fuentes.php?obra_id=' + id)
        .then(r => r.json())
        .then(data => {
            configFuentes = data;
            // Si es certificado nuevo, calculamos de cero. Si es edición, se respetan los inputs hidden si los hubiera (PHP renderiza HTML, JS luego recalcula)
            // Para evitar pisar datos en edición al cargar la pagina, solo llamamos calc si no hay filas generadas
            if(document.getElementById('bodyFuentes').children.length === 0) {
               calcTotales();
            }
        });
}

function checkCurva() {
    let oid = document.getElementById('selectObra').value;
    let per = document.getElementById('inputPeriodo').value;
    if(!oid || !per) return;

    // Supongamos un endpoint simple que devuelve JSON {planificado: 10.5} o null
    // Como no tenemos el endpoint, simularemos la lógica visual por ahora o habría que crear api_get_curva_periodo.php
    // fetch('api_get_curva_data.php?obra_id='+oid+'&periodo='+per)...
}

// --- LÓGICA DE DEDUCCIONES ---

function toggleFondoReparo() {
    let chk = document.getElementById('checkSustituido');
    let inp = document.getElementById('frPct');
    if(chk.checked) {
        inp.dataset.old = inp.value;
        inp.value = 0;
        inp.readOnly = true;
    } else {
        inp.value = inp.dataset.old || 5;
        inp.readOnly = false;
    }
    calcTotales();
}

function sugerirAnticipo() {
    let pctObra = parseFloat(document.getElementById('obraAnticipoPct').value) || 0;
    if(pctObra > 0) {
        // Opción A: Descontar sobre el Básico del certificado
        let basico = parseM(document.getElementById('montoBasico').value);
        let descuento = basico * (pctObra / 100);
        document.getElementById('antMonto').value = fmtM(descuento);
        calcNeto();
    } else {
        alert("La obra no tiene porcentaje de anticipo configurado.");
    }
}

// --- CÁLCULOS PRINCIPALES ---

function parseM(v) { 
    if(!v) return 0;
    return parseFloat(v.toString().replace(/\./g,'').replace(',','.')) || 0; 
}
function fmtM(v) { return v.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

function calcTotales() {
    let basico = parseM(document.getElementById('montoBasico').value);
    let redet = 0;
    document.querySelectorAll('.monto-redet').forEach(el => redet += parseM(el.value));
    
    let bruto = basico + redet;
    
    // Fondo Reparo
    let frPct = parseFloat(document.getElementById('frPct').value) || 0;
    let frMonto = bruto * (frPct / 100);
    document.getElementById('frMonto').value = fmtM(frMonto);
    
    calcNeto(bruto);
}

function calcNeto(brutoIn = null) {
    if(brutoIn === null) {
        let basico = parseM(document.getElementById('montoBasico').value);
        let redet = 0;
        document.querySelectorAll('.monto-redet').forEach(el => redet += parseM(el.value));
        brutoIn = basico + redet;
    }
    
    let fr = parseM(document.getElementById('frMonto').value);
    let ant = parseM(document.getElementById('antMonto').value);
    let mul = parseM(document.getElementById('multasMonto').value); // NUEVO
    
    let neto = brutoIn - fr - ant - mul;
    if(neto < 0) neto = 0;

    document.getElementById('txtNeto').innerText = '$ ' + fmtM(neto);
    document.getElementById('inputNetoHidden').value = neto.toFixed(2);

    distribuirFuentes(neto);
    validarArca(); 
}

// --- FUENTES DE FINANCIAMIENTO (LÓGICA MANUAL/AUTO) ---

function distribuirFuentes(neto) {
    // Si el usuario editó manualmente, no recalculamos automáticamente
    if(fuentesEditadasManualmente) return;

    const div = document.getElementById('bodyFuentes');
    div.innerHTML = ''; // Limpiamos para regenerar
    
    if(configFuentes.length === 0) {
        div.innerHTML = '<span class="text-muted fst-italic">Sin fuentes configuradas.</span>';
        return;
    }

    configFuentes.forEach(f => {
        let montoF = neto * (f.porcentaje / 100);
        
        let row = document.createElement('div');
        row.className = "d-flex justify-content-between border-bottom border-secondary-subtle py-1 align-items-center";
        row.innerHTML = `
            <span>${f.nombre} <small class="text-muted">(${f.porcentaje}%)</small></span>
            <div>
                <span class="me-1">$</span>
                <input type="text" class="input-edit-fufi monto" name="fuente_monto[]" 
                       value="${fmtM(montoF)}" oninput="marcarManual()" onblur="formatInput(this)">
                <input type="hidden" name="fuente_id[]" value="${f.fuente_id}">
                <input type="hidden" name="fuente_pct[]" value="${f.porcentaje}">
            </div>`;
        div.appendChild(row);
    });
}

function marcarManual() {
    fuentesEditadasManualmente = true;
    document.getElementById('warnManual').classList.remove('d-none');
}

function forzarRecalculoFuentes() {
    fuentesEditadasManualmente = false;
    document.getElementById('warnManual').classList.add('d-none');
    let neto = parseM(document.getElementById('inputNetoHidden').value);
    distribuirFuentes(neto);
}

function formatInput(el) {
    el.value = fmtM(parseM(el.value));
}

// --- MODAL ARCA Y OTROS ---
function agregarRedet() {
    const div = document.createElement('div');
    div.className = 'input-group mb-2 item-redet';
    div.innerHTML = `<input type="text" name="redet_concepto[]" class="form-control form-control-sm" placeholder="Concepto">
                     <span class="input-group-text py-0">$</span>
                     <input type="text" name="redet_monto[]" class="form-control form-control-sm monto-redet text-end" value="0,00" oninput="calcTotales()">
                     <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.parentElement.remove(); calcTotales()">&times;</button>`;
    document.getElementById('listaRedet').appendChild(div);
}

function abrirModalArca() {
    let selectEmp = document.getElementById('selectEmpresa');
    let cuit = selectEmp.options[selectEmp.selectedIndex].getAttribute('data-cuit');
    if(!cuit) { alert('Sin CUIT asociado.'); return; }
    
    var myModal = new bootstrap.Modal(document.getElementById('modalArca'));
    myModal.show();
    
    document.getElementById('contenidoArca').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-info"></div></div>';
    fetch('api_get_facturas.php?cuit=' + cuit)
        .then(r => r.text())
        .then(html => document.getElementById('contenidoArca').innerHTML = html);
}

function seleccionarFactura(id, numero, monto) {
    let divLista = document.getElementById('listaFacturas');
    document.getElementById('emptyArca')?.classList.add('d-none');
    if(divLista.innerHTML.includes('value="'+id+'"')) return;

    let card = document.createElement('div');
    card.className = 'card border-0 shadow-sm p-2 d-flex flex-row justify-content-between align-items-center item-factura mb-2';
    card.innerHTML = `
        <div><div class="fw-bold text-dark">Factura ${numero}</div><small class="text-muted">Vinculada</small></div>
        <div class="text-end"><div class="fw-bold text-success">$ ${fmtM(parseFloat(monto))}</div>
        <button type="button" class="btn btn-link btn-sm text-danger p-0" onclick="removerFactura(this)">Quit</button></div>
        <input type="hidden" name="facturas_arca[]" value="${id}">
        <input type="hidden" class="monto-arca" value="${monto}">`;
    divLista.appendChild(card);
    bootstrap.Modal.getInstance(document.getElementById('modalArca')).hide();
    validarArca();
}

function removerFactura(btn) { btn.closest('.item-factura').remove(); validarArca(); }

function validarArca() {
    let totalArca = 0;
    document.querySelectorAll('.monto-arca').forEach(el => totalArca += parseFloat(el.value));
    document.getElementById('totalArca').innerText = '$ ' + fmtM(totalArca);
    let neto = parseM(document.getElementById('inputNetoHidden').value);
    let diff = Math.abs(neto - totalArca);
    let alerta = document.getElementById('alertaArca');
    if (diff > 100 && totalArca > 0) {
        alerta.classList.remove('d-none');
        alerta.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> Diferencia $ ${fmtM(neto - totalArca)}`;
    } else {
        alerta.classList.add('d-none');
    }
}

// INIT
document.addEventListener('DOMContentLoaded', function() {
    // Si hay obra preseleccionada
    let oid = document.getElementById('selectObra').value;
    if(oid) {
        // En edición, cargamos configuración, pero NO sobreescribimos HTML si ya existe (PHP renderiza filas guardadas)
        // Para detectar si es edición vs carga inicial de nuevo form:
        let isEdit = <?= ($id > 0) ? 'true' : 'false' ?>;
        
        // Cargamos la config de fuentes para tenerla en memoria (por si quiere "Restaurar")
        fetch('api_get_fuentes.php?obra_id=' + oid).then(r=>r.json()).then(d=>{ configFuentes=d; });

        // Si es nuevo form, autocalcular inicial
        if(!isEdit) calcTotales();
        // Si es edición, marcar como manual para que no se sobreescriba al tocar algo
        else {
             fuentesEditadasManualmente = true;
             // Llenamos configFuentes pero no ejecutamos distribuirFuentes()
        }
    }
    
    // Formatos inputs
    document.querySelectorAll('.monto').forEach(el => {
        el.addEventListener('blur', function() { this.value = fmtM(parseM(this.value)); });
        el.addEventListener('focus', function() { this.value = parseM(this.value); });
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
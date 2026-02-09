<?php
// modulos/certificados/certificados_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$id = $_GET['id'] ?? 0;
$obra_pre = $_GET['obra_id'] ?? 0;

// --- PROCESAR GUARDADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Helpers limpieza
        $f = function($v){ return (float)str_replace(',','.', str_replace('.','',$v)); };
        $d = function($v){ return !empty($v) ? $v : null; };

        // Datos básicos
        $obra_id = $_POST['obra_id'];
        $empresa_id = $_POST['empresa_id'];
        $nro_cert = $_POST['nro_certificado'];
        $tipo = $_POST['tipo']; 
        $periodo = $_POST['periodo'];
        
        // Fechas
        $f_medicion = $d($_POST['fecha_medicion']);
        $f_firma = $d($_POST['fecha_firma']);
        $f_requisitos = $d($_POST['fecha_requisitos_cumplidos']);

        // Importes
        $monto_basico = $f($_POST['monto_basico']);
        $monto_redet = $f($_POST['monto_redeterminado']);
        $fri = $f($_POST['fri']);
        
        $monto_bruto = $monto_basico + $monto_redet + $fri; 
        $monto_neto = $monto_bruto; 

        // -----------------------------------------------------------
        // LÓGICA DE VENCIMIENTO (Plazo: 60 días)
        // -----------------------------------------------------------
        $plazo = 60;
        $fecha_base = null;

        if ($tipo === 'ORIGINAL' && !empty($periodo)) {
            $dt = DateTime::createFromFormat('Y-m', $periodo);
            if ($dt) {
                $dt->modify('last day of this month'); 
                $dt->modify('+1 day'); 
                $fecha_base = $dt;
            }
        } elseif ($tipo === 'REDETERMINACION' && !empty($f_firma)) {
            $fecha_base = new DateTime($f_firma);
        }

        if ($fecha_base && !empty($f_requisitos)) {
            $dtReq = new DateTime($f_requisitos);
            if ($dtReq > $fecha_base) $fecha_base = $dtReq; 
        }

        $f_vencimiento = $fecha_base ? $fecha_base->modify("+$plazo days")->format('Y-m-d') : null;

        // -----------------------------------------------------------
        // INSERT / UPDATE CERTIFICADO
        // -----------------------------------------------------------
        if ($id == 0) {
            // Caso raro si viene de Curva, pero por las dudas dejamos el insert
            $stmt = $pdo->prepare("INSERT INTO certificados (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion, fecha_firma, fecha_requisitos_cumplidos, fecha_vencimiento, monto_basico, monto_redeterminado, fri, monto_neto, monto_bruto) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$obra_id, $empresa_id, $nro_cert, $tipo, $periodo, $f_medicion, $f_firma, $f_requisitos, $f_vencimiento, $monto_basico, $monto_redet, $fri, $monto_neto, $monto_bruto]);
            $id = $pdo->lastInsertId();
        } else {
            // LÓGICA PRINCIPAL: Actualización de datos sobre registro existente (Carga Inicial)
            $stmt = $pdo->prepare("UPDATE certificados SET obra_id=?, empresa_id=?, nro_certificado=?, tipo=?, periodo=?, fecha_medicion=?, fecha_firma=?, fecha_requisitos_cumplidos=?, fecha_vencimiento=?, monto_basico=?, monto_redeterminado=?, fri=?, monto_neto=?, monto_bruto=? WHERE id=?");
            $stmt->execute([$obra_id, $empresa_id, $nro_cert, $tipo, $periodo, $f_medicion, $f_firma, $f_requisitos, $f_vencimiento, $monto_basico, $monto_redet, $fri, $monto_neto, $monto_bruto, $id]);
        }

        // -----------------------------------------------------------
        // VINCULACIÓN: ORDENES DE PAGO (TGF/SICOPRO)
        // -----------------------------------------------------------
        // Estrategia: Borrar todo lo anterior y volver a insertar lo que está en pantalla (limpieza total)
        $pdo->prepare("DELETE FROM certificados_ops WHERE certificado_id = ?")->execute([$id]);
        
        if (isset($_POST['op_numero']) && is_array($_POST['op_numero'])) {
            $stmtOP = $pdo->prepare("INSERT INTO certificados_ops (certificado_id, ejercicio, nro_op, importe_op) VALUES (?,?,?,?)");
            
            for ($i=0; $i < count($_POST['op_numero']); $i++) {
                $num = $_POST['op_numero'][$i]; // Aquí guardamos el movnupa
                $ejer = $_POST['op_ejercicio'][$i];
                $imp = $f($_POST['op_importe'][$i]);
                
                if (!empty($num)) {
                    $stmtOP->execute([$id, $ejer, $num, $imp]);
                }
            }
        }

        // -----------------------------------------------------------
        // VINCULACIÓN: FACTURAS (ARCA)
        // -----------------------------------------------------------
        $pdo->prepare("DELETE FROM certificados_facturas WHERE certificado_id = ?")->execute([$id]);
        
        if (isset($_POST['facturas_arca']) && is_array($_POST['facturas_arca'])) {
            $stmtFac = $pdo->prepare("INSERT INTO certificados_facturas (certificado_id, comprobante_arca_id, monto_aplicado) VALUES (?,?,?)");
            foreach ($_POST['facturas_arca'] as $fid) {
                $stmtFac->execute([$id, $fid, 0]);
            }
        }

        $pdo->commit();
        echo "<script>window.location.href='certificados_listado.php?status=success';</script>";
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// --- CARGAR DATOS (EDICIÓN) ---
$cert = [];
$ops_vinculadas = [];
$facturas_vinculadas = [];

if ($id > 0) {
    $cert = $pdo->query("SELECT * FROM certificados WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    
    // Cargar OPs ya vinculadas
    $stmtOps = $pdo->prepare("SELECT * FROM certificados_ops WHERE certificado_id = ?");
    $stmtOps->execute([$id]);
    $ops_vinculadas = $stmtOps->fetchAll(PDO::FETCH_ASSOC);
    
    // Cargar Facturas ya vinculadas
    $stmtFac = $pdo->prepare("SELECT cf.*, ca.numero, ca.punto_venta, ca.importe_total 
                              FROM certificados_facturas cf 
                              JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id 
                              WHERE cf.certificado_id = ?");
    $stmtFac->execute([$id]);
    $facturas_vinculadas = $stmtFac->fetchAll(PDO::FETCH_ASSOC);
}

$obras = $pdo->query("SELECT id, denominacion, empresa_id FROM obras WHERE activo=1")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><?= $id > 0 ? 'Editar' : 'Nuevo' ?> Certificado</h3>
        <a href="certificados_listado.php" class="btn btn-secondary btn-sm">Volver</a>
    </div>
    
    <?php if(isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

    <form method="POST" id="formCert">
        <div class="card mb-3 shadow-sm">
            <div class="card-header bg-light fw-bold">1. Datos Generales (Carga Inicial / Actualización)</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Obra</label>
                        <select name="obra_id" id="selectObra" class="form-select" onchange="setEmpresa(this)" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach($obras as $o): ?>
                                <option value="<?= $o['id'] ?>" data-emp="<?= $o['empresa_id'] ?>" <?= ($cert['obra_id']??$obra_pre)==$o['id']?'selected':'' ?>><?= htmlspecialchars($o['denominacion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="empresa_id" id="empresa_id" value="<?= $cert['empresa_id']??'' ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Periodo</label>
                        <input type="month" name="periodo" class="form-control" value="<?= $cert['periodo']??'' ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">N° Cert.</label>
                        <input type="number" name="nro_certificado" class="form-control" value="<?= $cert['nro_certificado']??'' ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tipo</label>
                        <select name="tipo" class="form-select">
                            <option value="ORIGINAL" <?= ($cert['tipo']??'')=='ORIGINAL'?'selected':'' ?>>Original</option>
                            <option value="REDETERMINACION" <?= ($cert['tipo']??'')=='REDETERMINACION'?'selected':'' ?>>Redeterm.</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">F. Medición</label>
                        <input type="date" name="fecha_medicion" class="form-control" value="<?= $cert['fecha_medicion']??'' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">F. Firma</label>
                        <input type="date" name="fecha_firma" class="form-control" value="<?= $cert['fecha_firma']??'' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" title="Ajusta el vencimiento">F. Requisitos Cumpl.</label>
                        <input type="date" name="fecha_requisitos_cumplidos" class="form-control" value="<?= $cert['fecha_requisitos_cumplidos']??'' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-danger">Vencimiento (Calc)</label>
                        <input type="date" class="form-control bg-light" value="<?= $cert['fecha_vencimiento']??'' ?>" readonly>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3 shadow-sm">
            <div class="card-header bg-light fw-bold">2. Importes</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Monto Básico</label>
                        <input type="text" name="monto_basico" class="form-control text-end monto" value="<?= number_format($cert['monto_basico']??0,2,',','.') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Redeterminación</label>
                        <input type="text" name="monto_redeterminado" class="form-control text-end monto" value="<?= number_format($cert['monto_redeterminado']??0,2,',','.') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">FRI</label>
                        <input type="text" name="fri" class="form-control text-end monto" value="<?= number_format($cert['fri']??0,2,',','.') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-receipt"></i> Facturas ARCA</span>
                        <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalArca">
                            <i class="bi bi-search"></i> Buscar
                        </button>
                    </div>
                    <div class="card-body p-2" id="listaFacturas">
                        <?php foreach($facturas_vinculadas as $f): ?>
                            <div class="border rounded p-2 mb-1 d-flex justify-content-between align-items-center item-fac bg-light">
                                <div>
                                    <i class="bi bi-check-circle text-success"></i> 
                                    <strong>Fac: <?= $f['numero'] ?></strong>
                                    <br><small class="text-muted">$ <?= number_format($f['importe_total'],2,',','.') ?></small>
                                </div>
                                <input type="hidden" name="facturas_arca[]" value="<?= $f['comprobante_arca_id'] ?>">
                                <button type="button" class="btn-close btn-sm" onclick="this.closest('.item-fac').remove()"></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-cash-coin"></i> Órdenes de Pago (SICOPRO)</span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-light text-success" data-bs-toggle="modal" data-bs-target="#modalSicopro">
                                <i class="bi bi-search"></i> Buscar (movnupa)
                            </button>
                            <button type="button" class="btn btn-outline-light text-white" onclick="addOPManual()">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-2" id="listaOPs">
                        <?php foreach($ops_vinculadas as $op): ?>
                            <div class="input-group input-group-sm mb-2 item-op">
                                <span class="input-group-text bg-light">OP</span>
                                <input type="number" name="op_ejercicio[]" class="form-control" value="<?= $op['ejercicio'] ?>" style="max-width:70px">
                                <input type="number" name="op_numero[]" class="form-control fw-bold" value="<?= $op['nro_op'] ?>">
                                <span class="input-group-text">$</span>
                                <input type="text" name="op_importe[]" class="form-control monto text-end" value="<?= number_format($op['importe_op'],2,',','.') ?>">
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.item-op').remove()">X</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 text-end">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save"></i> Guardar Cambios</button>
        </div>
    </form>
</div>

<div class="modal fade" id="modalArca" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Vincular Factura</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="loadingArca" class="text-center py-3" style="display:none;"><div class="spinner-border text-primary"></div></div>
                <div id="listaFacturasAjax" class="list-group"></div>
                <div id="msgSinFacturas" class="alert alert-warning mt-2" style="display:none;">Sin facturas disponibles.</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalSicopro" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Buscar OP en SICOPRO</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" id="inputBuscarOP" class="form-control" placeholder="Buscar por N° Pago (movnupa), Trámite o Proveedor...">
                    <button class="btn btn-success" type="button" onclick="buscarOP()">Buscar</button>
                </div>
                
                <div id="loadingSicopro" class="text-center py-3" style="display:none;"><div class="spinner-border text-success"></div></div>
                <div id="listaSicoproAjax" class="list-group"></div>
            </div>
        </div>
    </div>
</div>

<script>
// 1. Configuración de Empresa
function setEmpresa(sel) {
    let opt = sel.options[sel.selectedIndex];
    document.getElementById('empresa_id').value = opt.getAttribute('data-emp') || '';
    document.getElementById('listaFacturas').innerHTML = ''; // Limpiar facturas al cambiar obra
}

// 2. OPs: Agregar Manual y AJAX
function addOPManual() {
    agregarFilaOP(2025, '', '');
}

function agregarFilaOP(anio, numero, importe) {
    let div = document.createElement('div');
    div.className = 'input-group input-group-sm mb-2 item-op';
    div.innerHTML = `
        <span class="input-group-text bg-light">OP</span>
        <input type="number" name="op_ejercicio[]" class="form-control" value="${anio}" style="max-width:70px" title="Ejercicio">
        <input type="number" name="op_numero[]" class="form-control fw-bold" value="${numero}" placeholder="N° movnupa">
        <span class="input-group-text">$</span>
        <input type="text" name="op_importe[]" class="form-control monto text-end" value="${importe}" placeholder="0,00">
        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.item-op').remove()">X</button>
    `;
    document.getElementById('listaOPs').appendChild(div);
}

// 3. BUSCADOR SICOPRO (AJAX)
function buscarOP() {
    let q = document.getElementById('inputBuscarOP').value;
    if(q.length < 3) { alert("Ingrese al menos 3 caracteres"); return; }
    
    let cont = document.getElementById('listaSicoproAjax');
    cont.innerHTML = '';
    document.getElementById('loadingSicopro').style.display = 'block';
    
    fetch(`data_ops_sicopro_ajax.php?q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('loadingSicopro').style.display = 'none';
            if(data.length === 0) {
                cont.innerHTML = '<div class="alert alert-warning">No se encontraron resultados.</div>';
                return;
            }
            data.forEach(item => {
                let btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action';
                btn.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>OP: ${item.numero_op}</strong> <span class="badge bg-secondary">${item.ejercicio}</span>
                            <div class="small text-muted">${item.proveedor}</div>
                        </div>
                        <span class="fw-bold text-success">$ ${item.importe_fmt}</span>
                    </div>
                `;
                btn.onclick = function() {
                    agregarFilaOP(item.ejercicio, item.numero_op, item.importe_fmt);
                    // Cerrar modal
                    try {
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSicopro')).hide();
                    } catch(e) {
                         // Fallback cierre manual
                        document.getElementById('modalSicopro').classList.remove('show');
                        document.getElementById('modalSicopro').style.display = 'none';
                        document.querySelector('.modal-backdrop').remove();
                    }
                };
                cont.appendChild(btn);
            });
        })
        .catch(e => {
            console.error(e);
            document.getElementById('loadingSicopro').style.display = 'none';
        });
}

// 4. MODAL ARCA (Lógica existente blindada)
var modalArca = document.getElementById('modalArca');
modalArca.addEventListener('show.bs.modal', function (e) {
    let empId = document.getElementById('empresa_id').value;
    if(!empId) { alert("Seleccione Obra primero."); e.preventDefault(); return; }
    cargarFacturas(empId);
});

function cargarFacturas(empId) {
    let cont = document.getElementById('listaFacturasAjax');
    cont.innerHTML = '';
    document.getElementById('loadingArca').style.display = 'block';
    
    fetch(`data_facturas_ajax.php?empresa_id=${empId}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('loadingArca').style.display = 'none';
            if(data.length === 0) {
                 document.getElementById('msgSinFacturas').style.display = 'block';
                 return;
            }
            data.forEach(fac => {
                let btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action d-flex justify-content-between';
                btn.innerHTML = `<span>${fac.texto}</span><span class="badge bg-primary">$ ${fac.monto_fmt}</span>`;
                btn.onclick = function() { seleccionarFactura(fac); };
                cont.appendChild(btn);
            });
        });
}

function seleccionarFactura(fac) {
    let div = document.createElement('div');
    div.className = 'border rounded p-2 mb-1 d-flex justify-content-between align-items-center item-fac bg-light';
    div.innerHTML = `<div><i class="bi bi-check-circle text-primary"></i> <strong>${fac.texto}</strong><br><small>$ ${fac.monto_fmt}</small></div><input type="hidden" name="facturas_arca[]" value="${fac.id}"><button type="button" class="btn-close btn-sm" onclick="this.closest('.item-fac').remove()"></button>`;
    document.getElementById('listaFacturas').appendChild(div);
    
    try { bootstrap.Modal.getOrCreateInstance(document.getElementById('modalArca')).hide(); } 
    catch(e) { 
        document.getElementById('modalArca').style.display='none'; 
        document.querySelector('.modal-backdrop')?.remove(); 
    }
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
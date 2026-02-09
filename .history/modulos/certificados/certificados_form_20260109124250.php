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
        
        // Calculo de Bruto y Neto (Simplificado)
        $monto_bruto = $monto_basico + $monto_redet + $fri; 
        $monto_neto = $monto_bruto; // Si hay descuentos (multas/anticipos), restarlos aquí.

        // -----------------------------------------------------------
        // LÓGICA DE VENCIMIENTO (Plazo: 60 días)
        // -----------------------------------------------------------
        $plazo = 60;
        $fecha_base = null;

        if ($tipo === 'ORIGINAL' && !empty($periodo)) {
            // Original: 1er día del mes siguiente al periodo
            $dt = DateTime::createFromFormat('Y-m', $periodo);
            if ($dt) {
                $dt->modify('last day of this month'); 
                $dt->modify('+1 day'); 
                $fecha_base = $dt;
            }
        } elseif ($tipo === 'REDETERMINACION' && !empty($f_firma)) {
            // Redet: Fecha de Firma
            $fecha_base = new DateTime($f_firma);
        }

        // Ajuste por Requisitos Cumplidos
        if ($fecha_base && !empty($f_requisitos)) {
            $dtReq = new DateTime($f_requisitos);
            if ($dtReq > $fecha_base) {
                $fecha_base = $dtReq; 
            }
        }

        $f_vencimiento = $fecha_base ? $fecha_base->modify("+$plazo days")->format('Y-m-d') : null;

        // -----------------------------------------------------------
        // INSERT / UPDATE CERTIFICADO
        // -----------------------------------------------------------
        if ($id == 0) {
            $stmt = $pdo->prepare("INSERT INTO certificados (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion, fecha_firma, fecha_requisitos_cumplidos, fecha_vencimiento, monto_basico, monto_redeterminado, fri, monto_neto, monto_bruto) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$obra_id, $empresa_id, $nro_cert, $tipo, $periodo, $f_medicion, $f_firma, $f_requisitos, $f_vencimiento, $monto_basico, $monto_redet, $fri, $monto_neto, $monto_bruto]);
            $id = $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare("UPDATE certificados SET obra_id=?, empresa_id=?, nro_certificado=?, tipo=?, periodo=?, fecha_medicion=?, fecha_firma=?, fecha_requisitos_cumplidos=?, fecha_vencimiento=?, monto_basico=?, monto_redeterminado=?, fri=?, monto_neto=?, monto_bruto=? WHERE id=?");
            $stmt->execute([$obra_id, $empresa_id, $nro_cert, $tipo, $periodo, $f_medicion, $f_firma, $f_requisitos, $f_vencimiento, $monto_basico, $monto_redet, $fri, $monto_neto, $monto_bruto, $id]);
        }

        // -----------------------------------------------------------
        // VINCULACIÓN: ORDENES DE PAGO (TGF)
        // -----------------------------------------------------------
        // 1. Borramos las vinculaciones viejas
        $pdo->prepare("DELETE FROM certificados_ops WHERE certificado_id = ?")->execute([$id]);
        
        // 2. Insertamos las nuevas
        if (isset($_POST['op_numero']) && is_array($_POST['op_numero'])) {
            $stmtOP = $pdo->prepare("INSERT INTO certificados_ops (certificado_id, ejercicio, nro_op, importe_op) VALUES (?,?,?,?)");
            
            for ($i=0; $i < count($_POST['op_numero']); $i++) {
                $num = $_POST['op_numero'][$i];
                $ejer = $_POST['op_ejercicio'][$i];
                $imp = $f($_POST['op_importe'][$i]);
                
                // Solo guardamos si el campo N° OP tiene dato
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
    // 1. Datos del Certificado
    $cert = $pdo->query("SELECT * FROM certificados WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    
    // 2. Órdenes de Pago Vinculadas
    $stmtOps = $pdo->prepare("SELECT * FROM certificados_ops WHERE certificado_id = ?");
    $stmtOps->execute([$id]);
    $ops_vinculadas = $stmtOps->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Facturas Vinculadas
    $stmtFac = $pdo->prepare("SELECT cf.*, ca.numero, ca.punto_venta, ca.importe_total 
                              FROM certificados_facturas cf 
                              JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id 
                              WHERE cf.certificado_id = ?");
    $stmtFac->execute([$id]);
    $facturas_vinculadas = $stmtFac->fetchAll(PDO::FETCH_ASSOC);
}

// Listado de Obras para el Select
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
            <div class="card-header bg-light fw-bold">1. Datos Generales y Fechas</div>
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
                            <i class="bi bi-plus-lg"></i> Vincular
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
                        <?php if(empty($facturas_vinculadas)): ?>
                            <div class="text-center text-muted small fst-italic py-2" id="msgSinFacturasList">Sin facturas</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-cash-coin"></i> Órdenes de Pago (TGF)</span>
                        <button type="button" class="btn btn-sm btn-light text-success" onclick="addOP()">
                            <i class="bi bi-plus-lg"></i> Agregar OP
                        </button>
                    </div>
                    <div class="card-body p-2" id="listaOPs">
                        <?php foreach($ops_vinculadas as $op): ?>
                            <div class="input-group input-group-sm mb-2 item-op">
                                <span class="input-group-text">Año</span>
                                <input type="number" name="op_ejercicio[]" class="form-control" value="<?= $op['ejercicio'] ?>" style="max-width:70px">
                                <span class="input-group-text">N° OP</span>
                                <input type="number" name="op_numero[]" class="form-control fw-bold" value="<?= $op['nro_op'] ?>">
                                <span class="input-group-text">$</span>
                                <input type="text" name="op_importe[]" class="form-control monto text-end" value="<?= number_format($op['importe_op'],2,',','.') ?>">
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.item-op').remove()">X</button>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if(empty($ops_vinculadas)): ?>
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    if(document.querySelectorAll('.item-op').length === 0) addOP();
                                });
                            </script>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 text-end">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save"></i> Guardar Certificado</button>
        </div>
    </form>
</div>

<div class="modal fade" id="modalArca" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Vincular Factura Disponible</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="loadingArca" class="text-center py-3" style="display:none;">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Buscando comprobantes...</p>
                </div>
                <div id="listaFacturasAjax" class="list-group"></div>
                <div id="msgSinFacturas" class="alert alert-warning mt-2" style="display:none;">
                    No se encontraron facturas disponibles para esta empresa.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 1. Al cambiar la obra, actualizamos el ID de empresa oculto
function setEmpresa(sel) {
    let opt = sel.options[sel.selectedIndex];
    let empId = opt.getAttribute('data-emp') || '';
    document.getElementById('empresa_id').value = empId;
    
    // Opcional: Limpiar facturas si cambia la obra para evitar errores de consistencia
    document.getElementById('listaFacturas').innerHTML = ''; 
    // document.getElementById('listaOPs').innerHTML = ''; // Las OP a veces se mantienen
}

// 2. Agregar línea de Orden de Pago (Manual)
function addOP() {
    let div = document.createElement('div');
    div.className = 'input-group input-group-sm mb-2 item-op';
    div.innerHTML = `
        <span class="input-group-text">Año</span>
        <input type="number" name="op_ejercicio[]" class="form-control" value="2025" style="max-width:70px">
        <span class="input-group-text">N° OP</span>
        <input type="number" name="op_numero[]" class="form-control fw-bold" placeholder="Ej: 10500">
        <span class="input-group-text">$</span>
        <input type="text" name="op_importe[]" class="form-control monto text-end" placeholder="0,00">
        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.item-op').remove()">X</button>
    `;
    document.getElementById('listaOPs').appendChild(div);
}

// 3. LOGICA DEL MODAL ARCA
var modalEl = document.getElementById('modalArca');

// Evento al abrir el modal
modalEl.addEventListener('show.bs.modal', function (event) {
    let empresaId = document.getElementById('empresa_id').value;
    
    if(!empresaId) {
        alert("Primero seleccione una Obra (que tenga empresa asociada).");
        event.preventDefault(); // Cancelar apertura
        return;
    }
    cargarFacturasAjax(empresaId);
});

function cargarFacturasAjax(empresaId) {
    let contenedor = document.getElementById('listaFacturasAjax');
    let loading = document.getElementById('loadingArca');
    let msg = document.getElementById('msgSinFacturas');
    
    contenedor.innerHTML = '';
    msg.style.display = 'none';
    loading.style.display = 'block';

    fetch(`data_facturas_ajax.php?empresa_id=${empresaId}`)
        .then(response => response.json())
        .then(data => {
            loading.style.display = 'none';
            if(data.length === 0) {
                msg.style.display = 'block';
                return;
            }
            
            data.forEach(fac => {
                let btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                btn.innerHTML = `
                    <div>
                        <span class="fw-bold">${fac.texto}</span>
                    </div>
                    <span class="badge bg-primary rounded-pill">$ ${fac.monto_fmt}</span>
                `;
                // Pasamos el objeto completo a la función
                btn.onclick = function() { seleccionarFactura(fac); };
                contenedor.appendChild(btn);
            });
        })
        .catch(err => {
            loading.style.display = 'none';
            console.error(err);
            contenedor.innerHTML = '<div class="alert alert-danger">Error al cargar facturas. Asegúrese de que data_facturas_ajax.php exista.</div>';
        });
}

function seleccionarFactura(fac) {
    // 1. Ocultar mensaje de vacío
    let msgVacio = document.getElementById('msgSinFacturasList');
    if(msgVacio) msgVacio.style.display = 'none';

    // 2. Agregar al listado visual del formulario
    let div = document.createElement('div');
    div.className = 'border rounded p-2 mb-1 d-flex justify-content-between align-items-center item-fac bg-light';
    div.innerHTML = `
        <div>
            <i class="bi bi-receipt text-primary"></i> 
            <strong>${fac.texto}</strong>
            <br><small class="text-muted">Monto: $ ${fac.monto_fmt}</small>
        </div>
        <input type="hidden" name="facturas_arca[]" value="${fac.id}">
        <button type="button" class="btn-close btn-sm" onclick="this.closest('.item-fac').remove()"></button>
    `;
    document.getElementById('listaFacturas').appendChild(div);
    
    // 3. CERRAR MODAL (BLINDADO)
    try {
        var myModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalArca'));
        myModal.hide();
    } catch (e) {
        console.error("Fallo cierre bootstrap standard, forzando cierre...", e);
        var modalDom = document.getElementById('modalArca');
        modalDom.classList.remove('show');
        modalDom.style.display = 'none';
        modalDom.setAttribute('aria-hidden', 'true');
        modalDom.removeAttribute('aria-modal');
        modalDom.removeAttribute('role');
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
        document.body.classList.remove('modal-open');
        document.body.style = '';
    }
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
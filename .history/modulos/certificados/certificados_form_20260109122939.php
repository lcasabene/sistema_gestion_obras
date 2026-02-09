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
        // ... (otros descuentos resumidos para el ejemplo)
        $monto_bruto = $monto_basico + $monto_redet + $fri; 
        $monto_neto = $monto_bruto; // Aplicar restas de descuentos aquí si tienes los campos

        // -----------------------------------------------------------
        // LÓGICA DE VENCIMIENTO (Plazo: 60 días)
        // -----------------------------------------------------------
        $plazo = 60;
        $fecha_base = null;

        if ($tipo === 'ORIGINAL' && !empty($periodo)) {
            // Original: 1er día del mes siguiente al periodo
            $dt = DateTime::createFromFormat('Y-m', $periodo);
            if ($dt) {
                $dt->modify('last day of this month'); // Fin de mes
                $dt->modify('+1 day'); // 1er día mes siguiente
                $fecha_base = $dt;
            }
        } elseif ($tipo === 'REDETERMINACION' && !empty($f_firma)) {
            // Redet: Fecha de Firma
            $fecha_base = new DateTime($f_firma);
        }

        // Ajuste por Requisitos Cumplidos (corre el plazo)
        if ($fecha_base && !empty($f_requisitos)) {
            $dtReq = new DateTime($f_requisitos);
            if ($dtReq > $fecha_base) {
                $fecha_base = $dtReq; // El reloj empieza cuando se cumple el requisito
            }
        }

        $f_vencimiento = $fecha_base ? $fecha_base->modify("+$plazo days")->format('Y-m-d') : null;
        // -----------------------------------------------------------

        if ($id == 0) {
            $stmt = $pdo->prepare("INSERT INTO certificados (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion, fecha_firma, fecha_requisitos_cumplidos, fecha_vencimiento, monto_basico, monto_redeterminado, fri, monto_neto) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$obra_id, $empresa_id, $nro_cert, $tipo, $periodo, $f_medicion, $f_firma, $f_requisitos, $f_vencimiento, $monto_basico, $monto_redet, $fri, $monto_neto]);
            $id = $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare("UPDATE certificados SET obra_id=?, empresa_id=?, nro_certificado=?, tipo=?, periodo=?, fecha_medicion=?, fecha_firma=?, fecha_requisitos_cumplidos=?, fecha_vencimiento=?, monto_basico=?, monto_redeterminado=?, fri=?, monto_neto=? WHERE id=?");
            $stmt->execute([$obra_id, $empresa_id, $nro_cert, $tipo, $periodo, $f_medicion, $f_firma, $f_requisitos, $f_vencimiento, $monto_basico, $monto_redet, $fri, $monto_neto, $id]);
        }

        // --- GUARDAR OPs (VINCULACIÓN) ---
        $pdo->prepare("DELETE FROM certificados_ops WHERE certificado_id = ?")->execute([$id]);
        if (isset($_POST['op_numero'])) {
            $stmtOP = $pdo->prepare("INSERT INTO certificados_ops (certificado_id, ejercicio, nro_op, importe_op) VALUES (?,?,?,?)");
            for ($i=0; $i < count($_POST['op_numero']); $i++) {
                if (!empty($_POST['op_numero'][$i])) {
                    $stmtOP->execute([$id, $_POST['op_ejercicio'][$i], $_POST['op_numero'][$i], $f($_POST['op_importe'][$i])]);
                }
            }
        }

        // --- GUARDAR FACTURAS ---
        $pdo->prepare("DELETE FROM certificados_facturas WHERE certificado_id = ?")->execute([$id]);
        if (isset($_POST['facturas_arca'])) {
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

// --- CARGAR DATOS ---
$cert = [];
$ops = [];
$facts = [];
if ($id > 0) {
    $cert = $pdo->query("SELECT * FROM certificados WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $ops = $pdo->query("SELECT * FROM certificados_ops WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $facts = $pdo->query("SELECT cf.*, ca.numero, ca.importe_total FROM certificados_facturas cf JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id WHERE cf.certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
}

$obras = $pdo->query("SELECT id, denominacion, empresa_id FROM obras WHERE activo=1")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container my-4">
    <h3><?= $id > 0 ? 'Editar' : 'Nuevo' ?> Certificado</h3>
    
    <?php if(isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

    <form method="POST" id="formCert">
        <div class="card mb-3 shadow-sm">
            <div class="card-header bg-light fw-bold">Datos Generales y Fechas</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label>Obra</label>
                        <select name="obra_id" class="form-select" onchange="setEmpresa(this)" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach($obras as $o): ?>
                                <option value="<?= $o['id'] ?>" data-emp="<?= $o['empresa_id'] ?>" <?= ($cert['obra_id']??$obra_pre)==$o['id']?'selected':'' ?>><?= htmlspecialchars($o['denominacion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="empresa_id" id="empresa_id" value="<?= $cert['empresa_id']??'' ?>">
                    </div>
                    <div class="col-md-2">
                        <label>Periodo</label>
                        <input type="month" name="periodo" class="form-control" value="<?= $cert['periodo']??'' ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label>N° Cert.</label>
                        <input type="number" name="nro_certificado" class="form-control" value="<?= $cert['nro_certificado']??'' ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label>Tipo</label>
                        <select name="tipo" class="form-select">
                            <option value="ORIGINAL" <?= ($cert['tipo']??'')=='ORIGINAL'?'selected':'' ?>>Original</option>
                            <option value="REDETERMINACION" <?= ($cert['tipo']??'')=='REDETERMINACION'?'selected':'' ?>>Redeterm.</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label>F. Medición</label>
                        <input type="date" name="fecha_medicion" class="form-control" value="<?= $cert['fecha_medicion']??'' ?>">
                    </div>
                    <div class="col-md-3">
                        <label>F. Firma</label>
                        <input type="date" name="fecha_firma" class="form-control" value="<?= $cert['fecha_firma']??'' ?>">
                    </div>
                    <div class="col-md-3">
                        <label title="Ajusta el vencimiento">F. Requisitos Cumpl.</label>
                        <input type="date" name="fecha_requisitos_cumplidos" class="form-control" value="<?= $cert['fecha_requisitos_cumplidos']??'' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="text-danger">Vencimiento (Calc)</label>
                        <input type="date" class="form-control bg-light" value="<?= $cert['fecha_vencimiento']??'' ?>" readonly>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><label>Monto Básico</label><input type="text" name="monto_basico" class="form-control text-end monto" value="<?= number_format($cert['monto_basico']??0,2,',','.') ?>"></div>
                    <div class="col-md-3"><label>Redeterminación</label><input type="text" name="monto_redeterminado" class="form-control text-end monto" value="<?= number_format($cert['monto_redeterminado']??0,2,',','.') ?>"></div>
                    <div class="col-md-3"><label>FRI</label><input type="text" name="fri" class="form-control text-end monto" value="<?= number_format($cert['fri']??0,2,',','.') ?>"></div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-secondary text-white d-flex justify-content-between">
                        <span>Facturas ARCA</span>
                        <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalArca"><i class="bi bi-plus"></i></button>
                    </div>
                    <div class="card-body p-2" id="listaFacturas">
                        <?php foreach($facts as $f): ?>
                            <div class="border rounded p-2 mb-1 d-flex justify-content-between item-fac">
                                <small>Fac: <?= $f['numero'] ?> ($ <?= number_format($f['importe_total'],2,',','.') ?>)</small>
                                <input type="hidden" name="facturas_arca[]" value="<?= $f['comprobante_arca_id'] ?>">
                                <button type="button" class="btn-close btn-sm" onclick="this.closest('.item-fac').remove()"></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-success text-white d-flex justify-content-between">
                        <span>Órdenes de Pago (TGF)</span>
                        <button type="button" class="btn btn-sm btn-light" onclick="addOP()"><i class="bi bi-plus"></i></button>
                    </div>
                    <div class="card-body p-2" id="listaOPs">
                        <?php foreach($ops as $op): ?>
                            <div class="input-group input-group-sm mb-1 item-op">
                                <input type="number" name="op_ejercicio[]" class="form-control" value="<?= $op['ejercicio'] ?>" placeholder="Año" style="max-width:70px">
                                <input type="number" name="op_numero[]" class="form-control" value="<?= $op['nro_op'] ?>" placeholder="N° OP">
                                <input type="text" name="op_importe[]" class="form-control monto" value="<?= number_format($op['importe_op'],2,',','.') ?>">
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.item-op').remove()">X</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3 text-end">
            <button type="submit" class="btn btn-primary btn-lg">Guardar Certificado</button>
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
                <div id="listaFacturasAjax" class="list-group">
                    </div>
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
    
    // Limpiamos facturas si cambia la obra/empresa (opcional)
    // document.getElementById('listaFacturas').innerHTML = '';
}

// 2. Agregar línea de Orden de Pago (Manual)
function addOP() {
    let div = document.createElement('div');
    div.className = 'input-group input-group-sm mb-1 item-op';
    div.innerHTML = `
        <input type="number" name="op_ejercicio[]" class="form-control" value="2025" style="max-width:70px" placeholder="Año">
        <input type="number" name="op_numero[]" class="form-control fw-bold" placeholder="N° OP (Ej: 10025)">
        <input type="text" name="op_importe[]" class="form-control monto text-end" placeholder="0,00">
        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.item-op').remove()">X</button>
    `;
    document.getElementById('listaOPs').appendChild(div);
}

// 3. LOGICA DEL MODAL ARCA
var modalArca = document.getElementById('modalArca');
modalArca.addEventListener('show.bs.modal', function (event) {
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
                btn.onclick = function() { seleccionarFactura(fac); };
                contenedor.appendChild(btn);
            });
        })
        .catch(err => {
            loading.style.display = 'none';
            console.error(err);
            contenedor.innerHTML = '<div class="alert alert-danger">Error al cargar facturas.</div>';
        });
}

function seleccionarFactura(fac) {
    // Agregar al listado visual del formulario
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
    
    // Cerrar modal
    var modalInstance = bootstrap.Modal.getInstance(document.getElementById('modalArca'));
    modalInstance.hide();
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
<script>
function setEmpresa(sel) {
    let opt = sel.options[sel.selectedIndex];
    document.getElementById('empresa_id').value = opt.getAttribute('data-emp') || '';
}
function addOP() {
    let div = document.createElement('div');
    div.className = 'input-group input-group-sm mb-1 item-op';
    div.innerHTML = '<input type="number" name="op_ejercicio[]" class="form-control" value="2025" style="max-width:70px"><input type="number" name="op_numero[]" class="form-control" placeholder="N° OP"><input type="text" name="op_importe[]" class="form-control monto" placeholder="Monto"><button type="button" class="btn btn-outline-danger" onclick="this.closest(\'.item-op\').remove()">X</button>';
    document.getElementById('listaOPs').appendChild(div);
}
</script>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
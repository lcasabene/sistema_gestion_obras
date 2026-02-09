<?php
// modulos/certificados/certificados_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;
$obraId = $_GET['obra_id'] ?? 0; // Si venimos pre-seleccionados

// --- LÓGICA DE GUARDADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Limpieza de números
        $f = function($v){ return (float)str_replace(',','.', str_replace('.','',$v)); };
        
        $montoBasico = $f($_POST['monto_basico']);
        $montoRedet = 0; // Se calcula sumando items
        
        // 1. Guardar Cabecera
        $sql = "INSERT INTO certificados (obra_id, empresa_id, nro_certificado, periodo, fecha_medicion, 
                monto_basico, fondo_reparo_pct, fondo_reparo_monto, anticipo_descuento, multas_monto, 
                monto_neto_pagar, avance_fisico_mensual, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'BORRADOR')
                ON DUPLICATE KEY UPDATE 
                periodo=?, fecha_medicion=?, monto_basico=?, fondo_reparo_pct=?, fondo_reparo_monto=?, 
                anticipo_descuento=?, multas_monto=?, monto_neto_pagar=?, avance_fisico_mensual=?";
        
        $params = [
            $_POST['obra_id'], $_POST['empresa_id'], $_POST['nro_certificado'], $_POST['periodo'], $_POST['fecha_medicion'],
            $montoBasico, $_POST['fondo_reparo_pct'], $f($_POST['fondo_reparo_monto']), $f($_POST['anticipo_descuento']), 
            $f($_POST['multas_monto']), $f($_POST['monto_neto_pagar']), $_POST['avance_fisico_mensual']
        ];
        // Duplicamos params para el UPDATE (truco simple)
        $paramsUpdate = array_slice($params, 3); 
        
        if ($id > 0) {
            // Es Update Directo
            $pdo->prepare("UPDATE certificados SET obra_id=?, empresa_id=?, nro_certificado=?, periodo=?, fecha_medicion=?, monto_basico=?, fondo_reparo_pct=?, fondo_reparo_monto=?, anticipo_descuento=?, multas_monto=?, monto_neto_pagar=?, avance_fisico_mensual=? WHERE id=?")
                ->execute(array_merge($params, [$id]));
        } else {
            // Es Insert
            $stmt = $pdo->prepare("INSERT INTO certificados (obra_id, empresa_id, nro_certificado, periodo, fecha_medicion, monto_basico, fondo_reparo_pct, fondo_reparo_monto, anticipo_descuento, multas_monto, monto_neto_pagar, avance_fisico_mensual) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute($params);
            $id = $pdo->lastInsertId();
        }

        // 2. Guardar Redeterminaciones (Items)
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
        // Actualizar monto total bruto (Básico + Redet)
        $pdo->prepare("UPDATE certificados SET monto_redeterminaciones=?, monto_bruto=? WHERE id=?")
            ->execute([$montoRedet, ($montoBasico + $montoRedet), $id]);

        // 3. Guardar Fuentes (Pari Passu)
        $pdo->prepare("DELETE FROM certificados_financiamiento WHERE certificado_id = ?")->execute([$id]);
        if (isset($_POST['fuente_id'])) {
            $stmtFu = $pdo->prepare("INSERT INTO certificados_financiamiento (certificado_id, fuente_id, porcentaje, monto_asignado) VALUES (?, ?, ?, ?)");
            foreach ($_POST['fuente_id'] as $k => $fid) {
                $stmtFu->execute([$id, $fid, $_POST['fuente_pct'][$k], $f($_POST['fuente_monto'][$k])]);
            }
        }

        // 4. Guardar Facturas ARCA
        $pdo->prepare("DELETE FROM certificados_facturas WHERE certificado_id = ?")->execute([$id]);
        // Liberar facturas viejas
        // (Lógica pendiente: marcar estado_uso='DISPONIBLE' en arca si se desvincula)
        
        if (isset($_POST['facturas_arca'])) {
            $stmtFac = $pdo->prepare("INSERT INTO certificados_facturas (certificado_id, comprobante_arca_id) VALUES (?, ?)");
            foreach ($_POST['facturas_arca'] as $fid) {
                $stmtFac->execute([$id, $fid]);
                // Marcar como usada
                $pdo->prepare("UPDATE comprobantes_arca SET estado_uso='VINCULADO' WHERE id=?")->execute([$fid]);
            }
        }

        $pdo->commit();
        header("Location: certificados_listado.php"); exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// --- CARGA DE DATOS ---
$cert = [
    'obra_id'=>'', 'empresa_id'=>'', 'nro_certificado'=>'1', 'periodo'=>date('Y-m'),
    'monto_basico'=>0, 'fondo_reparo_pct'=>5, 'fondo_reparo_monto'=>0,
    'anticipo_descuento'=>0, 'multas_monto'=>0, 'monto_neto_pagar'=>0, 'avance_fisico_mensual'=>0
];
$itemsRedet = [];
$itemsFuentes = [];
$itemsFacturas = [];

if ($id > 0) {
    $cert = $pdo->query("SELECT * FROM certificados WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $itemsRedet = $pdo->query("SELECT * FROM certificados_items WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $itemsFuentes = $pdo->query("SELECT * FROM certificados_financiamiento WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $itemsFacturas = $pdo->query("SELECT c.*, a.numero, a.importe_total FROM certificados_facturas c JOIN comprobantes_arca a ON c.comprobante_arca_id = a.id WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
}

// Listas
$obras = $pdo->query("SELECT id, denominacion FROM obras WHERE activo=1")->fetchAll();
$empresas = $pdo->query("SELECT id, razon_social, cuit FROM empresas WHERE activo=1")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<form method="POST" id="formCert">
<div class="container my-4">
    
    <div class="d-flex justify-content-between mb-3">
        <h3><?= $id>0 ? 'Editar Certificado' : 'Nuevo Certificado' ?></h3>
        <a href="certificados_listado.php" class="btn btn-secondary">Volver</a>
    </div>

    <div class="card mb-3 shadow-sm">
        <div class="card-header bg-primary text-white">1. Datos Generales</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label>Obra</label>
                    <select name="obra_id" id="selectObra" class="form-select" required onchange="cargarConfigObra(this.value)">
                        <option value="">Seleccione...</option>
                        <?php foreach($obras as $o): ?>
                            <option value="<?= $o['id'] ?>" <?= $cert['obra_id']==$o['id']?'selected':'' ?>><?= $o['denominacion'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>Empresa Contratista</label>
                    <select name="empresa_id" id="selectEmpresa" class="form-select" required>
                        <?php foreach($empresas as $e): ?>
                            <option value="<?= $e['id'] ?>" data-cuit="<?= $e['cuit'] ?>" <?= $cert['empresa_id']==$e['id']?'selected':'' ?>>
                                <?= $e['razon_social'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Nº Certificado</label>
                    <input type="number" name="nro_certificado" class="form-control fw-bold" value="<?= $cert['nro_certificado'] ?>" required>
                </div>
                <div class="col-md-3">
                    <label>Periodo (Mes/Año)</label>
                    <input type="month" name="periodo" class="form-control" value="<?= $cert['periodo'] ?>" required>
                </div>
                <div class="col-md-3">
                    <label>Fecha Medición</label>
                    <input type="date" name="fecha_medicion" class="form-control" value="<?= $cert['fecha_medicion'] ?? date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <label>Avance Físico (%)</label>
                    <div class="input-group">
                        <input type="number" name="avance_fisico_mensual" step="0.01" class="form-control fw-bold" value="<?= $cert['avance_fisico_mensual'] ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3 h-100 shadow-sm border-success">
                <div class="card-header bg-success text-white d-flex justify-content-between">
                    <span>2. Montos del Certificado</span>
                    <small>Valores Brutos</small>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="fw-bold">Monto Básico (Origen)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="text" name="monto_basico" id="montoBasico" class="form-control monto text-end fs-5" 
                                   value="<?= number_format($cert['monto_basico'],2,',','.') ?>" oninput="calcTotales()">
                        </div>
                    </div>
                    
                    <label class="fw-bold mb-2">Redeterminaciones</label>
                    <div id="listaRedet">
                        <?php foreach($itemsRedet as $ir): ?>
                        <div class="input-group mb-2 item-redet">
                            <input type="text" name="redet_concepto[]" class="form-control" value="<?= $ir['concepto'] ?>">
                            <span class="input-group-text">$</span>
                            <input type="text" name="redet_monto[]" class="form-control monto-redet text-end" 
                                   value="<?= number_format($ir['monto'],2,',','.') ?>" oninput="calcTotales()">
                            <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove(); calcTotales()">X</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-success mb-3" onclick="agregarRedet()">+ Agregar Redet.</button>

                    <div class="alert alert-secondary py-2 d-flex justify-content-between fw-bold">
                        <span>TOTAL BRUTO:</span>
                        <span id="txtBruto">$ 0,00</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-3 h-100 shadow-sm border-warning">
                <div class="card-header bg-warning text-dark">3. Deducciones y Neto</div>
                <div class="card-body bg-light">
                    <div class="row mb-2 align-items-center">
                        <div class="col-5">
                            <label class="small text-muted">Fondo Reparo</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="fondo_reparo_pct" id="frPct" class="form-control" value="<?= $cert['fondo_reparo_pct'] ?>" onchange="calcTotales()">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-7">
                            <div class="input-group">
                                <span class="input-group-text text-danger">$</span>
                                <input type="text" name="fondo_reparo_monto" id="frMonto" class="form-control text-end text-danger fw-bold monto" 
                                       value="<?= number_format($cert['fondo_reparo_monto'],2,',','.') ?>" oninput="calcNeto()">
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="small text-muted">Devolución Anticipo</label>
                        <div class="input-group">
                            <span class="input-group-text text-danger">$</span>
                            <input type="text" name="anticipo_descuento" id="antMonto" class="form-control text-end text-danger fw-bold monto" 
                                   value="<?= number_format($cert['anticipo_descuento'],2,',','.') ?>" oninput="calcNeto()">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="small text-muted">Multas / Otros</label>
                        <div class="input-group">
                            <span class="input-group-text text-danger">$</span>
                            <input type="text" name="multas_monto" id="mulMonto" class="form-control text-end text-danger fw-bold monto" 
                                   value="<?= number_format($cert['multas_monto'],2,',','.') ?>" oninput="calcNeto()">
                        </div>
                    </div>

                    <div class="card bg-white border-primary">
                        <div class="card-body py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-primary">A PAGAR (NETO):</span>
                            <input type="hidden" name="monto_neto_pagar" id="inputNetoHidden" value="<?= $cert['monto_neto_pagar'] ?>">
                            <span id="txtNeto" class="fs-4 fw-bold text-primary">$ 0,00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="card mb-3 shadow-sm">
                <div class="card-header fw-bold">4. Financiamiento (Pari Passu)</div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead class="table-light"><tr><th>Fuente</th><th>%</th><th>Monto</th></tr></thead>
                        <tbody id="bodyFuentes">
                            </tbody>
                    </table>
                    <small class="text-muted fst-italic">* Los montos se calculan automáticamente sobre el Neto.</small>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card mb-3 shadow-sm border-info">
                <div class="card-header bg-info text-white d-flex justify-content-between">
                    <span>5. Validación ARCA</span>
                    <button type="button" class="btn btn-sm btn-light text-info fw-bold" onclick="abrirModalArca()">Buscar Factura</button>
                </div>
                <div class="card-body">
                    <div id="listaFacturas">
                        <?php foreach($itemsFacturas as $if): ?>
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span>Factura <?= $if['numero'] ?></span>
                                <span class="fw-bold">$ <?= number_format($if['importe_total'],2,',','.') ?></span>
                                <input type="hidden" name="facturas_arca[]" value="<?= $if['comprobante_arca_id'] ?>">
                                <input type="hidden" class="monto-arca" value="<?= $if['importe_total'] ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-2 text-end">
                        <span class="small text-muted">Total Facturas: </span>
                        <span id="totalArca" class="fw-bold">$ 0,00</span>
                    </div>
                    <div id="alertaArca" class="alert alert-warning mt-2 d-none small py-1">
                        <i class="bi bi-exclamation-triangle"></i> Diferencia con Monto Bruto.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg w-100 shadow">Guardar Certificado</button>
</div>
</form>

<div class="modal fade" id="modalArca" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white"><h5 class="modal-title">Facturas Disponibles</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div id="loaderArca" class="text-center my-3"><div class="spinner-border text-info"></div></div>
                <div id="contenidoArca"></div>
            </div>
        </div>
    </div>
</div>

<script>
// --- LÓGICA JS ---
function agregarRedet() {
    const div = document.createElement('div');
    div.className = 'input-group mb-2 item-redet';
    div.innerHTML = `<input type="text" name="redet_concepto[]" class="form-control" placeholder="Concepto">
                     <span class="input-group-text">$</span>
                     <input type="text" name="redet_monto[]" class="form-control monto-redet text-end" value="0,00" oninput="calcTotales()">
                     <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove(); calcTotales()">X</button>`;
    document.getElementById('listaRedet').appendChild(div);
}

function parseM(v) { return parseFloat(v.replace(/\./g,'').replace(',','.')) || 0; }
function fmtM(v) { return v.toLocaleString('es-AR', {minimumFractionDigits: 2}); }

function calcTotales() {
    let basico = parseM(document.getElementById('montoBasico').value);
    let redet = 0;
    document.querySelectorAll('.monto-redet').forEach(el => redet += parseM(el.value));
    
    let bruto = basico + redet;
    document.getElementById('txtBruto').innerText = '$ ' + fmtM(bruto);
    
    // Auto calc Fondo Reparo
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
    let mul = parseM(document.getElementById('mulMonto').value);
    
    let neto = brutoIn - fr - ant - mul;
    document.getElementById('txtNeto').innerText = '$ ' + fmtM(neto);
    document.getElementById('inputNetoHidden').value = neto; // Guardar float puro o fmt? Mejor fmt en DB mysql espera decimal .
    document.getElementById('inputNetoHidden').value = neto.toFixed(2); // Para el POST

    distribuirFuentes(neto);
    validarArca(brutoIn);
}

// --- PARI PASSU ---
let configFuentes = []; // Se cargará vía AJAX al elegir obra

function cargarConfigObra(id) {
    if(!id) return;
    // Simulación AJAX (En producción crear un endpoint que devuelva JSON con las fuentes de la obra)
    // Aquí asumimos que ya las tenemos o hacemos fetch
    // Para simplificar ahora, haré un fetch rápido
    fetch('api_get_fuentes.php?obra_id=' + id)
        .then(r => r.json())
        .then(data => {
            configFuentes = data;
            calcTotales(); // Recalcula distribución
        });
}

function distribuirFuentes(neto) {
    const tbody = document.getElementById('bodyFuentes');
    tbody.innerHTML = '';
    configFuentes.forEach((f, idx) => {
        let montoF = neto * (f.porcentaje / 100);
        tbody.innerHTML += `
            <tr>
                <td>${f.nombre}</td>
                <td>
                    <input type="hidden" name="fuente_id[]" value="${f.fuente_id}">
                    <input type="number" name="fuente_pct[]" class="form-control form-control-sm" value="${f.porcentaje}" readonly>
                </td>
                <td>
                    <input type="text" name="fuente_monto[]" class="form-control form-control-sm text-end" value="${fmtM(montoF)}">
                </td>
            </tr>`;
    });
}

// --- ARCA ---
function abrirModalArca() {
    let cuit = document.getElementById('selectEmpresa').options[document.getElementById('selectEmpresa').selectedIndex].dataset.cuit;
    if(!cuit) { alert('Seleccione una empresa primero'); return; }
    
    var myModal = new bootstrap.Modal(document.getElementById('modalArca'));
    myModal.show();
    
    // Cargar facturas disponibles vía AJAX
    document.getElementById('contenidoArca').innerHTML = 'Cargando...';
    fetch('api_get_facturas.php?cuit=' + cuit)
        .then(r => r.text())
        .then(html => document.getElementById('contenidoArca').innerHTML = html);
}

function seleccionarFactura(id, numero, monto) {
    // Agregar al listado visual
    let div = document.createElement('div');
    div.className = 'd-flex justify-content-between border-bottom py-1';
    div.innerHTML = `<span>Factura ${numero}</span> <span class="fw-bold">$ ${fmtM(parseFloat(monto))}</span>
                     <input type="hidden" name="facturas_arca[]" value="${id}">
                     <input type="hidden" class="monto-arca" value="${monto}">`;
    document.getElementById('listaFacturas').appendChild(div);
    bootstrap.Modal.getInstance(document.getElementById('modalArca')).hide();
    validarArca();
}

function validarArca(bruto = null) {
    if(bruto === null) {
        let basico = parseM(document.getElementById('montoBasico').value);
        let redet = 0;
        document.querySelectorAll('.monto-redet').forEach(el => redet += parseM(el.value));
        bruto = basico + redet;
    }
    
    let totalArca = 0;
    document.querySelectorAll('.monto-arca').forEach(el => totalArca += parseFloat(el.value));
    
    document.getElementById('totalArca').innerText = '$ ' + fmtM(totalArca);
    
    // Tolerancia de $10 por redondeo
    if (Math.abs(bruto - totalArca) > 10 && totalArca > 0) {
        document.getElementById('alertaArca').classList.remove('d-none');
        document.getElementById('alertaArca').innerText = `Diferencia de $ ${fmtM(bruto - totalArca)} con el Bruto`;
    } else {
        document.getElementById('alertaArca').classList.add('d-none');
    }
}

// Inicialización
document.addEventListener('DOMContentLoaded', function() {
    calcTotales();
    // Si es edición, cargar config fuentes
    let oid = document.getElementById('selectObra').value;
    if(oid) cargarConfigObra(oid);
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
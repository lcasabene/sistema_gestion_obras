<?php
// modulos/certificados/certificados_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = $_GET['id'] ?? 0;
$obra_preseleccionada = $_GET['obra_id'] ?? 0;

// --- LÓGICA DE GUARDADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $f = function($v){ return (float)str_replace(',','.', str_replace('.','',$v)); };
        
        $montoBasico = $f($_POST['monto_basico']);
        $montoRedet = 0; // Se calculará abajo
        
        // 1. Guardar Cabecera (Agregamos campo 'tipo')
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
        
        // Ejecución (Truco para ON DUPLICATE KEY: repetir params)
        $paramsFull = array_merge($params, $params);
        
        if ($id > 0) {
            // Update específico si tenemos ID
            $sqlUpdate = "UPDATE certificados SET obra_id=?, empresa_id=?, nro_certificado=?, tipo=?, periodo=?, fecha_medicion=?, 
                          monto_basico=?, fondo_reparo_pct=?, fondo_reparo_monto=?, anticipo_descuento=?, multas_monto=?, 
                          monto_neto_pagar=?, avance_fisico_mensual=? WHERE id=?";
            $pdo->prepare($sqlUpdate)->execute(array_merge($params, [$id]));
        } else {
            // Insert nuevo
            $stmt = $pdo->prepare("INSERT INTO certificados (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion, monto_basico, fondo_reparo_pct, fondo_reparo_monto, anticipo_descuento, multas_monto, monto_neto_pagar, avance_fisico_mensual, estado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'BORRADOR')");
            $stmt->execute($params);
            $id = $pdo->lastInsertId();
        }

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
        
        // Actualizar Totales
        $pdo->prepare("UPDATE certificados SET monto_redeterminaciones=?, monto_bruto=? WHERE id=?")
            ->execute([$montoRedet, ($montoBasico + $montoRedet), $id]);

        // 3. Fuentes Financiamiento
        $pdo->prepare("DELETE FROM certificados_financiamiento WHERE certificado_id = ?")->execute([$id]);
        if (isset($_POST['fuente_id'])) {
            $stmtFu = $pdo->prepare("INSERT INTO certificados_financiamiento (certificado_id, fuente_id, porcentaje, monto_asignado) VALUES (?, ?, ?, ?)");
            foreach ($_POST['fuente_id'] as $k => $fid) {
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
        // Volver al listado de certificados de ESA obra si es posible, sino al general
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

if ($id > 0) {
    $cert = $pdo->query("SELECT * FROM certificados WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $itemsRedet = $pdo->query("SELECT * FROM certificados_items WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $itemsFuentes = $pdo->query("SELECT * FROM certificados_financiamiento WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
    $itemsFacturas = $pdo->query("SELECT c.*, a.numero, a.importe_total FROM certificados_facturas c JOIN comprobantes_arca a ON c.comprobante_arca_id = a.id WHERE certificado_id=$id")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($obra_preseleccionada > 0) {
    // Lógica inteligente: Si es nuevo y hay obra, buscar el último certificado para sugerir el número siguiente
    $stmtUltimo = $pdo->prepare("SELECT nro_certificado, empresa_id FROM certificados WHERE obra_id = ? ORDER BY nro_certificado DESC LIMIT 1");
    $stmtUltimo->execute([$obra_preseleccionada]);
    $ultimo = $stmtUltimo->fetch();
    if($ultimo) {
        $cert['nro_certificado'] = $ultimo['nro_certificado'] + 1;
        $cert['empresa_id'] = $ultimo['empresa_id'];
    } else {
        // Si no hay certificados previos, buscamos la empresa en la tabla obras
        $stmtObra = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
        $stmtObra->execute([$obra_preseleccionada]);
        $resObra = $stmtObra->fetch();
        if($resObra) $cert['empresa_id'] = $resObra['empresa_id'];
    }
}

// Listas
$obras = $pdo->query("SELECT id, denominacion, empresa_id FROM obras WHERE activo=1")->fetchAll();
$empresas = $pdo->query("SELECT id, razon_social, cuit FROM empresas WHERE activo=1")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<?php if(isset($error)): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<form method="POST" id="formCert">
<div class="container my-4">
    
    <div class="d-flex justify-content-between mb-3 align-items-center">
        <div>
            <h3 class="mb-0"><?= $id>0 ? 'Editar Certificado' : 'Nuevo Certificado' ?></h3>
            <span class="text-muted small">Carga rápida de avances y facturación</span>
        </div>
        <a href="certificados_listado.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <div class="card mb-4 shadow-sm border-top border-4 border-primary">
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
                        <select name="obra_id" id="selectObra" class="form-select" required onchange="autoSeleccionarEmpresa(this)">
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
                    <label class="form-label fw-bold text-primary">Tipo Certificado</label>
                    <select name="tipo" class="form-select border-primary fw-bold" onchange="ajustarFormularioPorTipo(this.value)">
                        <option value="ORDINARIO" <?= $cert['tipo']=='ORDINARIO'?'selected':'' ?>>ORDINARIO (Mensual)</option>
                        <option value="ANTICIPO" <?= $cert['tipo']=='ANTICIPO'?'selected':'' ?>>ANTICIPO FINANCIERO</option>
                        <option value="REDETERMINACION" <?= $cert['tipo']=='REDETERMINACION'?'selected':'' ?>>REDETERMINACIÓN DE PRECIOS</option>
                        <option value="ADICIONAL" <?= $cert['tipo']=='ADICIONAL'?'selected':'' ?>>ADICIONAL DE OBRA</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Nº Certif.</label>
                    <input type="number" name="nro_certificado" class="form-control fw-bold" value="<?= $cert['nro_certificado'] ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Periodo</label>
                    <input type="month" name="periodo" class="form-control" value="<?= $cert['periodo'] ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha Medición</label>
                    <input type="date" name="fecha_medicion" class="form-control" value="<?= $cert['fecha_medicion'] ?? date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Avance Físico Mensual</label>
                    <div class="input-group">
                        <input type="number" name="avance_fisico_mensual" step="0.01" class="form-control fw-bold" value="<?= $cert['avance_fisico_mensual'] ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="card mb-3 shadow-sm h-100">
                <div class="card-header bg-light fw-bold">Detalle de Montos</div>
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
                            <label class="fw-bold mb-2 small text-uppercase text-muted">Redeterminaciones / Adicionales</label>
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
                                <i class="bi bi-plus-circle"></i> Agregar Item
                            </button>
                        </div>
                    </div>

                    <h6 class="text-muted mt-4 border-bottom pb-2">Deducciones</h6>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="small">Fondo Reparo (% variable)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="fondo_reparo_pct" id="frPct" class="form-control" value="<?= $cert['fondo_reparo_pct'] ?>" onchange="calcTotales()">
                                <span class="input-group-text">%</span>
                                <input type="text" name="fondo_reparo_monto" id="frMonto" class="form-control text-end text-danger monto" readonly value="<?= number_format($cert['fondo_reparo_monto'],2,',','.') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small">Devolución Anticipo</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text text-danger">$</span>
                                <input type="text" name="anticipo_descuento" id="antMonto" class="form-control text-end text-danger monto" 
                                       value="<?= number_format($cert['anticipo_descuento'],2,',','.') ?>" oninput="calcNeto()">
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-primary d-flex justify-content-between align-items-center mt-3 mb-0">
                        <span class="fw-bold">A PAGAR (NETO):</span>
                        <input type="hidden" name="monto_neto_pagar" id="inputNetoHidden" value="<?= $cert['monto_neto_pagar'] ?>">
                        <span id="txtNeto" class="fs-3 fw-bold">$ 0,00</span>
                    </div>

                    <div class="mt-3">
                        <small class="text-muted d-block mb-1">Imputación por Fuente (Automático)</small>
                        <div id="bodyFuentes" class="bg-light p-2 rounded small"></div>
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
                                    <small class="text-muted">Importado de AFIP</small>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-success">$ <?= number_format($if['importe_total'],2,',','.') ?></div>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 text-decoration-none" onclick="removerFactura(this)">Desvincular</button>
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
                            <i class="bi bi-exclamation-triangle-fill"></i> Los montos no coinciden
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
                <h5 class="modal-title">Seleccionar Comprobantes Disponibles</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 bg-light border-bottom">
                    <small class="text-muted">Mostrando facturas importadas de AFIP (Mis Comprobantes) pendientes de uso para este CUIT.</small>
                </div>
                <div id="contenidoArca" class="p-3"></div>
            </div>
        </div>
    </div>
</div>

<script>
// --- JS LOGIC ---
let configFuentes = []; 

function autoSeleccionarEmpresa(select) {
    let opt = select.options[select.selectedIndex];
    let empId = opt.getAttribute('data-empid');
    
    // Auto seleccionar empresa
    if(empId) {
        document.getElementById('selectEmpresa').value = empId;
        // Trigger para actualizar data (si usáramos select2)
    }
    cargarConfigObra(select.value);
}

function cargarConfigObra(id) {
    if(!id) return;
    fetch('api_get_fuentes.php?obra_id=' + id)
        .then(r => r.json())
        .then(data => {
            configFuentes = data;
            calcTotales();
        });
}

function agregarRedet() {
    const div = document.createElement('div');
    div.className = 'input-group mb-2 item-redet';
    div.innerHTML = `<input type="text" name="redet_concepto[]" class="form-control form-control-sm" placeholder="Concepto (ej: Acta Acuerdo)">
                     <span class="input-group-text py-0">$</span>
                     <input type="text" name="redet_monto[]" class="form-control form-control-sm monto-redet text-end" value="0,00" oninput="calcTotales()">
                     <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.parentElement.remove(); calcTotales()">&times;</button>`;
    document.getElementById('listaRedet').appendChild(div);
}

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
    // Podríamos agregar multas aquí si habilitas el input
    
    let neto = brutoIn - fr - ant;
    if(neto < 0) neto = 0;

    document.getElementById('txtNeto').innerText = '$ ' + fmtM(neto);
    document.getElementById('inputNetoHidden').value = neto.toFixed(2);

    distribuirFuentes(neto);
    validarArca(brutoIn); // Ojo: A veces se valida contra Neto o Bruto según normativa. Usualmente Factura = Bruto (si es factura C/B) o Neto+IVA. Aquí simplificamos contra Neto a Pagar.
}

function distribuirFuentes(neto) {
    const div = document.getElementById('bodyFuentes');
    div.innerHTML = '';
    if(configFuentes.length === 0) {
        div.innerHTML = '<span class="text-muted fst-italic">Sin configuración de fuentes para esta obra.</span>';
        return;
    }
    configFuentes.forEach(f => {
        let montoF = neto * (f.porcentaje / 100);
        div.innerHTML += `
            <div class="d-flex justify-content-between border-bottom border-secondary-subtle py-1">
                <span>${f.nombre} <small class="text-muted">(${f.porcentaje}%)</small></span>
                <span class="fw-bold">$ ${fmtM(montoF)}</span>
                <input type="hidden" name="fuente_id[]" value="${f.fuente_id}">
                <input type="hidden" name="fuente_pct[]" value="${f.porcentaje}">
                <input type="hidden" name="fuente_monto[]" value="${fmtM(montoF)}">
            </div>`;
    });
}

// ARCA Y FACTURAS
function abrirModalArca() {
    let selectEmp = document.getElementById('selectEmpresa');
    let cuit = selectEmp.options[selectEmp.selectedIndex].getAttribute('data-cuit');
    
    if(!cuit) { alert('La empresa seleccionada no tiene CUIT asociado o no se ha seleccionado empresa.'); return; }
    
    var myModal = new bootstrap.Modal(document.getElementById('modalArca'));
    myModal.show();
    
    document.getElementById('contenidoArca').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-info"></div><p class="mt-2">Consultando facturas disponibles...</p></div>';
    
    fetch('api_get_facturas.php?cuit=' + cuit)
        .then(r => r.text())
        .then(html => document.getElementById('contenidoArca').innerHTML = html);
}

function seleccionarFactura(id, numero, monto) {
    let divLista = document.getElementById('listaFacturas');
    let emptyMsg = document.getElementById('emptyArca');
    if(emptyMsg) emptyMsg.classList.add('d-none');

    // Evitar duplicados visuales
    if(divLista.innerHTML.includes('value="'+id+'"')) return;

    let card = document.createElement('div');
    card.className = 'card border-0 shadow-sm p-2 d-flex flex-row justify-content-between align-items-center item-factura mb-2';
    card.innerHTML = `
        <div>
            <div class="fw-bold text-dark">Factura ${numero}</div>
            <small class="text-muted">Vinculada ahora</small>
        </div>
        <div class="text-end">
            <div class="fw-bold text-success">$ ${fmtM(parseFloat(monto))}</div>
            <button type="button" class="btn btn-link btn-sm text-danger p-0 text-decoration-none" onclick="removerFactura(this)">Desvincular</button>
        </div>
        <input type="hidden" name="facturas_arca[]" value="${id}">
        <input type="hidden" class="monto-arca" value="${monto}">
    `;
    divLista.appendChild(card);
    
    bootstrap.Modal.getInstance(document.getElementById('modalArca')).hide();
    validarArca();
}

function removerFactura(btn) {
    btn.closest('.item-factura').remove();
    validarArca();
}

function validarArca(referencia = null) {
    // Calculamos total facturas
    let totalArca = 0;
    document.querySelectorAll('.monto-arca').forEach(el => totalArca += parseFloat(el.value));
    document.getElementById('totalArca').innerText = '$ ' + fmtM(totalArca);

    // Comparar contra el Neto (o Bruto, depende tu lógica contable).
    // Usualmente se factura lo que se cobra (Neto de bolsillo) o Bruto. 
    // Aquí comparo con Neto para dar la alerta.
    let neto = parseM(document.getElementById('txtNeto').innerText.replace('$ ',''));
    
    let diff = Math.abs(neto - totalArca);
    let alerta = document.getElementById('alertaArca');
    
    if (diff > 100 && totalArca > 0) { // Tolerancia $100
        alerta.classList.remove('d-none');
        alerta.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> Diferencia de $ ${fmtM(neto - totalArca)} respecto al Neto`;
    } else {
        alerta.classList.add('d-none');
    }
}

// Inicialización
document.addEventListener('DOMContentLoaded', function() {
    // Detectar si hay obra preseleccionada y cargar sus datos
    let oid = document.getElementById('selectObra').value;
    if(oid) {
        let sel = document.getElementById('selectObra');
        // Si es un input hidden (preseleccionado), buscamos el valor directo
        if(sel.tagName === 'INPUT') cargarConfigObra(sel.value);
        else autoSeleccionarEmpresa(sel); // Si es select
    }
    
    // Formateadores de input
    document.querySelectorAll('.monto').forEach(el => {
        el.addEventListener('blur', function() { this.value = fmtM(parseM(this.value)); });
        el.addEventListener('focus', function() { this.value = parseM(this.value); }); // Quitar formato al editar
    });
    
    calcTotales();
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
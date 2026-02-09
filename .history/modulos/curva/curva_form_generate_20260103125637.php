<?php
// curva_form_generate.php
session_start();
require_once __DIR__ . '/../../config/database.php';

$obraId = $_GET['obra_id'] ?? 0;
$mensaje = '';
$tipoMensaje = '';

// 1. Obtener datos Obra
$stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
$stmt->execute([$obraId]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$obra) die("Obra no encontrada");

// 2. Cargar Índices Mensuales
$stmtInd = $pdo->query("SELECT anio, mes, porcentaje FROM indices_mensuales ORDER BY anio ASC, mes ASC");
$indicesRaw = $stmtInd->fetchAll(PDO::FETCH_ASSOC);
$indicesMap = [];
foreach ($indicesRaw as $r) {
    $periodo = sprintf("%04d-%02d", $r['anio'], $r['mes']);
    $indicesMap[$periodo] = (float)$r['porcentaje'];
}

// 3. Cargar Vedas Invernales (Eventos activos)
$sqlVedas = "SELECT fecha, fecha_fin FROM obras_eventos 
             WHERE obra_id = ? AND tipo_evento = 'VEDA_INVERNAL' AND activo = 1";
$stmtVedas = $pdo->prepare($sqlVedas);
$stmtVedas->execute([$obraId]);
$vedasRaw = $stmtVedas->fetchAll(PDO::FETCH_ASSOC);
$vedasJson = json_encode($vedasRaw); 

// 4. GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_curva'])) {
    try {
        $pdo->beginTransaction();

        // Limpieza monto contrato visual
        $montoClean = str_replace(['$',' '], '', $_POST['monto_contrato']); 
        $montoClean = str_replace('.', '', $montoClean); 
        $montoClean = str_replace(',', '.', $montoClean); 
        
        $plazo = (int)$_POST['plazo'];
        
        // Cabecera
        $sqlVer = "INSERT INTO curva_version (obra_id, fecha_creacion, observacion, es_vigente, monto_presupuesto, plazo_meses, mes_base) 
                   VALUES (:obra, NOW(), 'Generada Manualmente', 1, :monto, :plazo, :mesbase)";
        $stmtVer = $pdo->prepare($sqlVer);
        $stmtVer->execute([
            ':obra'    => $obraId, 
            ':monto'   => $montoClean, 
            ':plazo'   => $plazo,
            ':mesbase' => $_POST['mes_base']
        ]);
        $versionId = $pdo->lastInsertId();

        // Desactivar anteriores
        $pdo->prepare("UPDATE curva_version SET es_vigente = 0 WHERE obra_id = ? AND id != ?")->execute([$obraId, $versionId]);

        // Items
        $sqlItem = "INSERT INTO curva_items 
                    (version_id, periodo, concepto, porcentaje_fisico, monto_base, indice_inflacion, fri, redeterminacion, recupero, neto) 
                    VALUES (:vid, :per, :conc, :pct, :base, :infl, :fri, :redet, :recup, :neto)";
        $stmtItem = $pdo->prepare($sqlItem);

        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $stmtItem->execute([
                    ':vid'   => $versionId,
                    ':per'   => $item['fecha'],
                    ':conc'  => $item['concepto'] ?? '',
                    ':pct'   => (float)$item['pct_hidden'],
                    ':base'  => (float)$item['bruto_hidden'],
                    ':infl'  => (float)$item['infl_hidden'],
                    ':fri'   => (float)$item['fri_hidden'],
                    ':redet' => (float)$item['redet_hidden'],
                    ':recup' => (float)$item['recupero_hidden'],
                    ':neto'  => (float)$item['neto_hidden']
                ]);
            }
        }

        $pdo->commit();
        $mensaje = "Curva guardada correctamente (V.$versionId)";
        $tipoMensaje = "alert-success";

    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "Error: " . $e->getMessage();
        $tipoMensaje = "alert-danger";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Curva</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .table-input { 
            width: 100%; border: none; background: transparent; 
            text-align: right; font-family: 'Consolas', monospace; font-weight: 600;
            color: #495057;
        }
        .table-input:focus { background: #fff; outline: 2px solid #86b7fe; color: #000; }
        .bg-readonly { background-color: #f8f9fa; }
        .input-pct { color: #0d6efd; font-weight: bold; background-color: #f0f8ff; border: 1px solid #cce5ff; border-radius: 4px; }
        .input-infl { color: #d63384; font-weight: bold; background-color: #fff0f6; border: 1px solid #ffc9db; border-radius: 4px; } 
        .text-negativo { color: #dc3545 !important; }
        /* Estilos visuales para veda */
        .fila-veda { background-color: #e9ecef !important; }
        .texto-veda { color: #6c757d !important; font-style: italic; font-weight: normal; }
    </style>
</head>
<body class="bg-light">

<form method="POST" id="formCurvaGlobal">
    <input type="hidden" name="guardar_curva" value="1">
    
    <div class="container-fluid px-4 my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="text-primary"><i class="bi bi-bar-chart-steps"></i> Nueva Curva: <?= htmlspecialchars($obra['denominacion'], ENT_QUOTES, 'UTF-8') ?></h3>
            <a href="curva_listado.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
        </div>

        <?php if($mensaje): ?>
            <div class="alert <?= $tipoMensaje ?> py-2"><?= $mensaje ?></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white fw-bold">1. Parámetros de Cálculo</div>
            <div class="card-body bg-light">
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Monto Contrato</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white">$</span>
                            <input type="text" id="monto_total" name="monto_contrato" class="form-control fw-bold fs-5" 
                                   value="<?= number_format($obra['monto_actualizado'] ?? 0, 2, ',', '.') ?>" 
                                   onchange="this.value = formatMoney(parseLocalFloat(this.value))">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-primary text-uppercase">Mes Base (Índice 1.0)</label>
                        <input type="month" id="mes_base" name="mes_base" class="form-control border-primary fw-bold" 
                               value="<?= date('Y-m', strtotime('-1 month')) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-success text-uppercase">Fecha Anticipo</label>
                        <input type="date" id="fecha_anticipo" name="fecha_anticipo" class="form-control border-success fw-bold" 
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-dark text-uppercase">Inicio Obra</label>
                        <input type="date" id="fecha_inicio_obra" name="fecha_inicio" class="form-control fw-bold" 
                               value="<?= date('Y-m-01', strtotime('+1 month')) ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-primary w-100 fw-bold shadow-sm py-2" onclick="generarGrillaBase()">
                            <i class="bi bi-table"></i> GENERAR TABLA
                        </button>
                    </div>
                </div>
                
                <hr class="text-muted opacity-25">
                
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">Plazo (Meses)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="plazo" name="plazo" class="form-control fw-bold" value="24">
                            <span class="input-group-text">Meses</span>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">% Anticipo Fin.</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="pct_anticipo" name="pct_anticipo" class="form-control fw-bold text-primary" value="10">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="d-flex align-items-center p-2 border rounded bg-white" style="height: 38px;"> 
                            <div class="form-check form-switch w-100 mb-0">
                                <input class="form-check-input" type="checkbox" id="check_redet_anticipo" checked onchange="recalcularCascadaFRI()">
                                <label class="form-check-label small fw-bold ms-2" for="check_redet_anticipo">Redeterminar Anticipo</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm" id="cardResultados" style="display:none;">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-uppercase text-secondary"><i class="bi bi-calendar3"></i> Proyección Mensual</h5>
                    
                    <div class="d-flex gap-2">
                        <input type="file" id="csvInput" accept=".csv" style="display: none;" onchange="procesarCSV(this)">
                        <button type="button" class="btn btn-outline-success btn-sm fw-bold" onclick="document.getElementById('csvInput').click()">
                            <i class="bi bi-filetype-csv"></i> Importar CSV
                        </button>
                        <button type="button" class="btn btn-warning btn-sm fw-bold text-dark" data-bs-toggle="modal" data-bs-target="#modalRangosFRI">
                            <i class="bi bi-magic"></i> Inflación Masiva
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0 align-middle" style="font-size: 0.9rem;">
                        <thead class="table-light text-center small text-uppercase align-middle">
                            <tr style="height: 45px;">
                                <th class="text-start ps-3" style="width: 100px;">Periodo</th>
                                <th class="text-start">Concepto</th>
                                <th class="col-pct">% Físico</th>
                                <th>Monto Base</th>
                                <th class="col-infl" style="color:#d63384;">% Infl.</th>
                                <th class="col-fri table-warning">FRI Acum.</th>
                                <th class="table-warning">Redeterminación</th>
                                <th class="text-danger">Recupero</th>
                                <th class="bg-primary text-white" style="width: 130px;">Neto</th>
                            </tr>
                        </thead>
                        <tbody id="gridBody"></tbody>
                        <tfoot class="table-light fw-bold small border-top-3" style="font-size: 1rem;">
                            <tr>
                                <td colspan="2" class="text-end pe-3">TOTALES:</td>
                                <td class="text-end text-primary"><span id="footPct">0,00</span>%</td>
                                <td class="text-end"><span id="footBase">$0,00</span></td>
                                <td></td>
                                <td></td>
                                <td class="text-end text-success"><span id="footRedet">$0,00</span></td>
                                <td class="text-end text-danger"><span id="footRecupero">$0,00</span></td>
                                <td class="text-end bg-primary text-white"><span id="footNeto">$0,00</span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3 text-end">
                <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">
                    <i class="bi bi-save"></i> Guardar Versión
                </button>
            </div>
        </div>
    </div>
</form>

<div class="modal fade" id="modalRangosFRI" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title">Proyección Masiva de Inflación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm table-bordered text-center">
            <thead class="table-light"><tr><th>Desde</th><th>Hasta</th><th>% Infl.</th><th></th></tr></thead>
            <tbody id="bodyRangos">
                <tr>
                    <td><input type="month" class="form-control form-control-sm rango-desde"></td>
                    <td><input type="month" class="form-control form-control-sm rango-hasta"></td>
                    <td><input type="number" step="0.1" class="form-control form-control-sm rango-pct" value="2.0"></td>
                    <td><button class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()">X</button></td>
                </tr>
            </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarRango()">+ Rango</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" onclick="aplicarRangosMasivo()">Aplicar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const indicesGlobales = <?= json_encode($indicesMap) ?>;
    const vedasGlobales = <?= $vedasJson ?>; 
    const inflacionDefault = 2.0; 

    // --- HELPERS FORMATO ---
    function parseLocalFloat(str) {
        if(!str) return 0;
        let clean = String(str).replace('$','').replace(' ','').replace('-','');
        clean = clean.replace(/\./g, ''); 
        clean = clean.replace(',', '.');  
        return parseFloat(clean) || 0;
    }
    
    function formatMoney(num) {
        if(num === null || num === undefined || isNaN(num)) return "0,00";
        return num.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    function formatFri(num) {
        return num.toLocaleString('es-AR', {minimumFractionDigits: 4, maximumFractionDigits: 4});
    }
    function formatPct(num) {
        return num.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // --- CÁLCULO DE CURVA S (Distribución Normal) ---
    function generarPesosCurvaS(meses) {
        if (meses <= 0) return [];
        let pesos = [];
        let totalPeso = 0;
        const media = meses / 2;     
        const desviacion = meses / 4; 

        for(let i = 1; i <= meses; i++) {
            let x = i;
            let exponente = -0.5 * Math.pow((x - media) / desviacion, 2);
            let peso = Math.exp(exponente);
            pesos.push(peso);
            totalPeso += peso;
        }
        return pesos.map(p => (p / totalPeso) * 100);
    }

    // --- HELPER VEDA ---
    function esMesVeda(fechaDate) {
        let check = new Date(fechaDate);
        check.setDate(15); 
        for (let v of vedasGlobales) {
            let inicio = new Date(v.fecha + 'T00:00:00');
            let fin = v.fecha_fin ? new Date(v.fecha_fin + 'T00:00:00') : inicio;
            if (check >= inicio && check <= fin) return true;
        }
        return false;
    }

    // --- GENERAR GRILLA INTELIGENTE ---
    function generarGrillaBase() {
        const montoTotal = parseLocalFloat(document.getElementById('monto_total').value);
        const pctAnticipo = parseFloat(document.getElementById('pct_anticipo').value) || 0;
        const plazo = parseInt(document.getElementById('plazo').value) || 12;
        const fechaInicio = document.getElementById('fecha_inicio_obra').value;
        const fechaAnticipo = document.getElementById('fecha_anticipo').value;
        
        if(montoTotal <= 0) { alert("Ingrese monto contrato válido"); return; }
        if(plazo <= 0) { alert("Ingrese un plazo válido"); return; }

        const tbody = document.getElementById('gridBody');
        tbody.innerHTML = '';
        
        // 1. ANTICIPO
        let trAF = crearFilaHTML({
            tipo: 'anticipo', idx: 'af', concepto: 'Anticipo Financiero (AF)',
            fecha: fechaAnticipo, pctSug: pctAnticipo, esAnticipo: true
        });
        tbody.appendChild(trAF);

        // 2. CALCULAR CALENDARIO Y TRABAJABILIDAD
        let fechaCursor = new Date(fechaInicio + 'T00:00:00');
        let mapaMeses = []; 
        let mesesTrabajables = 0;

        for(let i=1; i<=plazo; i++) {
            let hayVeda = esMesVeda(fechaCursor);
            mapaMeses.push({ fecha: new Date(fechaCursor), esVeda: hayVeda });
            if(!hayVeda) mesesTrabajables++;
            fechaCursor.setMonth(fechaCursor.getMonth() + 1);
        }

        // 3. CURVA S
        let porcentajesS = generarPesosCurvaS(mesesTrabajables);
        let indicePesos = 0;

        // 4. DIBUJAR FILAS
        let contadorCertificados = 1; // Contador independiente

        mapaMeses.forEach((m, index) => {
            let mes = m.fecha.getMonth() + 1;
            let anio = m.fecha.getFullYear();
            let periodoStr = anio + '-' + (mes < 10 ? '0'+mes : mes); 
            let periodoDia = m.fecha.toISOString().split('T')[0];

            let concepto = '';
            let pctDelMes = 0;

            if(m.esVeda) {
                // CASO VEDA: No hay certificado, pausa numeración
                pctDelMes = 0.00;
                concepto = 'Veda Climática'; 
            } else {
                // CASO TRABAJO: Numeramos y asignamos peso
                concepto = 'Certificado Nº ' + contadorCertificados;
                contadorCertificados++;
                
                if(indicePesos < porcentajesS.length) {
                    pctDelMes = porcentajesS[indicePesos];
                    indicePesos++;
                }
            }

            // Usamos 'index' para el ID unico del input en el array POST
            let tr = crearFilaHTML({
                tipo: 'certificado', idx: index + 1, concepto: concepto,
                fecha: periodoDia, periodoVisual: periodoStr, pctSug: pctDelMes, esAnticipo: false
            });

            if(m.esVeda) {
                tr.classList.add('fila-veda');
                tr.querySelector('.input-pct').classList.add('texto-veda');
            }

            tbody.appendChild(tr);
        });

        document.getElementById('cardResultados').style.display = 'block';
        recalcularCascadaFRI(); 
    }

    function crearFilaHTML(p) {
        let tr = document.createElement('tr');
        tr.setAttribute('data-tipo', p.tipo); 
        tr.setAttribute('data-fecha', p.fecha);
        
        let periodoYM = p.fecha.substring(0, 7);
        let inflacionMes = (indicesGlobales[periodoYM] !== undefined) ? indicesGlobales[periodoYM] : inflacionDefault;
        let estiloFila = p.esAnticipo ? 'table-warning border-bottom border-dark' : '';
        let estiloConcepto = p.esAnticipo ? 'fw-bold text-primary' : '';
        
        tr.className = estiloFila;
        tr.innerHTML = `
            <td class="text-start bg-light fw-bold text-muted small ps-3">
                ${periodoYM}
                <input type="hidden" name="items[${p.idx}][fecha]" value="${p.fecha}">
            </td>
            <td class="${estiloConcepto} text-start">
                ${p.concepto}
                <input type="hidden" name="items[${p.idx}][concepto]" value="${p.concepto}">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm text-end input-pct" 
                       value="${formatPct(p.pctSug)}" onchange="recalcularFilaPorInput(this)">
                <input type="hidden" class="hidden-pct" name="items[${p.idx}][pct_hidden]" value="${p.pctSug}">
            </td>
            <td class="bg-readonly">
                <input type="text" readonly class="table-input input-bruto" value="0,00">
                <input type="hidden" class="hidden-bruto" name="items[${p.idx}][bruto_hidden]" value="0">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm text-end input-infl"
                       value="${formatPct(inflacionMes)}" onchange="recalcularCascadaFRI()">
                <input type="hidden" class="hidden-infl" name="items[${p.idx}][infl_hidden]" value="${inflacionMes}">
            </td>
            <td class="table-warning">
                <input type="text" class="form-control form-control-sm text-end input-fri fw-bold border-0 bg-transparent" 
                       value="1,0000" readonly tabindex="-1">
                <input type="hidden" class="hidden-fri" name="items[${p.idx}][fri_hidden]" value="1">
            </td>
            <td class="bg-readonly table-warning">
                <input type="text" readonly class="table-input input-redet text-success" value="0,00">
                <input type="hidden" class="hidden-redet" name="items[${p.idx}][redet_hidden]" value="0">
            </td>
            <td class="bg-readonly">
                <input type="text" readonly class="table-input input-recupero text-danger" value="0,00">
                <input type="hidden" class="hidden-recupero" name="items[${p.idx}][recupero_hidden]" value="0">
            </td>
            <td class="bg-readonly fw-bold">
                <input type="text" readonly class="table-input input-neto text-primary" value="0,00">
                <input type="hidden" class="hidden-neto" name="items[${p.idx}][neto_hidden]" value="0">
            </td>
        `;
        return tr;
    }

    // --- LOGICA DE CÁLCULO ---
    function recalcularFilaPorInput(inputPct) {
        let tr = inputPct.closest('tr');
        let valorRaw = parseLocalFloat(inputPct.value);
        inputPct.value = formatPct(valorRaw);
        tr.querySelector('.hidden-pct').value = valorRaw;
        recalcularMontosFila(tr);
        actualizarFooter();
    }

    function recalcularCascadaFRI() {
        const mesBaseStr = document.getElementById('mes_base').value; 
        const usarRedetAnticipo = document.getElementById('check_redet_anticipo').checked;
        const filas = document.querySelectorAll('#gridBody tr');
        let friAcumuladoCertificados = 1.0; 
        let inicioCertificadosCalculado = false;

        filas.forEach((tr) => {
            let tipo = tr.getAttribute('data-tipo');
            let fechaFila = tr.getAttribute('data-fecha'); 
            let periodoYM = fechaFila.substring(0, 7);
            let friFila = 1.0;
            let inflacionInput = parseLocalFloat(tr.querySelector('.input-infl').value);
            tr.querySelector('.hidden-infl').value = inflacionInput;

            if (tipo === 'anticipo') {
                if (usarRedetAnticipo) {
                    let friPrevio = calcularFriHistorico(mesBaseStr, periodoYM); 
                    friFila = friPrevio * (1 + (inflacionInput / 100));
                } else {
                    friFila = 1.0000;
                }
            } else {
                if (!inicioCertificadosCalculado) {
                    friAcumuladoCertificados = calcularFriHistorico(mesBaseStr, periodoYM);
                    inicioCertificadosCalculado = true;
                }
                friAcumuladoCertificados = friAcumuladoCertificados * (1 + (inflacionInput / 100));
                friFila = friAcumuladoCertificados;
            }
            
            tr.querySelector('.input-fri').value = formatFri(friFila);
            tr.querySelector('.hidden-fri').value = friFila; 
            recalcularMontosFila(tr); 
        });
        actualizarFooter();
    }

    function recalcularMontosFila(tr) {
        const montoTotal = parseLocalFloat(document.getElementById('monto_total').value);
        const pctAnticipo = parseFloat(document.getElementById('pct_anticipo').value) || 0;
        const tipo = tr.getAttribute('data-tipo');

        let pctFisico = parseFloat(tr.querySelector('.hidden-pct').value) || 0;
        let fri = parseFloat(tr.querySelector('.hidden-fri').value) || 1;

        let montoBase = montoTotal * (pctFisico / 100);
        let montoRedeterminado = montoBase * fri; 
        let redet = montoRedeterminado - montoBase;
        
        let recupero = 0;
        if (tipo !== 'anticipo') recupero = montoBase * (pctAnticipo / 100);

        let neto = (montoBase + redet) - recupero;

        tr.querySelector('.input-bruto').value = formatMoney(montoBase);
        tr.querySelector('.input-redet').value = formatMoney(redet);
        tr.querySelector('.input-recupero').value = recupero > 0 ? "- " + formatMoney(recupero) : "0,00";
        tr.querySelector('.input-neto').value = formatMoney(neto);
        
        tr.querySelector('.hidden-bruto').value = montoBase;
        tr.querySelector('.hidden-redet').value = redet;
        tr.querySelector('.hidden-recupero').value = recupero;
        tr.querySelector('.hidden-neto').value = neto;

        if(neto < 0) tr.querySelector('.input-neto').classList.add('text-negativo');
        else tr.querySelector('.input-neto').classList.remove('text-negativo');
    }

    function actualizarFooter() {
        let sumPct = 0, sumBase = 0, sumRedet = 0, sumRecup = 0, sumNeto = 0;
        
        document.querySelectorAll('#gridBody tr').forEach(tr => {
            let tipo = tr.getAttribute('data-tipo');
            
            let valBase = parseFloat(tr.querySelector('.hidden-bruto').value) || 0;
            let valPct = parseFloat(tr.querySelector('.hidden-pct').value) || 0;
            let valRedet = parseFloat(tr.querySelector('.hidden-redet').value) || 0;
            let valRecup = parseFloat(tr.querySelector('.hidden-recupero').value) || 0;
            let valNeto = parseFloat(tr.querySelector('.hidden-neto').value) || 0;

            if(tipo !== 'anticipo') {
                sumPct += valPct;
                sumBase += valBase;
            }
            
            sumRedet += valRedet;
            sumRecup += valRecup;
            sumNeto += valNeto;
        });
        
        document.getElementById('footPct').innerText = formatPct(sumPct);
        document.getElementById('footBase').innerText = '$ ' + formatMoney(sumBase);
        document.getElementById('footRedet').innerText = '$ ' + formatMoney(sumRedet);
        document.getElementById('footRecupero').innerText = '- $ ' + formatMoney(sumRecup);
        document.getElementById('footNeto').innerText = '$ ' + formatMoney(sumNeto);
    }

    function calcularFriHistorico(mesBase, mesDestino) {
        if (mesDestino <= mesBase) return 1.0;
        let [yB, mB] = mesBase.split('-').map(Number);
        let [yD, mD] = mesDestino.split('-').map(Number);
        let cursor = new Date(yB, mB, 1); 
        let fin = new Date(yD, mD - 1, 1); 
        let acumulado = 1.0;
        let safe = 0;
        while(cursor < fin && safe < 120) {
            let m = cursor.getMonth() + 1;
            let a = cursor.getFullYear();
            let pStr = a + '-' + (m < 10 ? '0'+m : m);
            let pct = (indicesGlobales[pStr] !== undefined) ? indicesGlobales[pStr] : inflacionDefault;
            acumulado = acumulado * (1 + (pct / 100));
            cursor.setMonth(cursor.getMonth() + 1);
            safe++;
        }
        return acumulado;
    }

    function agregarRango() {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td><input type="month" class="form-control form-control-sm rango-desde"></td>
                        <td><input type="month" class="form-control form-control-sm rango-hasta"></td>
                        <td><input type="number" step="0.1" class="form-control form-control-sm rango-pct"></td>
                        <td><button class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()">X</button></td>`;
        document.getElementById('bodyRangos').appendChild(tr);
    }
    
    function aplicarRangosMasivo() {
        let rangos = [];
        document.querySelectorAll('#bodyRangos tr').forEach(tr => {
            let d = tr.querySelector('.rango-desde').value;
            let h = tr.querySelector('.rango-hasta').value;
            let p = parseFloat(tr.querySelector('.rango-pct').value);
            if(d && h && !isNaN(p)) rangos.push({ desde: d, hasta: h, pct: p });
        });
        document.querySelectorAll('#gridBody tr').forEach(tr => {
            let periodo = tr.getAttribute('data-fecha').substring(0,7);
            for(let r of rangos) if(periodo >= r.desde && periodo <= r.hasta) {
                let inputInfl = tr.querySelector('.input-infl');
                inputInfl.value = formatPct(r.pct);
                tr.querySelector('.hidden-infl').value = r.pct;
            }
        });
        recalcularCascadaFRI();
        bootstrap.Modal.getInstance(document.getElementById('modalRangosFRI')).hide();
    }

    function procesarCSV(input) {
        if (!input.files || !input.files[0]) return;
        const archivo = input.files[0];
        const lector = new FileReader();
        lector.onload = function(e) {
            const contenido = e.target.result;
            const lineas = contenido.split(/\r\n|\n/); 
            let actualizados = 0;
            lineas.forEach(linea => {
                if (!linea.trim()) return; 
                let partes = linea.split(';'); 
                if (partes.length < 2) partes = linea.split(',');
                if (partes.length >= 2) {
                    let periodoCSV = partes[0].trim(); 
                    let pctRaw = partes[1].trim();     
                    let filas = document.querySelectorAll(`#gridBody tr`);
                    let filaEncontrada = null;
                    filas.forEach(tr => {
                        let fechaFila = tr.getAttribute('data-fecha'); 
                        if (fechaFila && fechaFila.startsWith(periodoCSV)) filaEncontrada = tr;
                    });
                    if (filaEncontrada) {
                        let pctFloat = parseFloat(pctRaw.replace(',', '.'));
                        if (!isNaN(pctFloat)) {
                            let inputPct = filaEncontrada.querySelector('.input-pct');
                            inputPct.value = formatPct(pctFloat); 
                            recalcularFilaPorInput(inputPct); 
                            actualizados++;
                        }
                    }
                }
            });
            input.value = ''; 
            alert(`Importación finalizada.\nSe actualizaron ${actualizados} periodos.`);
            actualizarFooter();
        };
        lector.readAsText(archivo);
    }
</script>
</body>
</html>
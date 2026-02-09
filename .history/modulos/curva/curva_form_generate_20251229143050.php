Entendido. He realizado las correcciones exactas que pediste:

1. **Recupero a Valor Nominal:** Modifiqué la fórmula matemática. Ahora el descuento del anticipo se calcula **solo sobre el Monto Base** (sin incluir la redeterminación), lo que matemáticamente beneficia al flujo de caja de la obra.
2. **Redeterminación del Anticipo Opcional:** Agregué un "Switch" (interruptor) en la parte superior. Si lo apagas, el Anticipo Financiero no genera redeterminación (FRI = 1.000).
3. **Diseño Superior (Alineación):** Reorganicé la tarjeta de parámetros. Ahora está dividida en dos filas claras para que los inputs no se amontonen y estén perfectamente alineados.
4. **Columnas más anchas:** Aumenté el ancho de las columnas de "% Físico" y "% Inflación" para que los números se lean cómodamente.

Aquí tienes el código completo actualizado:

```php
<?php
// curva_form_generate.php
session_start();
require_once __DIR__ . '/../../config/database.php';
// require_once __DIR__ . '/includes/CurvaCalculator.php'; 

$obraId = $_GET['obra_id'] ?? 0;
$mensaje = '';

// 1. Obtener datos de la obra
$stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
$stmt->execute([$obraId]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$obra) die("Obra no encontrada");

// 2. Cargar ÍNDICES GLOBALES
$stmtInd = $pdo->query("SELECT anio, mes, porcentaje FROM indices_mensuales ORDER BY anio ASC, mes ASC");
$indicesRaw = $stmtInd->fetchAll(PDO::FETCH_ASSOC);

$indicesMap = [];
foreach ($indicesRaw as $r) {
    $periodo = sprintf("%04d-%02d", $r['anio'], $r['mes']);
    $indicesMap[$periodo] = (float)$r['porcentaje'];
}

// 3. Procesar Guardado (Simulado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_curva'])) {
    // Aquí tu lógica de guardado
    $mensaje = "Datos listos para procesar. Recuerda validar los montos en el backend.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Curva: <?= htmlspecialchars($obra['denominacion']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Estilos de Inputs en Tabla */
        .table-input { 
            width: 100%; border: none; background: transparent; 
            text-align: right; font-size: 0.95rem; font-family: 'Consolas', monospace; font-weight: 600;
        }
        .table-input:focus { background: #fff; outline: 2px solid #86b7fe; }
        .bg-readonly { background-color: #f8f9fa; }
        
        /* Colores Específicos */
        .input-pct { color: #0d6efd; font-weight: bold; font-size: 1rem; }
        .input-infl { color: #d63384; font-weight: bold; font-size: 1rem; } 
        .text-negativo { color: #dc3545 !important; }

        /* Ajuste de ancho de columnas solicitado */
        .col-pct { width: 100px; }
        .col-infl { width: 100px; }
        .col-fri { width: 90px; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="text-primary"><i class="bi bi-bar-chart-steps"></i> Nueva Curva: <?= htmlspecialchars($obra['denominacion']) ?></h3>
        <a href="curva_listado.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-info py-2"><?= $mensaje ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-header bg-primary text-white fw-bold">1. Parámetros Generales</div>
        <div class="card-body bg-light">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Monto Contrato</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0">$</span>
                        <input type="number" id="monto_total" class="form-control fw-bold border-start-0 ps-0" 
                               value="<?= $obra['monto_contrato'] ?? 0 ?>" style="font-size: 1.1rem;">
                    </div>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-primary text-uppercase">Mes Base (Índice 1.0)</label>
                    <input type="month" id="mes_base" class="form-control border-primary fw-bold" 
                           value="<?= date('Y-m', strtotime('-1 month')) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label small fw-bold text-success text-uppercase">Fecha Pago Anticipo</label>
                    <input type="date" id="fecha_anticipo" class="form-control border-success fw-bold" 
                           value="<?= date('Y-m-d') ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label small fw-bold text-dark text-uppercase">Fecha Inicio Obra</label>
                    <input type="date" id="fecha_inicio_obra" class="form-control fw-bold" 
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
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Plazo de Obra</label>
                    <div class="input-group input-group-sm">
                        <input type="number" id="plazo" class="form-control fw-bold" value="12">
                        <span class="input-group-text">Meses</span>
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">% Anticipo Fin.</label>
                    <div class="input-group input-group-sm">
                        <input type="number" id="pct_anticipo" class="form-control fw-bold text-primary" value="10">
                        <span class="input-group-text">%</span>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-check form-switch p-2 border rounded bg-white">
                        <input class="form-check-input" type="checkbox" id="check_redet_anticipo" checked onchange="recalcularCascadaFRI()">
                        <label class="form-check-label small fw-bold" for="check_redet_anticipo">Redeterminar Anticipo</label>
                        <div class="form-text" style="font-size: 0.75rem; line-height: 1;">Si se desactiva, el FRI del Anticipo será 1.0</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="formCurva" style="display:none;">
        <input type="hidden" name="guardar_curva" value="1">
        
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0 fw-bold text-uppercase text-secondary"><i class="bi bi-calendar3"></i> Proyección Financiera</h5>
                
                <button type="button" class="btn btn-warning btn-sm fw-bold text-dark" data-bs-toggle="modal" data-bs-target="#modalRangosFRI">
                    <i class="bi bi-magic"></i> Proyectar Inflación Masiva
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0 align-middle" style="font-size: 0.9rem;">
                        <thead class="table-light text-center small text-uppercase align-middle">
                            <tr style="height: 50px;">
                                <th class="text-start ps-3" style="width: 100px;">Periodo</th>
                                <th class="text-start">Concepto</th>
                                <th class="col-pct">% Físico</th> <th>Monto Base</th>
                                <th class="col-infl" style="color:#d63384;">% Infl. Mes</th> <th class="col-fri table-warning">FRI Acum.</th>
                                <th class="table-warning">Redeterminación</th>
                                <th class="text-danger">Recupero (Nominal)</th>
                                <th class="bg-primary text-white" style="width: 140px;">Neto a Cobrar</th>
                            </tr>
                        </thead>
                        <tbody id="gridBody">
                            </tbody>
                        <tfoot class="table-light fw-bold small border-top-3">
                            <tr style="font-size: 1rem;">
                                <td colspan="3" class="text-end pe-3">TOTALES CONSOLIDADOS:</td>
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
                    <i class="bi bi-check-circle-fill"></i> Guardar Versión Definitiva
                </button>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="modalRangosFRI" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title">Proyección de Inflación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm table-bordered text-center">
            <thead class="table-light"><tr><th>Desde</th><th>Hasta</th><th>% Inflación</th><th></th></tr></thead>
            <tbody id="bodyRangos">
                <tr>
                    <td><input type="month" class="form-control form-control-sm rango-desde"></td>
                    <td><input type="month" class="form-control form-control-sm rango-hasta"></td>
                    <td><input type="number" step="0.1" class="form-control form-control-sm rango-pct" placeholder="2.0"></td>
                    <td><button class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()">X</button></td>
                </tr>
            </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarRango()">+ Agregar Fila</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" onclick="aplicarRangosMasivo()">Aplicar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- DATOS GLOBALES ---
    const indicesGlobales = <?= json_encode($indicesMap) ?>;
    const inflacionDefault = 2.0; 

    function formatMoney(num) {
        if(num === null || num === undefined) return "0,00";
        return num.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // --- 1. GENERAR TABLA ---
    function generarGrillaBase() {
        const montoTotal = parseFloat(document.getElementById('monto_total').value) || 0;
        const pctAnticipo = parseFloat(document.getElementById('pct_anticipo').value) || 0;
        const plazo = parseInt(document.getElementById('plazo').value) || 12;
        const fechaInicio = document.getElementById('fecha_inicio_obra').value;
        const fechaAnticipo = document.getElementById('fecha_anticipo').value;
        
        if(montoTotal <= 0) { alert("Ingrese monto contrato"); return; }
        if(!fechaInicio || !fechaAnticipo) { alert("Revise las fechas"); return; }

        const tbody = document.getElementById('gridBody');
        tbody.innerHTML = '';
        
        // A. FILA ANTICIPO
        let trAF = crearFilaHTML({
            tipo: 'anticipo', idx: 'af', concepto: 'Anticipo Financiero (AF)',
            fecha: fechaAnticipo, pctSug: pctAnticipo, esAnticipo: true
        });
        tbody.appendChild(trAF);

        // B. FILAS CERTIFICADOS
        let fechaCursor = new Date(fechaInicio + 'T00:00:00');
        let pctMensual = ((100) / plazo).toFixed(3); // Distribución 100% en certificados

        for(let i=1; i<=plazo; i++) {
            let mes = fechaCursor.getMonth() + 1;
            let anio = fechaCursor.getFullYear();
            let periodoStr = anio + '-' + (mes < 10 ? '0'+mes : mes); 
            let periodoDia = fechaCursor.toISOString().split('T')[0];

            let tr = crearFilaHTML({
                tipo: 'certificado', idx: i, concepto: 'Certificado Nº ' + i,
                fecha: periodoDia, periodoVisual: periodoStr, pctSug: pctMensual, esAnticipo: false
            });
            tbody.appendChild(tr);
            fechaCursor.setMonth(fechaCursor.getMonth() + 1);
        }

        document.getElementById('formCurva').style.display = 'block';
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
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-sm text-end input-pct" 
                       name="items[${p.idx}][pct]" value="${p.pctSug}" onchange="recalcularTodo()">
            </td>
            <td class="bg-readonly">
                <input type="text" readonly class="table-input input-bruto" name="items[${p.idx}][bruto]" value="0,00">
            </td>
            
            <td>
                <input type="number" step="0.1" class="form-control form-control-sm text-end input-infl"
                       value="${inflacionMes}" onchange="recalcularCascadaFRI()">
            </td>

            <td class="table-warning">
                <input type="number" step="0.0001" class="form-control form-control-sm text-end input-fri fw-bold" 
                       name="items[${p.idx}][fri]" value="1.0000" readonly tabindex="-1">
            </td>

            <td class="bg-readonly table-warning">
                <input type="text" readonly class="table-input input-redet text-success" name="items[${p.idx}][redet]" value="0,00">
            </td>
            <td class="bg-readonly">
                <input type="text" readonly class="table-input input-recupero text-danger" name="items[${p.idx}][recupero]" value="0,00">
            </td>
            <td class="bg-readonly fw-bold">
                <input type="text" readonly class="table-input input-neto text-primary" name="items[${p.idx}][neto]" value="0,00">
            </td>
        `;
        return tr;
    }

    // --- 2. CÁLCULO DE FRI ---
    function recalcularCascadaFRI() {
        const mesBaseStr = document.getElementById('mes_base').value; 
        const usarRedetAnticipo = document.getElementById('check_redet_anticipo').checked; // OPCIONAL
        const filas = document.querySelectorAll('#gridBody tr');
        
        let friAcumuladoCertificados = 1.0; 
        let inicioCertificadosCalculado = false;

        filas.forEach((tr) => {
            let tipo = tr.getAttribute('data-tipo');
            let fechaFila = tr.getAttribute('data-fecha'); 
            let periodoYM = fechaFila.substring(0, 7);
            let friFila = 1.0;

            if (tipo === 'anticipo') {
                if (usarRedetAnticipo) {
                    friFila = calcularFriHistorico(mesBaseStr, periodoYM); 
                } else {
                    friFila = 1.0000; // Sin redeterminación
                }
            } else {
                if (!inicioCertificadosCalculado) {
                    friAcumuladoCertificados = calcularFriHistorico(mesBaseStr, periodoYM);
                    inicioCertificadosCalculado = true;
                }
                let inflacionInput = parseFloat(tr.querySelector('.input-infl').value) || 0;
                friAcumuladoCertificados = friAcumuladoCertificados * (1 + (inflacionInput / 100));
                friFila = friAcumuladoCertificados;
            }

            tr.querySelector('.input-fri').value = friFila.toFixed(4);
            recalcularMontosFila(tr); 
        });
        actualizarFooter();
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

    // --- 3. CÁLCULO FINANCIERO (CON CORRECCIÓN VALOR NOMINAL) ---
    function recalcularMontosFila(tr) {
        const montoTotal = parseFloat(document.getElementById('monto_total').value) || 0;
        const pctAnticipo = parseFloat(document.getElementById('pct_anticipo').value) || 0;
        const tipo = tr.getAttribute('data-tipo');

        let pctFisico = parseFloat(tr.querySelector('.input-pct').value) || 0;
        let fri = parseFloat(tr.querySelector('.input-fri').value) || 1;

        let montoBase = montoTotal * (pctFisico / 100);
        let montoRedeterminado = montoBase * fri; 
        let redet = montoRedeterminado - montoBase;
        
        let recupero = 0;
        if (tipo === 'anticipo') {
            recupero = 0; 
        } else {
            // CORRECCIÓN APLICADA: RECUPERO A VALOR NOMINAL
            // Se calcula sobre el Monto Base, NO sobre el Monto Bruto con Redeterminación
            recupero = montoBase * (pctAnticipo / 100);
        }

        let neto = (montoBase + redet) - recupero;

        tr.querySelector('.input-bruto').value = formatMoney(montoBase);
        tr.querySelector('.input-redet').value = formatMoney(redet);
        tr.querySelector('.input-recupero').value = recupero > 0 ? "- " + formatMoney(recupero) : "0,00";
        tr.querySelector('.input-neto').value = formatMoney(neto);
        
        if(neto < 0) tr.querySelector('.input-neto').classList.add('text-negativo');
        else tr.querySelector('.input-neto').classList.remove('text-negativo');
    }

    function recalcularTodo() {
        document.querySelectorAll('#gridBody tr').forEach(tr => recalcularMontosFila(tr));
        actualizarFooter();
    }
    
    function parseLocalFloat(str) {
        if(!str) return 0;
        let clean = str.replace('$','').replace(' ','').replace('-','').replace(/\./g, '').replace(',', '.');
        return parseFloat(clean) || 0;
    }

    function actualizarFooter() {
        let sumBase = 0, sumRedet = 0, sumRecup = 0, sumNeto = 0;
        document.querySelectorAll('#gridBody tr').forEach(tr => {
            sumBase += parseLocalFloat(tr.querySelector('.input-bruto').value);
            sumRedet += parseLocalFloat(tr.querySelector('.input-redet').value);
            sumRecup += parseLocalFloat(tr.querySelector('.input-recupero').value);
            sumNeto += parseLocalFloat(tr.querySelector('.input-neto').value);
        });
        document.getElementById('footBase').innerText = '$ ' + formatMoney(sumBase);
        document.getElementById('footRedet').innerText = '$ ' + formatMoney(sumRedet);
        document.getElementById('footRecupero').innerText = '- $ ' + formatMoney(sumRecup);
        document.getElementById('footNeto').innerText = '$ ' + formatMoney(sumNeto);
    }

    // Modal (sin cambios)
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
            for(let r of rangos) if(periodo >= r.desde && periodo <= r.hasta) tr.querySelector('.input-infl').value = r.pct;
        });
        recalcularCascadaFRI();
        bootstrap.Modal.getInstance(document.getElementById('modalRangosFRI')).hide();
    }
</script>
</body>
</html>
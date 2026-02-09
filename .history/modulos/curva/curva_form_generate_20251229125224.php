<?php
// curva_form_generate.php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/includes/CurvaCalculator.php';

$obraId = $_GET['obra_id'] ?? 0;
$mensaje = '';

// 1. Obtener datos de la obra
$stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
$stmt->execute([$obraId]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$obra) die("Obra no encontrada");

// 2. Cargar ÍNDICES GLOBALES (Para pre-llenar)
$stmtInd = $pdo->query("SELECT anio, mes, porcentaje FROM indices_mensuales ORDER BY anio ASC, mes ASC");
$indicesRaw = $stmtInd->fetchAll(PDO::FETCH_ASSOC);

// Convertimos a array JS: "2025-01" => 2.5
$indicesMap = [];
foreach ($indicesRaw as $r) {
    $periodo = sprintf("%04d-%02d", $r['anio'], $r['mes']);
    $indicesMap[$periodo] = (float)$r['porcentaje'];
}

// 3. Guardado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_curva'])) {
    try {
        $manager = new CurvaManager($pdo);
        // IMPORTANTE: Verifica que CurvaCalculator use el nombre correcto de tu tabla
        $manager->guardarVersion($obraId, $_POST);
        header("Location: curva_listado.php?msg=ok");
        exit;
    } catch (Exception $e) {
        $mensaje = "Error al guardar: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Curva Híbrida</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .table-input { width: 100%; border: none; background: transparent; text-align: right; font-size: 0.9rem; }
        .table-input:focus { background: #fff; outline: 2px solid #86b7fe; }
        .bg-readonly { background-color: #f8f9fa; }
        .input-pct { color: #0d6efd; font-weight: 500; }
        .input-infl { color: #d63384; font-weight: 500; } /* Color rosa para inflación */
    </style>
</head>
<body class="bg-light">

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Nueva Curva: <?= htmlspecialchars($obra['denominacion']) ?></h3>
        <a href="curva_listado.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-danger"><?= $mensaje ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-bold">Parámetros de Cálculo</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small">Monto Contrato</label>
                    <input type="number" id="monto_total" class="form-control" value="<?= $obra['monto_contrato'] ?? 0 ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">% Anticipo</label>
                    <input type="number" id="pct_anticipo" class="form-control" value="20">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Fecha Inicio Obra</label>
                    <input type="date" id="fecha_inicio" class="form-control" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Plazo (Meses)</label>
                    <input type="number" id="plazo" class="form-control" value="12">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-primary fw-bold">Mes Base (Indice 1.0)</label>
                    <input type="month" id="mes_base" class="form-control border-primary" 
                           value="<?= date('Y-m', strtotime('-1 month')) ?>">
                    <div class="form-text" style="font-size: 11px">La inflación acumula desde aquí.</div>
                </div>

                <div class="col-12 text-end border-top pt-3 mt-3">
                    <button type="button" class="btn btn-primary px-4" onclick="generarGrillaBase()">
                        <i class="bi bi-calculator"></i> Generar Tabla
                    </button>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="formCurva" style="display:none;">
        <input type="hidden" name="guardar_curva" value="1">
        
        <div class="card shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <span class="fw-bold text-muted small text-uppercase">Detalle Mensual</span>
                
                <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalRangosFRI">
                    <i class="bi bi-magic"></i> Editar Inflación por Rangos
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0 align-middle text-end" style="font-size: 0.9rem;">
                        <thead class="table-light text-center small text-uppercase">
                            <tr>
                                <th class="text-start">Periodo</th>
                                <th style="width: 80px;">% Avance</th>
                                <th>Monto Base</th>
                                <th style="width: 90px; color:#d63384;">% Infl. Mes</th> <th class="table-warning" style="width: 90px;">FRI Acum.</th>
                                <th class="table-warning">Redeterminación</th>
                                <th>Recupero</th>
                                <th>Neto</th>
                            </tr>
                        </thead>
                        <tbody id="gridBody">
                            </tbody>
                        <tfoot class="table-light fw-bold small">
                            <tr>
                                <td class="text-start">TOTALES</td>
                                <td id="footPct">0%</td>
                                <td id="footBruto">$0</td>
                                <td>-</td>
                                <td>-</td>
                                <td id="footRedet" class="text-warning-emphasis">$0</td>
                                <td id="footRecupero">$0</td>
                                <td id="footNeto">$0</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3 text-end">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save"></i> Guardar Versión
                </button>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="modalRangosFRI" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title">Editar Inflación por Rangos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Esto sobrescribirá los valores de la columna "% Infl. Mes" en la tabla principal.</p>
        <table class="table table-sm table-bordered text-center">
            <thead class="table-light">
                <tr><th>Desde</th><th>Hasta</th><th>% Inflación</th><th></th></tr>
            </thead>
            <tbody id="bodyRangos">
                <tr>
                    <td><input type="month" class="form-control form-control-sm rango-desde"></td>
                    <td><input type="month" class="form-control form-control-sm rango-hasta"></td>
                    <td><input type="number" step="0.1" class="form-control form-control-sm rango-pct" placeholder="2.0"></td>
                    <td><button class="btn btn-outline-danger btn-sm" onclick="eliminarRango(this)">X</button></td>
                </tr>
            </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarRango()">+ Agregar Tramo</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-primary" onclick="aplicarRangosMasivo()">Aplicar Cambios</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Datos globales desde PHP
    const indicesGlobales = <?= json_encode($indicesMap) ?>;
    const inflacionDefault = 2.0; 

    // --- 1. GENERAR TABLA ---
    function generarGrillaBase() {
        const montoTotal = parseFloat(document.getElementById('monto_total').value) || 0;
        const plazo = parseInt(document.getElementById('plazo').value) || 12;
        let fecha = new Date(document.getElementById('fecha_inicio').value + 'T00:00:00');
        const mesBaseStr = document.getElementById('mes_base').value; // YYYY-MM
        
        if(montoTotal <= 0) { alert("Ingrese monto contrato"); return; }

        const tbody = document.getElementById('gridBody');
        tbody.innerHTML = '';
        
        let pctSugerido = (100 / plazo).toFixed(3);

        for(let i=0; i<plazo; i++) {
            let mes = fecha.getMonth() + 1;
            let anio = fecha.getFullYear();
            let periodoStr = anio + '-' + (mes < 10 ? '0'+mes : mes); // 2026-01

            // Buscar % Inflación en BD o usar default
            let inflacionMes = (indicesGlobales[periodoStr] !== undefined) 
                                ? indicesGlobales[periodoStr] 
                                : inflacionDefault;

            let tr = document.createElement('tr');
            tr.setAttribute('data-periodo', periodoStr); // Para identificar fila
            tr.innerHTML = `
                <td class="text-start bg-light fw-bold text-muted" style="font-size:0.85rem;">
                    ${periodoStr}
                    <input type="hidden" name="items[${i}][periodo]" value="${periodoStr}">
                </td>
                <td>
                    <input type="number" step="0.001" class="form-control form-control-sm text-end input-pct" 
                           name="items[${i}][pct]" value="${pctSugerido}" onchange="recalcularTodo()">
                </td>
                <td class="bg-readonly">
                    <input type="text" readonly class="table-input input-bruto" name="items[${i}][bruto]" value="0">
                </td>
                
                <td>
                    <input type="number" step="0.1" class="form-control form-control-sm text-end input-infl"
                           value="${inflacionMes}" onchange="recalcularCascadaFRI()">
                </td>

                <td class="table-warning">
                    <input type="number" step="0.0001" class="form-control form-control-sm text-end input-fri fw-bold" 
                           name="items[${i}][fri]" value="1.0000" readonly tabindex="-1">
                </td>

                <td class="bg-readonly table-warning">
                    <input type="text" readonly class="table-input input-redet" name="items[${i}][redet]" value="0">
                </td>
                <td class="bg-readonly">
                    <input type="text" readonly class="table-input input-recupero" name="items[${i}][recupero]" value="0">
                </td>
                <td class="bg-readonly fw-bold">
                    <input type="text" readonly class="table-input input-neto" name="items[${i}][neto]" value="0">
                </td>
            `;
            tbody.appendChild(tr);
            fecha.setMonth(fecha.getMonth() + 1);
        }

        document.getElementById('formCurva').style.display = 'block';
        
        // Primera pasada de cálculos
        recalcularCascadaFRI(); 
    }

    // --- 2. CÁLCULO DE FRI EN CASCADA ---
    function recalcularCascadaFRI() {
        // Obtenemos el Mes Base para saber "cuánta inflación traemos antes de empezar la obra"
        const mesBaseStr = document.getElementById('mes_base').value; // Ej: 2025-12
        const filas = document.querySelectorAll('#gridBody tr');
        
        if(filas.length === 0) return;

        // Paso A: Calcular FRI Inicial (La mochila inflacionaria antes del mes 1 de obra)
        // Buscamos la fecha del primer periodo de la tabla
        let primerPeriodoTabla = filas[0].getAttribute('data-periodo');
        
        // Calculamos el acumulado desde MesBase hasta (PrimerPeriodo - 1 mes)
        // Usando la TABLA GLOBAL para ese tramo "histórico/previo"
        let friAcumulado = calcularFriHistorico(mesBaseStr, primerPeriodoTabla);

        // Paso B: Recorrer tabla y acumular con los INPUTS VISUALES
        filas.forEach(tr => {
            // Leemos lo que dice el input de esa fila (puede haber sido editado manualmente)
            let inflacionInput = parseFloat(tr.querySelector('.input-infl').value) || 0;
            
            // Formula FRI: Acumulado Anterior * (1 + InflaciónMes)
            friAcumulado = friAcumulado * (1 + (inflacionInput / 100));

            // Escribir el resultado en la columna FRI
            tr.querySelector('.input-fri').value = friAcumulado.toFixed(4);
            
            // Ya que cambió el FRI, recalculamos los $ de esta fila
            recalcularMontosFila(tr); 
        });

        actualizarFooter();
    }

    // Auxiliar: Calcula inflación usando datos globales para el periodo "muerto" entre Base e Inicio
    function calcularFriHistorico(mesBase, mesInicioObra) {
        if (mesInicioObra <= mesBase) return 1.0; // Sin ajuste si inicia antes de base (raro)
        
        // Logica simplificada: recorrer meses intermedios y buscar en indicesGlobales
        let [yB, mB] = mesBase.split('-').map(Number);
        let [yI, mI] = mesInicioObra.split('-').map(Number);
        
        let cursor = new Date(yB, mB - 1 + 1, 1); // Mes siguiente al base
        let fin = new Date(yI, mI - 1, 1); // Hasta mes inicio (exclusivo, porque mes inicio usa el input)
        
        let acumulado = 1.0;
        
        while(cursor < fin) { // < estricto, no incluimos el mes de inicio (ese se toma del input)
            let m = cursor.getMonth() + 1;
            let a = cursor.getFullYear();
            let pStr = a + '-' + (m < 10 ? '0'+m : m);
            
            // Buscar en global
            let pct = (indicesGlobales[pStr] !== undefined) ? indicesGlobales[pStr] : inflacionDefault;
            acumulado = acumulado * (1 + (pct / 100));
            
            cursor.setMonth(cursor.getMonth() + 1);
        }
        return acumulado;
    }


    // --- 3. CÁLCULO DE MONTOS ($) ---
    function recalcularMontosFila(tr) {
        const montoTotal = parseFloat(document.getElementById('monto_total').value) || 0;
        const pctAnticipo = parseFloat(document.getElementById('pct_anticipo').value) || 0;

        let pct = parseFloat(tr.querySelector('.input-pct').value) || 0;
        let fri = parseFloat(tr.querySelector('.input-fri').value) || 1;

        let bruto = montoTotal * (pct / 100);
        let ajustado = bruto * fri; // FRI acumulado
        let redet = ajustado - bruto;
        let recupero = bruto * (pctAnticipo / 100);
        let neto = (bruto + redet) - recupero;

        const fmt = (n) => n.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        tr.querySelector('.input-bruto').value = bruto.toFixed(2);
        tr.querySelector('.input-redet').value = redet.toFixed(2);
        tr.querySelector('.input-recupero').value = recupero.toFixed(2);
        tr.querySelector('.input-neto').value = neto.toFixed(2);
    }

    function recalcularTodo() {
        document.querySelectorAll('#gridBody tr').forEach(tr => recalcularMontosFila(tr));
        actualizarFooter();
    }
    
    function actualizarFooter() {
        let sumPct = 0, sumBruto = 0, sumRedet = 0, sumNeto = 0;
        document.querySelectorAll('#gridBody tr').forEach(tr => {
            sumPct += parseFloat(tr.querySelector('.input-pct').value) || 0;
            sumBruto += parseFloat(tr.querySelector('.input-bruto').value) || 0;
            sumRedet += parseFloat(tr.querySelector('.input-redet').value) || 0;
            sumNeto += parseFloat(tr.querySelector('.input-neto').value) || 0;
        });

        const fmt = (n) => '$ ' + n.toLocaleString('es-AR', {minimumFractionDigits: 2});
        document.getElementById('footPct').innerText = sumPct.toFixed(2) + '%';
        document.getElementById('footBruto').innerText = fmt(sumBruto);
        document.getElementById('footRedet').innerText = fmt(sumRedet);
        document.getElementById('footNeto').innerText = fmt(sumNeto);
    }

    // --- 4. MODAL MASIVO ---
    function agregarRango() {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td><input type="month" class="form-control form-control-sm rango-desde"></td>
                        <td><input type="month" class="form-control form-control-sm rango-hasta"></td>
                        <td><input type="number" step="0.1" class="form-control form-control-sm rango-pct"></td>
                        <td><button class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()">X</button></td>`;
        document.getElementById('bodyRangos').appendChild(tr);
    }

    function aplicarRangosMasivo() {
        // Leer rangos
        let rangos = [];
        document.querySelectorAll('#bodyRangos tr').forEach(tr => {
            let d = tr.querySelector('.rango-desde').value;
            let h = tr.querySelector('.rango-hasta').value;
            let p = parseFloat(tr.querySelector('.rango-pct').value);
            if(d && h && !isNaN(p)) rangos.push({ desde: d, hasta: h, pct: p });
        });

        // Aplicar a los INPUTS de la tabla
        document.querySelectorAll('#gridBody tr').forEach(tr => {
            let periodo = tr.getAttribute('data-periodo');
            for(let r of rangos) {
                if(periodo >= r.desde && periodo <= r.hasta) {
                    tr.querySelector('.input-infl').value = r.pct;
                }
            }
        });

        // Recalcular todo
        recalcularCascadaFRI();
        
        // Cerrar modal
        bootstrap.Modal.getInstance(document.getElementById('modalRangosFRI')).hide();
    }
</script>
</body>
</html>
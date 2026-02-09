<?php
// curva_form_generate.php
session_start();
require_once __DIR__ . '/../../config/database.php'; // Tu conexión $pdo
require_once __DIR__ . '/includes/CurvaCalculator.php';

$obraId = $_GET['obra_id'] ?? 0;
$mensaje = '';

// 1. Obtener datos básicos de la obra
$stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
$stmt->execute([$obraId]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$obra) die("Obra no encontrada");

// 2. Procesar Guardado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_curva'])) {
    try {
        $manager = new CurvaManager($pdo);
        $manager->guardarVersion($obraId, $_POST);
        // Redirigir al listado
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
    <title>Generar Curva y Proyección FRI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .table-input { width: 100%; border: none; background: transparent; text-align: right; }
        .table-input:focus { background: #fff; outline: 2px solid #86b7fe; }
        .bg-readonly { background-color: #f8f9fa; }
    </style>
</head>
<body class="bg-light">

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Generar Curva: <?= htmlspecialchars($obra['denominacion']) ?></h3>
        <a href="curva_listado.php" class="btn btn-outline-secondary">Cancelar</a>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-danger"><?= $mensaje ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">Parámetros Iniciales</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Monto Contrato ($)</label>
                    <input type="number" id="monto_total" class="form-control" 
                           value="<?= $obra['monto_contrato'] ?? 0 ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha Inicio</label>
                    <input type="date" id="fecha_inicio" class="form-control" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Plazo (Meses)</label>
                    <input type="number" id="plazo" class="form-control" value="12">
                </div>
                <div class="col-md-2">
                    <label class="form-label">% Anticipo</label>
                    <input type="number" id="pct_anticipo" class="form-control" value="20">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-primary w-100" onclick="generarGrillaBase()">
                        <i class="bi bi-table"></i> Generar Tabla
                    </button>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="formCurva" style="display:none;">
        <input type="hidden" name="guardar_curva" value="1">
        
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="p-3 bg-light border-bottom d-flex justify-content-between">
                    <div>
                        <strong>Distribución Mensual</strong>
                        <span class="text-muted small ms-2">Edite los porcentajes o el FRI manualmente.</span>
                    </div>
                    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalRangosFRI">
                        <i class="bi bi-graph-up-arrow"></i> Configurar Proyección FRI (Rangos)
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0 align-middle text-end" id="tablaCurva">
                        <thead class="table-light text-center">
                            <tr>
                                <th class="text-start">Periodo</th>
                                <th style="width: 80px;">% Avance</th>
                                <th>Monto Básico</th>
                                <th class="table-warning" style="width: 100px;">FRI (Coef)</th>
                                <th class="table-warning">Redeterm. ($)</th>
                                <th>Recupero Ant.</th>
                                <th>A Cobrar (Neto)</th>
                            </tr>
                        </thead>
                        <tbody id="gridBody">
                            </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td class="text-start">TOTALES</td>
                                <td id="footPct">0%</td>
                                <td id="footBruto">$0</td>
                                <td>-</td>
                                <td id="footRedet">$0</td>
                                <td id="footRecupero">$0</td>
                                <td id="footNeto">$0</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-check-circle"></i> Guardar Versión Definitiva
                </button>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="modalRangosFRI" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title">Proyección de Inflación por Rangos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Defina la inflación mensual estimada para cada tramo. El sistema calculará el interés compuesto acumulado mes a mes.</p>
        
        <table class="table table-sm table-bordered text-center">
            <thead class="table-light">
                <tr>
                    <th>Desde</th>
                    <th>Hasta</th>
                    <th>% Inflación Mes</th>
                    <th style="width:50px;"></th>
                </tr>
            </thead>
            <tbody id="bodyRangos">
                <tr>
                    <td><input type="month" class="form-control form-control-sm rango-desde"></td>
                    <td><input type="month" class="form-control form-control-sm rango-hasta"></td>
                    <td><input type="number" step="0.1" class="form-control form-control-sm rango-pct" placeholder="2.0"></td>
                    <td><button class="btn btn-outline-danger btn-sm" onclick="eliminarRango(this)">&times;</button></td>
                </tr>
            </tbody>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarRango()">+ Agregar Tramo</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-primary" onclick="aplicarRangosFRI()">Aplicar a la Tabla</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- VARIABLES GLOBALES ---
    let montoTotal = 0;
    let pctAnticipo = 0;

    // --- 1. GENERACIÓN DE ESTRUCTURA ---
    function generarGrillaBase() {
        // Leer inputs
        montoTotal = parseFloat(document.getElementById('monto_total').value) || 0;
        pctAnticipo = parseFloat(document.getElementById('pct_anticipo').value) || 0;
        const plazo = parseInt(document.getElementById('plazo').value) || 12;
        let fecha = new Date(document.getElementById('fecha_inicio').value + 'T00:00:00'); // Fix zona horaria simple
        
        // Validar
        if(montoTotal <= 0) { alert("Ingrese monto contrato"); return; }

        const tbody = document.getElementById('gridBody');
        tbody.innerHTML = ''; // Limpiar

        // Generar filas
        for(let i=0; i<plazo; i++) {
            // Formato YYYY-MM
            let mes = fecha.getMonth() + 1;
            let anio = fecha.getFullYear();
            let periodoStr = anio + '-' + (mes < 10 ? '0'+mes : mes); // 2025-01
            
            // Calculo simple de curva S (aprox) o lineal para empezar
            // Aquí pongo lineal, el usuario edita. O puedes poner 0.
            let pctSugerido = (100 / plazo).toFixed(2); 

            let tr = document.createElement('tr');
            tr.setAttribute('data-periodo', periodoStr); // CLAVE PARA EL MODAL DE RANGOS
            
            tr.innerHTML = `
                <td class="text-start bg-readonly">
                    ${periodoStr}
                    <input type="hidden" name="items[${i}][periodo]" value="${periodoStr}">
                </td>
                <td>
                    <input type="number" step="0.01" class="form-control form-control-sm text-end input-pct" 
                           name="items[${i}][pct]" value="${pctSugerido}" onchange="recalcularFila(this)">
                </td>
                <td class="bg-readonly">
                    <input type="text" readonly class="table-input input-bruto" name="items[${i}][bruto]" value="0">
                </td>
                <td class="table-warning">
                    <input type="number" step="0.0001" class="form-control form-control-sm text-end input-fri" 
                           name="items[${i}][fri]" value="1.0000" onchange="recalcularFila(this)">
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

            // Avanzar mes
            fecha.setMonth(fecha.getMonth() + 1);
        }

        document.getElementById('formCurva').style.display = 'block';
        recalcularTodo(); // Primera pasada
    }

    // --- 2. CÁLCULOS POR FILA ---
    function recalcularFila(inputElement) {
        let tr = inputElement.closest('tr');
        
        // Leer valores actuales de la fila
        let pct = parseFloat(tr.querySelector('.input-pct').value) || 0;
        let fri = parseFloat(tr.querySelector('.input-fri').value) || 1.0;

        // Cálculos Matematicos
        let montoBruto = montoTotal * (pct / 100);
        
        // El FRI se aplica sobre el bruto: BrutoAjustado = Bruto * FRI
        // La redeterminación es la diferencia.
        let montoAjustado = montoBruto * fri;
        let redet = montoAjustado - montoBruto;

        // Recupero de anticipo (proporcional al avance físico)
        // Monto Anticipo Total = montoTotal * (pctAnticipo/100)
        // Recupero Mes = Monto Anticipo Total * (AvanceMes / 100) -> NO.
        // Recupero Mes = (MontoTotal * pctAnticipo%) * (AvanceMes%) -> ERROR COMÚN
        // Fórmula correcta de descuento proporcional: 
        // Recupero = CertificadoBruto * %AnticipoOtorgado (así se descuenta parejo)
        let recupero = montoBruto * (pctAnticipo / 100);

        // Neto
        let neto = (montoBruto + redet) - recupero;

        // Escribir en inputs (Formato visual y valor para POST)
        tr.querySelector('.input-bruto').value = montoBruto.toFixed(2);
        tr.querySelector('.input-redet').value = redet.toFixed(2);
        tr.querySelector('.input-recupero').value = recupero.toFixed(2);
        tr.querySelector('.input-neto').value = neto.toFixed(2);

        actualizarTotalesFooter();
    }

    function recalcularTodo() {
        document.querySelectorAll('#gridBody tr').forEach(tr => {
            // Buscamos un input cualquiera dentro de la fila para disparar la función
            recalcularFila(tr.querySelector('.input-pct')); 
        });
    }

    function actualizarTotalesFooter() {
        let tPct=0, tBruto=0, tRedet=0, tRecup=0, tNeto=0;
        
        document.querySelectorAll('#gridBody tr').forEach(tr => {
            tPct += parseFloat(tr.querySelector('.input-pct').value) || 0;
            tBruto += parseFloat(tr.querySelector('.input-bruto').value) || 0;
            tRedet += parseFloat(tr.querySelector('.input-redet').value) || 0;
            tRecup += parseFloat(tr.querySelector('.input-recupero').value) || 0;
            tNeto += parseFloat(tr.querySelector('.input-neto').value) || 0;
        });

        // Formatear moneda ARS
        const fmt = (num) => '$ ' + num.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        document.getElementById('footPct').innerText = tPct.toFixed(2) + '%';
        document.getElementById('footBruto').innerText = fmt(tBruto);
        document.getElementById('footRedet').innerText = fmt(tRedet);
        document.getElementById('footRecupero').innerText = fmt(tRecup);
        document.getElementById('footNeto').innerText = fmt(tNeto);
        
        // Alerta visual si no suma 100
        document.getElementById('footPct').className = (Math.abs(tPct - 100) < 0.1) ? 'text-success fw-bold' : 'text-danger fw-bold';
    }

    // --- 3. LÓGICA DE RANGOS FRI (LO QUE PEDISTE) ---
    
    function agregarRango() {
        const tbody = document.getElementById('bodyRangos');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="month" class="form-control form-control-sm rango-desde"></td>
            <td><input type="month" class="form-control form-control-sm rango-hasta"></td>
            <td><input type="number" step="0.1" class="form-control form-control-sm rango-pct" value="0.0"></td>
            <td><button class="btn btn-outline-danger btn-sm" onclick="eliminarRango(this)">&times;</button></td>
        `;
        tbody.appendChild(tr);
    }
    
    function eliminarRango(btn) { btn.closest('tr').remove(); }

    function aplicarRangosFRI() {
        // 1. Leer configuración del modal
        let rangos = [];
        document.querySelectorAll('#bodyRangos tr').forEach(tr => {
            let d = tr.querySelector('.rango-desde').value;
            let h = tr.querySelector('.rango-hasta').value;
            let p = parseFloat(tr.querySelector('.rango-pct').value) || 0;
            if(d && h) rangos.push({ desde: d, hasta: h, factor: 1 + (p/100) });
        });

        // 2. Iterar sobre la tabla principal y aplicar interés compuesto
        let friAcumulado = 1.0000;
        
        document.querySelectorAll('#gridBody tr').forEach((tr, index) => {
            let periodoFila = tr.getAttribute('data-periodo'); // ej "2025-01"
            let inflacionMes = 1.0; // Neutro

            // Buscar si el mes cae en algún rango
            for(let r of rangos) {
                // Comparación lexicográfica de strings YYYY-MM funciona perfecto
                if(periodoFila >= r.desde && periodoFila <= r.hasta) {
                    inflacionMes = r.factor;
                    break;
                }
            }

            // Calculamos el acumulado PARA este mes
            friAcumulado = friAcumulado * inflacionMes;

            // Asignamos al input
            tr.querySelector('.input-fri').value = friAcumulado.toFixed(4);

            // Importante: Recalcular montos de la fila
            recalcularFila(tr.querySelector('.input-fri'));
        });

        // Cerrar modal
        const modalEl = document.getElementById('modalRangosFRI');
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        modalInstance.hide();
    }
</script>

</body>
</html>
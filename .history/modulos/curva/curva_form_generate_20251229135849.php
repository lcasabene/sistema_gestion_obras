<?php
// curva_form_generate.php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/includes/CurvaCalculator.php'; // Asegúrate que esta ruta exista o comenta si no la usas aún

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
    // Aquí iría tu lógica de guardado. 
    // Recuerda des-formatear los números (quitar puntos) antes de guardar en BD.
    try {
        // Ejemplo simplificado:
        // $manager = new CurvaManager($pdo);
        // $manager->guardarVersion($obraId, $_POST);
        // header("Location: curva_listado.php?msg=ok");
        // exit;
        $mensaje = "Simulación: Datos listos para guardar (implementar lógica de BD).";
    } catch (Exception $e) {
        $mensaje = "Error al guardar: " . $e->getMessage();
    }
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
        /* Estilos para inputs que parecen texto plano */
        .table-input { 
            width: 100%; 
            border: none; 
            background: transparent; 
            text-align: right; 
            font-size: 0.9rem; 
            font-family: 'Consolas', monospace; /* Para alinear números */
            font-weight: 600;
        }
        .table-input:focus { background: #fff; outline: 2px solid #86b7fe; }
        
        .bg-readonly { background-color: #f8f9fa; }
        
        /* Colores para inputs editables */
        .input-pct { color: #0d6efd; font-weight: bold; }
        .input-infl { color: #d63384; font-weight: bold; } 
        
        /* Negativos */
        .text-negativo { color: #dc3545 !important; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="bi bi-bar-chart-steps"></i> Nueva Curva: <?= htmlspecialchars($obra['denominacion']) ?></h3>
        <a href="curva_listado.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-header bg-primary text-white fw-bold">1. Parámetros de Cálculo</div>
        <div class="card-body bg-light">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Monto Contrato</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" id="monto_total" class="form-control fw-bold" 
                               value="<?= $obra['monto_contrato'] ?? 0 ?>">
                    </div>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-primary">Mes Base (Índice 1.0)</label>
                    <input type="month" id="mes_base" class="form-control border-primary fw-bold" 
                           value="<?= date('Y-m', strtotime('-1 month')) ?>">
                    <div class="form-text" style="font-size: 11px">Base para inflación 0.</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label small fw-bold text-success">Fecha ANTICIPO</label>
                    <input type="date" id="fecha_anticipo" class="form-control border-success fw-bold" 
                           value="<?= date('Y-m-d') ?>">
                </div>

                <div class="col-md-1">
                    <label class="form-label small fw-bold">% Anticipo</label>
                    <input type="number" id="pct_anticipo" class="form-control" value="10">
                </div>

                <div class="col-md-2">
                    <label class="form-label small fw-bold">Inicio de Obra</label>
                    <input type="date" id="fecha_inicio_obra" class="form-control" 
                           value="<?= date('Y-m-01', strtotime('+1 month')) ?>">
                </div>
                
                <div class="col-md-1">
                    <label class="form-label small fw-bold">Plazo (Meses)</label>
                    <input type="number" id="plazo" class="form-control" value="12">
                </div>

                <div class="col-md-2">
                    <button type="button" class="btn btn-primary w-100 fw-bold shadow-sm" onclick="generarGrillaBase()">
                        <i class="bi bi-table"></i> Generar Tabla
                    </button>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" id="formCurva" style="display:none;">
        <input type="hidden" name="guardar_curva" value="1">
        
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold text-uppercase"><i class="bi bi-calendar3"></i> Proyección Financiera y Redeterminaciones</span>
                
                <button type="button" class="btn btn-warning btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalRangosFRI">
                    <i class="bi bi-graph-up-arrow"></i> Proyectar Inflación Masiva
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0 align-middle" style="font-size: 0.85rem;">
                        <thead class="table-light text-center small text-uppercase align-middle">
                            <tr>
                                <th class="text-start" style="width: 100px;">Periodo</th>
                                <th class="text-start">Concepto</th>
                                <th style="width: 70px;">% Físico</th>
                                <th>Monto Base</th>
                                <th style="width: 70px; color:#d63384;">% Infl.</th> 
                                <th class="table-warning" style="width: 80px;">FRI Acum.</th>
                                <th class="table-warning">Redeterminación</th>
                                <th class="text-danger">Recupero Antic.</th>
                                <th class="bg-primary text-white">Neto a Cobrar</th>
                            </tr>
                        </thead>
                        <tbody id="gridBody">
                            </tbody>
                        <tfoot class="table-light fw-bold small border-top-3">
                            <tr style="font-size: 1rem;">
                                <td colspan="3" class="text-end">TOTALES CONSOLIDADOS:</td>
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
                <button type="submit" class="btn btn-success px-4 fw-bold">
                    <i class="bi bi-save"></i> Guardar Versión Definitiva
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
        <div class="alert alert-warning py-2 small">Defina rangos de fechas para aplicar un % de inflación mensual estimado.</div>
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

    // --- FORMATEO MONEDA (Argentina) ---
    // Convierte 1234.56 -> "1.234,56"
    function formatMoney(num) {
        if(num === null || num === undefined) return "0,00";
        return num.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // --- 1. GENERAR TABLA ---
    function generarGrillaBase() {
        // Obtenemos valores
        const montoTotal = parseFloat(document.getElementById('monto_total').value) || 0;
        const pctAnticipo = parseFloat(document.getElementById('pct_anticipo').value) || 0;
        const plazo = parseInt(document.getElementById('plazo').value) || 12;
        const fechaInicio = document.getElementById('fecha_inicio_obra').value;
        const fechaAnticipo = document.getElementById('fecha_anticipo').value;
        const mesBase = document.getElementById('mes_base').value; // YYYY-MM
        
        if(montoTotal <= 0) { alert("Ingrese monto contrato"); return; }
        if(!fechaInicio || !fechaAnticipo) { alert("Revise las fechas"); return; }

        const tbody = document.getElementById('gridBody');
        tbody.innerHTML = '';
        
        // --- A. FILA ANTICIPO (AF) ---
        // Se calcula aparte porque tiene fecha distinta y lógica distinta (Recupero 0)
        let trAF = crearFilaHTML({
            tipo: 'anticipo',
            idx: 'af',
            concepto: 'Anticipo Financiero (AF)',
            fecha: fechaAnticipo,
            pctSug: pctAnticipo, // El % físico del anticipo es el % del anticipo
            readonlyPct: false,
            esAnticipo: true
        });
        tbody.appendChild(trAF);

        // --- B. FILAS CERTIFICADOS (Mes 1 a N) ---
        // El saldo físico a distribuir es 100% - %Anticipo (si es que el AF cuenta como avance fisico, 
        // pero usualmente la curva física suma 100% en obra + anticipo financiero aparte.
        // ASUNCIÓN ESTÁNDAR: La suma de avance FISICO de los certificados debe dar 100%. 
        // El anticipo es financiero. 
        // Ajuste: Pondremos avance físico distribuido en el plazo.
        
        let fechaCursor = new Date(fechaInicio + 'T00:00:00');
        let pctMensual = (100 / plazo).toFixed(3); // Distribución lineal simple inicial

        for(let i=1; i<=plazo; i++) {
            let mes = fechaCursor.getMonth() + 1;
            let anio = fechaCursor.getFullYear();
            let periodoStr = anio + '-' + (mes < 10 ? '0'+mes : mes); // 2026-01
            let periodoDia = fechaCursor.toISOString().split('T')[0];

            let tr = crearFilaHTML({
                tipo: 'certificado',
                idx: i,
                concepto: 'Certificado Nº ' + i,
                fecha: periodoDia, // Usamos día 1 del mes para referencias
                periodoVisual: periodoStr,
                pctSug: pctMensual,
                readonlyPct: false,
                esAnticipo: false
            });
            tbody.appendChild(tr);

            // Avanzar mes
            fechaCursor.setMonth(fechaCursor.getMonth() + 1);
        }

        document.getElementById('formCurva').style.display = 'block';
        
        // Ejecutar cálculos iniciales
        recalcularCascadaFRI(); 
    }

    // --- HELPER PARA CREAR HTML DE FILA ---
    function crearFilaHTML(p) {
        let tr = document.createElement('tr');
        // Guardamos metadatos en atributos data
        tr.setAttribute('data-tipo', p.tipo); 
        tr.setAttribute('data-fecha', p.fecha); // Fecha completa YYYY-MM-DD
        
        // Buscamos inflación por defecto para esa fecha (YYYY-MM)
        let periodoYM = p.fecha.substring(0, 7);
        let inflacionMes = (indicesGlobales[periodoYM] !== undefined) ? indicesGlobales[periodoYM] : inflacionDefault;

        let estiloFila = p.esAnticipo ? 'table-warning border-bottom border-dark' : '';
        let estiloConcepto = p.esAnticipo ? 'fw-bold' : '';

        tr.className = estiloFila;
        tr.innerHTML = `
            <td class="text-start bg-light fw-bold text-muted small">
                ${periodoYM}
                <input type="hidden" name="items[${p.idx}][fecha]" value="${p.fecha}">
            </td>
            <td class="${estiloConcepto}">
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

    // --- 2. CÁLCULO DE FRI (Lógica Especial Anticipo vs Certificados) ---
    function recalcularCascadaFRI() {
        const mesBaseStr = document.getElementById('mes_base').value; // YYYY-MM
        const filas = document.querySelectorAll('#gridBody tr');
        
        let friAcumuladoCertificados = 1.0; 
        let inicioCertificadosCalculado = false;

        filas.forEach((tr, index) => {
            let tipo = tr.getAttribute('data-tipo');
            let fechaFila = tr.getAttribute('data-fecha'); // YYYY-MM-DD
            let periodoYM = fechaFila.substring(0, 7);

            let friFila = 1.0;

            if (tipo === 'anticipo') {
                // EL ANTICIPO TIENE SU PROPIA LÓGICA: Desde Mes Base -> Hasta Mes Anticipo
                friFila = calcularFriHistorico(mesBaseStr, periodoYM); 
                // Nota: El anticipo NO usa el input de "Inflación Mes" de su propia fila para acumularse a sí mismo,
                // usa la historia hasta ese momento.
            } else {
                // CERTIFICADOS: Lógica de cascada
                // Si es el primer certificado, necesitamos calcular la "mochila" histórica hasta el mes anterior al inicio
                if (!inicioCertificadosCalculado) {
                    // Calculamos base hasta el mes anterior al primer certificado
                    friAcumuladoCertificados = calcularFriHistorico(mesBaseStr, periodoYM);
                    inicioCertificadosCalculado = true;
                }
                
                // Multiplicamos por la inflación de ESTE mes (la que dice el input)
                let inflacionInput = parseFloat(tr.querySelector('.input-infl').value) || 0;
                friAcumuladoCertificados = friAcumuladoCertificados * (1 + (inflacionInput / 100));
                
                friFila = friAcumuladoCertificados;
            }

            // Escribir el resultado
            tr.querySelector('.input-fri').value = friFila.toFixed(4);
            
            // Gatillar calculo de $
            recalcularMontosFila(tr); 
        });

        actualizarFooter();
    }

    // Calcula inflación acumulada desde Mes Base (exclusivo) hasta Mes Destino (exclusivo) usando tabla global
    function calcularFriHistorico(mesBase, mesDestino) {
        if (mesDestino <= mesBase) return 1.0;
        
        let [yB, mB] = mesBase.split('-').map(Number);
        let [yD, mD] = mesDestino.split('-').map(Number);
        
        let cursor = new Date(yB, mB - 1 + 1, 1); // Mes siguiente al base
        let fin = new Date(yD, mD - 1, 1); // Hasta el mes destino
        
        let acumulado = 1.0;
        
        // Loop de seguridad (max 10 años)
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

    // --- 3. CÁLCULO FINANCIERO ($) ---
    function recalcularMontosFila(tr) {
        const montoTotal = parseFloat(document.getElementById('monto_total').value) || 0;
        const pctAnticipo = parseFloat(document.getElementById('pct_anticipo').value) || 0;
        const tipo = tr.getAttribute('data-tipo');

        // A. Obtener datos crudos
        let pctFisico = parseFloat(tr.querySelector('.input-pct').value) || 0;
        let fri = parseFloat(tr.querySelector('.input-fri').value) || 1;

        // B. Cálculos
        // Si es Anticipo, el "Avance Físico" en realidad representa el % financiero del anticipo
        // Si es Certificado, el "Avance Físico" es avance de obra
        
        let montoBase = 0;
        if(tipo === 'anticipo') {
            // El monto base del anticipo se calcula sobre el total contrato * % Anticipo input general?
            // O usamos el input pct de la fila? Usemos el de la fila para flexibilidad.
            // Pero usualmente Anticipo es monto fijo.
            // Usemos lógica estricta: Monto Base = Contrato * % Fila
            montoBase = montoTotal * (pctFisico / 100); 
        } else {
            // Certificado normal
            montoBase = montoTotal * (pctFisico / 100);
        }

        let montoRedeterminado = montoBase * fri; 
        let redet = montoRedeterminado - montoBase;
        
        // C. Lógica Recupero
        let recupero = 0;
        if (tipo === 'anticipo') {
            recupero = 0; // No se recupera nada al cobrar el anticipo
        } else {
            // En certificados: Descontamos el % de Anticipo sobre el TOTAL (Base + Redet)
            // Para mantener el equilibrio financiero del anticipo otorgado.
            let montoBrutoConRedet = montoBase + redet;
            recupero = montoBrutoConRedet * (pctAnticipo / 100);
        }

        let neto = (montoBase + redet) - recupero;

        // D. Volcar a inputs VISUALES (usando formatMoney)
        tr.querySelector('.input-bruto').value = formatMoney(montoBase);
        tr.querySelector('.input-redet').value = formatMoney(redet);
        tr.querySelector('.input-recupero').value = recupero > 0 ? "- " + formatMoney(recupero) : "0,00";
        tr.querySelector('.input-neto').value = formatMoney(neto);
        
        // Si hay un negativo real (neto < 0), ponerlo en rojo
        if(neto < 0) tr.querySelector('.input-neto').classList.add('text-negativo');
        else tr.querySelector('.input-neto').classList.remove('text-negativo');
    }

    function recalcularTodo() {
        document.querySelectorAll('#gridBody tr').forEach(tr => recalcularMontosFila(tr));
        actualizarFooter();
    }
    
    // Función auxiliar para sumar columnas parseando el formato "1.234,56"
    function parseLocalFloat(str) {
        if(!str) return 0;
        // Quitar " $" o "-"
        let clean = str.replace('$','').replace(' ','').replace('-',''); 
        // Quitar puntos de miles
        clean = clean.replace(/\./g, '');
        // Reemplazar coma por punto
        clean = clean.replace(',', '.');
        return parseFloat(clean) || 0;
    }

    function actualizarFooter() {
        let sumBase = 0, sumRedet = 0, sumRecup = 0, sumNeto = 0;
        
        document.querySelectorAll('#gridBody tr').forEach(tr => {
            sumBase += parseLocalFloat(tr.querySelector('.input-bruto').value);
            sumRedet += parseLocalFloat(tr.querySelector('.input-redet').value);
            // El recupero visualmente puede tener un guion, parseLocalFloat lo maneja
            sumRecup += parseLocalFloat(tr.querySelector('.input-recupero').value);
            sumNeto += parseLocalFloat(tr.querySelector('.input-neto').value);
        });

        document.getElementById('footBase').innerText = '$ ' + formatMoney(sumBase);
        document.getElementById('footRedet').innerText = '$ ' + formatMoney(sumRedet);
        document.getElementById('footRecupero').innerText = '- $ ' + formatMoney(sumRecup);
        document.getElementById('footNeto').innerText = '$ ' + formatMoney(sumNeto);
    }

    // --- MODAL (Copiar pegar lógica anterior) ---
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
            for(let r of rangos) {
                if(periodo >= r.desde && periodo <= r.hasta) {
                    tr.querySelector('.input-infl').value = r.pct;
                }
            }
        });
        recalcularCascadaFRI();
        bootstrap.Modal.getInstance(document.getElementById('modalRangosFRI')).hide();
    }
</script>
</body>
</html>
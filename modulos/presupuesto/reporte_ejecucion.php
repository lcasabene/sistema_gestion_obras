<?php
// ---------------------------------------------------------
// 1. INCLUDES Y CONFIGURACIÓN
// ---------------------------------------------------------
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/middleware.php';

// DETECCIÓN AUTOMÁTICA DE CONEXIÓN
global $conn;
if (!isset($conn) && isset($pdo)) { $conn = $pdo; }
if (!isset($conn) && isset($db)) { $conn = $db; }

if (!$conn) {
    die("<div class='alert alert-danger'>Error Crítico: No se encuentra la variable de conexión a la BD. Verifique config/database.php</div>");
}

// HEADER
include __DIR__ . '/../../public/_header.php'; 

// ---------------------------------------------------------
// 2. LÓGICA DE DATOS
// ---------------------------------------------------------

// A. Filtros
$fecha_corte = $_GET['fecha_corte'] ?? date('Y-m-01');
$anio_filtro = date('Y', strtotime($fecha_corte));
$modo = $_GET['modo'] ?? 'presupuestario'; // presupuestario | financiero

// B. Consulta SQL - Valores de la curva vigente (incluye anticipos)
$sql = "SELECT 
            o.id, 
            o.denominacion, 
            ci.periodo as fecha, 
            ci.concepto,
            COALESCE(ci.monto_base, 0) as monto_base,
            COALESCE(ci.redeterminacion, 0) as redeterminacion,
            (COALESCE(ci.monto_base, 0) + COALESCE(ci.redeterminacion, 0)) as monto_presupuestario,
            COALESCE(ci.neto, 0) as monto_financiero
        FROM obras o
        
        -- 1. Curva Versión (Vigente)
        LEFT JOIN curva_version cv 
            ON o.id = cv.obra_id AND cv.es_vigente = 1
            
        -- 2. Items de la Curva (incluye anticipos)
        LEFT JOIN curva_items ci 
            ON cv.id = ci.version_id 
            AND ci.periodo >= :fecha_corte
            
        WHERE o.activo = 1 
        ORDER BY o.denominacion ASC, ci.periodo ASC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fecha_corte' => $fecha_corte]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger m-3'><strong>Error SQL:</strong> " . $e->getMessage() . "</div>";
    $resultados = [];
}

// C. Procesamiento (Pivote por Obra)
$obras = [];
$todos_los_meses = [];

foreach ($resultados as $fila) {
    $id = $fila['id'];
    
    if (!isset($obras[$id])) {
        $obras[$id] = [
            'denominacion' => $fila['denominacion'],
            'proyecciones' => [] 
        ];
    }

    if (!empty($fila['fecha'])) {
        $mes_clave = date('Y-m', strtotime($fila['fecha'])); 
        
        if (!isset($obras[$id]['proyecciones'][$mes_clave])) {
            $obras[$id]['proyecciones'][$mes_clave] = 0;
        }
        
        // Según el modo, usamos monto_presupuestario o monto_financiero
        $monto = ($modo === 'financiero') 
            ? $fila['monto_financiero'] 
            : $fila['monto_presupuestario'];
        
        $obras[$id]['proyecciones'][$mes_clave] += $monto;
        
        if (!in_array($mes_clave, $todos_los_meses)) {
            $todos_los_meses[] = $mes_clave;
        }
    }
}
sort($todos_los_meses);

// Etiquetas según modo
$modo_label = ($modo === 'financiero') ? 'Financiero (Neto)' : 'Presupuestario (Base + Redet)';
$modo_badge_class = ($modo === 'financiero') ? 'bg-success' : 'bg-primary';
?>

<div class="content-wrapper" style="padding: 20px;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Proyección de Ejecución Financiera</h1>
    
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="../../public/menu.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Volver al Menú
        </a>
    </div>
</div>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-auto">
                    <label for="fecha_corte" class="form-label fw-bold">Proyectar desde:</label>
                    <input type="date" class="form-control" name="fecha_corte" id="fecha_corte" value="<?php echo $fecha_corte; ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label fw-bold">Tipo de reporte:</label>
                    <select name="modo" class="form-select" id="selectModo">
                        <option value="presupuestario" <?php echo ($modo === 'presupuestario') ? 'selected' : ''; ?>>Presupuestario</option>
                        <option value="financiero" <?php echo ($modo === 'financiero') ? 'selected' : ''; ?>>Financiero</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Actualizar
                    </button>
                </div>
                <div class="col-auto ms-auto">
                    <span class="badge <?php echo $modo_badge_class; ?> fs-6 py-2 px-3">
                        <i class="fas fa-<?php echo ($modo === 'financiero') ? 'money-bill-wave' : 'file-invoice-dollar'; ?> me-1"></i>
                        <?php echo $modo_label; ?>
                    </span>
                </div>
            </form>
            <div class="mt-2">
                <small class="text-muted">
                    <?php if ($modo === 'financiero'): ?>
                        <i class="fas fa-info-circle"></i> <strong>Financiero:</strong> Muestra el <em>neto</em> de cada período (bruto + redeterminación − recupero de anticipo). Refleja el flujo de caja real.
                    <?php else: ?>
                        <i class="fas fa-info-circle"></i> <strong>Presupuestario:</strong> Muestra <em>monto base + redeterminación</em> de cada período. Refleja el impacto bruto en el presupuesto.
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaReporte" class="table table-bordered table-hover w-100 table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th class="align-middle">Obra / Proyecto</th>
                            <th class="align-middle text-end">Total Proyectado</th>
                            
                            <?php foreach ($todos_los_meses as $mes): 
                                $dt = DateTime::createFromFormat('Y-m', $mes);
                                $nombres = ["Jan"=>"Ene","Feb"=>"Feb","Mar"=>"Mar","Apr"=>"Abr","May"=>"May","Jun"=>"Jun","Jul"=>"Jul","Aug"=>"Ago","Sep"=>"Sep","Oct"=>"Oct","Nov"=>"Nov","Dec"=>"Dic"];
                                $label = $nombres[$dt->format('M')] . " " . $dt->format('y');
                            ?>
                                <th class="align-middle text-center bg-secondary" style="min-width: 80px;"><?php echo $label; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($obras as $obra): 
                            $total_obra = array_sum($obra['proyecciones']);
                        ?>
                        <tr>
                            <td class="fw-bold text-primary">
                                <?php echo htmlspecialchars($obra['denominacion']); ?>
                            </td>
                            <td class="text-end fw-bold">
                                <?php if($total_obra != 0): ?>
                                    $ <?php echo number_format($total_obra, 2, ',', '.'); ?>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            
                            <?php foreach ($todos_los_meses as $mes): ?>
                                <td class="text-end">
                                    <?php 
                                        $val = $obra['proyecciones'][$mes] ?? 0;
                                        if ($val != 0) {
                                            echo number_format($val, 2, ',', '.');
                                        } else {
                                            echo '<span class="text-muted small">-</span>';
                                        }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary fw-bold">
                            <td class="text-end">TOTALES:</td>
                            <td class="text-end">
                                <?php 
                                    $gran_total = 0;
                                    foreach ($obras as $o) { $gran_total += array_sum($o['proyecciones']); }
                                    echo '$ ' . number_format($gran_total, 2, ',', '.');
                                ?>
                            </td>
                            <?php foreach ($todos_los_meses as $mes): 
                                $sum = 0;
                                foreach ($obras as $o) {
                                    $sum += ($o['proyecciones'][$mes] ?? 0);
                                }
                            ?>
                            <td class="text-end text-dark">$ <?php echo number_format($sum, 0, ',', '.'); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    var modo = '<?php echo $modo; ?>';
    var tituloReporte = 'Proyeccion_' + (modo === 'financiero' ? 'Financiera' : 'Presupuestaria') + '_' + $('#fecha_corte').val();

    $('#tablaReporte').DataTable({
        dom: 'Bfrtip',
        paging: false,
        scrollX: true,
        ordering: true,
        order: [[0, 'asc']],
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel"></i> Exportar Excel',
                className: 'btn btn-success btn-sm me-2',
                title: tituloReporte,
                footer: true
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Imprimir',
                className: 'btn btn-secondary btn-sm',
                orientation: 'landscape',
                footer: true
            }
        ],
        language: {
            url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
        }
    });
});
</script>

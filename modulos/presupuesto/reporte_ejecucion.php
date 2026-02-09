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

// A. Filtro de fecha
$fecha_corte = $_GET['fecha_corte'] ?? date('Y-m-01');

// B. Consulta SQL
// CAMBIOS: 
// 1. Traemos 'monto_disp' explícitamente.
// 2. Corregimos el JOIN de presupuesto a 'pe.obra_id' (según lo hablado anteriormente).
$sql = "SELECT 
            o.id, 
            o.denominacion, 
            pe.monto_def,   -- Para referencia
            pe.monto_disp,  -- Para validación (semáforo rojo)
            pe.fufi,
            ci.periodo as fecha, 
            (COALESCE(ci.monto_base, 0) + COALESCE(ci.redeterminacion, 0)) as monto_proyectado
        FROM obras o
        
        -- 1. Presupuesto (Usamos obra_id para vincular correctamente)
        LEFT JOIN presupuesto_ejecucion pe 
            ON o.id = pe.id
            
        -- 2. Curva Versión (Vigente)
        LEFT JOIN curva_version cv 
            ON o.id = cv.obra_id AND cv.es_vigente = 1
            
        -- 3. Items de la Curva
        LEFT JOIN curva_items ci 
            ON cv.id = ci.version_id AND ci.periodo >= :fecha_corte
            
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

// C. Procesamiento (Pivote)
$obras = [];
$todos_los_meses = [];

foreach ($resultados as $fila) {
    $id = $fila['id'];
    
    if (!isset($obras[$id])) {
        $obras[$id] = [
            'denominacion' => $fila['denominacion'],
            'monto_def'    => $fila['monto_def'] ?? 0,
            'monto_disp'   => $fila['monto_disp'] ?? 0, // Guardamos el disponible real
            'fufi'         => $fila['fufi'] ?? '',
            'proyecciones' => [] 
        ];
    }

    if (!empty($fila['fecha'])) {
        $mes_clave = date('Y-m', strtotime($fila['fecha'])); 
        
        if (!isset($obras[$id]['proyecciones'][$mes_clave])) {
            $obras[$id]['proyecciones'][$mes_clave] = 0;
        }
        
        $obras[$id]['proyecciones'][$mes_clave] += $fila['monto_proyectado'];
        
        if (!in_array($mes_clave, $todos_los_meses)) {
            $todos_los_meses[] = $mes_clave;
        }
    }
}
sort($todos_los_meses);
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
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Actualizar
                    </button>
                </div>
                <div class="col-auto ms-auto">
                     <div class="d-flex align-items-center">
                        <div style="width: 20px; height: 20px; background-color: #f8d7da; border: 1px solid #f5c6cb; margin-right: 10px;"></div>
                        <span class="text-muted small">Rojo: Proyección Anual > Disponible</span>
                     </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaReporte" class="table table-bordered table-hover w-100 table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th class="align-middle">Obra / Proyecto</th>
                            <th class="align-middle text-center">FUFI</th>
                            <th class="align-middle text-end">Monto Disponible</th>
                            
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
                        <?php 
                        // Calculamos el año actual del filtro para la validación
                        $anio_filtro = date('Y', strtotime($fecha_corte));

                        foreach ($obras as $obra): 
                            
                            // LÓGICA DE VALIDACIÓN (ROJO)
                            $suma_proyecciones_anio = 0;
                            foreach ($obra['proyecciones'] as $mes => $monto) {
                                // Sumamos solo si el mes pertenece al año del filtro (ej: empieza con "2026")
                                if (strpos($mes, $anio_filtro) === 0) {
                                    $suma_proyecciones_anio += $monto;
                                }
                            }

                            // Si lo disponible es menor a lo proyectado para el año -> ERROR
                            // (Manejamos posible null en monto_disp tratándolo como 0)
                            $monto_disp = floatval($obra['monto_disp']);
                            $es_deficit = ($monto_disp < $suma_proyecciones_anio);
                            
                            // Clase CSS para pintar la fila
                            $clase_fila = $es_deficit ? 'table-danger' : '';
                        ?>
                        <tr class="<?php echo $clase_fila; ?>">
                            <td class="fw-bold text-primary">
                                <?php echo htmlspecialchars($obra['denominacion']); ?>
                                <?php if($es_deficit): ?>
                                    <i class="fas fa-exclamation-triangle text-danger ms-1" title="Déficit: Proyección anual supera el disponible"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo htmlspecialchars($obra['fufi']); ?></td>
                            <td class="text-end fw-bold" title="Definitivo: $ <?php echo number_format($obra['monto_def'], 2, ',', '.'); ?>">
                                $ <?php echo number_format($obra['monto_disp'], 2, ',', '.'); ?>
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
                            <td colspan="3" class="text-end">TOTALES:</td>
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
    var tituloReporte = 'Proyeccion_Ejecucion_' + $('#fecha_corte').val();

    $('#tablaReporte').DataTable({
        dom: 'Bfrtip',
        paging: false,
        scrollX: true,
        ordering: true,
        order: [[0, 'asc']], // Ordenar por nombre de obra
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
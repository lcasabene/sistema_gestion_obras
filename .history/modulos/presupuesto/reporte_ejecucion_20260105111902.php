<?php
// ---------------------------------------------------------
// 1. CONFIGURACIÓN Y CONEXIÓN
// ---------------------------------------------------------
require_once '../../conexion.php'; // Ajusta según tu estructura
include '../../includes/header.php'; 

// ---------------------------------------------------------
// 2. LÓGICA PHP
// ---------------------------------------------------------

// A. Filtro de fecha (Por defecto el mes actual)
$fecha_corte = $_GET['fecha_corte'] ?? date('Y-m-01');

// B. Consulta SQL
// Esta consulta une: Obra -> Presupuesto -> Curva Vigente -> Items de Curva
$sql = "SELECT 
            o.id, 
            o.denominacion, 
            pe.monto_def,
            pe.fufi, 
            ci.fecha, 
            ci.monto 
        FROM obras o
        -- 1. Unimos con Presupuesto (para monto_def y fufi)
        LEFT JOIN presupuesto_ejecucion pe 
            ON o.id = pe.id_obra
        -- 2. Unimos con la Cabecera de Curvas (Solo la vigente)
        -- IMPORTANTE: Cambia 'curva_cabecera' por el nombre real de la tabla de tu captura #1
        LEFT JOIN curva_cabecera cc 
            ON o.id = cc.obra_id AND cc.es_vigente = 1
        -- 3. Unimos con los Items (Proyecciones) filtrando por fecha
        LEFT JOIN curva_items ci 
            ON cc.id = ci.curva_id AND ci.fecha >= :fecha_corte
        WHERE o.activo = 1 
        ORDER BY o.denominacion ASC, ci.fecha ASC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fecha_corte' => $fecha_corte]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error SQL: " . $e->getMessage() . "</div>";
    exit;
}

// C. Pivote de Datos (Transformar filas a columnas)
$obras = [];
$todos_los_meses = [];

foreach ($resultados as $fila) {
    $id = $fila['id'];
    
    // Si la obra no existe en el array, la creamos
    if (!isset($obras[$id])) {
        $obras[$id] = [
            'denominacion' => $fila['denominacion'],
            'monto_def'    => $fila['monto_def'],
            'fufi'         => $fila['fufi'] ?? '', // Usamos null coalescing por si fufi es null
            'proyecciones' => [] 
        ];
    }

    // Si hay proyección para esa fecha
    if (!empty($fila['fecha'])) {
        // Clave Año-Mes (ej: 2026-02)
        $mes_clave = date('Y-m', strtotime($fila['fecha'])); 
        
        // Sumamos (por si hubiera duplicados erróneos, mejor sumar que sobrescribir)
        if (!isset($obras[$id]['proyecciones'][$mes_clave])) {
            $obras[$id]['proyecciones'][$mes_clave] = 0;
        }
        $obras[$id]['proyecciones'][$mes_clave] += $fila['monto'];
        
        // Guardamos el mes en la lista maestra de columnas
        if (!in_array($mes_clave, $todos_los_meses)) {
            $todos_los_meses[] = $mes_clave;
        }
    }
}

// Ordenamos columnas cronológicamente
sort($todos_los_meses);
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <h1>Reporte de Ejecución y Proyecciones</h1>
        </div>
    </section>

    <section class="content">
        <div class="card card-outline card-primary">
            
            <div class="card-header">
                <form method="GET" class="row align-items-end">
                    <div class="col-md-3">
                        <label for="fecha_corte" class="form-label">Proyectar desde:</label>
                        <input type="date" class="form-control" name="fecha_corte" id="fecha_corte" value="<?php echo $fecha_corte; ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-sync"></i> Actualizar
                        </button>
                    </div>
                </form>
            </div>

            <div class="card-body">
                <div class="table-responsive">
                    <table id="tablaProyeccion" class="table table-bordered table-striped table-sm nowrap" style="width:100%">
                        <thead class="bg-dark text-white">
                            <tr>
                                <th style="min-width: 250px;">Obra</th>
                                <th>FUFI</th>
                                <th>Monto Def.</th>
                                <?php foreach ($todos_los_meses as $mes): 
                                    $dt = DateTime::createFromFormat('Y-m', $mes);
                                    // Formato "Ene 26"
                                    $meses_es = ["Jan"=>"Ene","Feb"=>"Feb","Mar"=>"Mar","Apr"=>"Abr","May"=>"May","Jun"=>"Jun","Jul"=>"Jul","Aug"=>"Ago","Sep"=>"Sep","Oct"=>"Oct","Nov"=>"Nov","Dec"=>"Dic"];
                                    $nombre_mes = $meses_es[$dt->format('M')] . " " . $dt->format('y');
                                ?>
                                    <th class="text-center bg-secondary"><?php echo $nombre_mes; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($obras as $obra): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($obra['denominacion']); ?></td>
                                <td><?php echo htmlspecialchars($obra['fufi']); ?></td>
                                <td class="text-right text-bold">
                                    <?php echo '$ ' . number_format($obra['monto_def'], 2, ',', '.'); ?>
                                </td>
                                
                                <?php foreach ($todos_los_meses as $mes): ?>
                                    <td class="text-right">
                                        <?php 
                                            $val = $obra['proyecciones'][$mes] ?? 0;
                                            if ($val != 0) {
                                                echo number_format($val, 2, ',', '.');
                                            } else {
                                                echo '<span class="text-muted text-xs">-</span>';
                                            }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                             <tr>
                                <th colspan="3" class="text-right">Totales Mensuales:</th>
                                <?php foreach ($todos_los_meses as $mes): 
                                    $total_mes = 0;
                                    foreach ($obras as $o) {
                                        $total_mes += ($o['proyecciones'][$mes] ?? 0);
                                    }
                                ?>
                                <th class="text-right"><?php echo number_format($total_mes, 0, ',', '.'); ?></th>
                                <?php endforeach; ?>
                             </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../../includes/footer.php'; ?>

<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaProyeccion').DataTable({
        "scrollX": true,
        "paging": false,       // Mostrar todo en una sola página suele ser mejor para reportes financieros
        "info": true,
        "dom": 'Bfrtip',       // Botones arriba
        "buttons": [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel"></i> Excel',
                className: 'btn btn-success btn-sm',
                title: 'Proyección Obras - Generado el ' + new Date().toLocaleDateString(),
                footer: true // Incluir fila de totales en Excel
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Imprimir',
                className: 'btn btn-default btn-sm',
                footer: true
            }
        ],
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
        }
    });
});
</script>
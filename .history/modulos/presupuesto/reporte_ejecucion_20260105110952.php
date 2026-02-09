<?php
// ---------------------------------------------------------
// 1. CONFIGURACIÓN Y CONEXIÓN
// ---------------------------------------------------------
// Ajusta la ruta ('../../conexion.php') según donde tengas tu archivo de conexión real.
require_once __DIR__ . '/../../config/database.php';

// Incluimos el Header (Ajusta la ruta si es necesario)
include __DIR__ . '/../../public/_header.php';

require_once __DIR__ . '/../../auth/middleware.php';
require_login();


// ---------------------------------------------------------
// 2. LÓGICA PHP (PROCESAMIENTO DE DATOS)
// ---------------------------------------------------------

// A. Obtener fecha de corte (si no viene por GET, usamos el 1ro del mes actual)
$fecha_corte = $_GET['fecha_corte'] ?? date('Y-m-01');

// B. Consulta SQL
// Traemos todas las obras activas y unimos con sus proyecciones futuras
// Usamos LEFT JOIN para que aparezcan las obras aunque no tengan proyección cargada aún.
$sql = "SELECT 
            o.id_obra, 
            o.descripcion, 
            o.monto_def, 
            o.fufi,
            pe.fecha, 
            pe.monto_proyeccion 
        FROM obras o
        LEFT JOIN presupuesto_ejecucion pe 
            ON o.id_obra = pe.id_obra 
            AND pe.fecha >= :fecha_corte
        WHERE o.activo = 1 
        ORDER BY o.descripcion ASC, pe.fecha ASC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fecha_corte' => $fecha_corte]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Error en la consulta: " . $e->getMessage();
    exit;
}

// C. Pivote de Datos (Transformar filas a columnas)
$obras = [];
$todos_los_meses = [];

foreach ($resultados as $fila) {
    $id = $fila['id_obra'];
    
    // Inicializar la obra si es la primera vez que la vemos en el loop
    if (!isset($obras[$id])) {
        $obras[$id] = [
            'descripcion' => $fila['descripcion'],
            'monto_def'   => $fila['monto_def'],
            'fufi'        => $fila['fufi'],
            'proyecciones'=> [] 
        ];
    }

    // Si la fila tiene datos de proyección (no es NULL debido al LEFT JOIN)
    if (!empty($fila['fecha'])) {
        // Formateamos la fecha como 'YYYY-MM' para usarla de clave
        $mes_clave = date('Y-m', strtotime($fila['fecha'])); 
        
        // Guardamos el monto
        $obras[$id]['proyecciones'][$mes_clave] = $fila['monto_proyeccion'];
        
        // Agregamos el mes a la lista de encabezados si no existe
        if (!in_array($mes_clave, $todos_los_meses)) {
            $todos_los_meses[] = $mes_clave;
        }
    }
}

// Ordenamos los meses cronológicamente para que las columnas salgan en orden
sort($todos_los_meses);
?>

<div class="content-wrapper"> <section class="content-header">
        <div class="container-fluid">
            <h1>Reporte de Ejecución y Proyección</h1>
        </div>
    </section>

    <section class="content">
        <div class="card card-primary card-outline">
            
            <div class="card-header">
                <form method="GET" class="row align-items-end">
                    <div class="col-md-3">
                        <label for="fecha_corte" class="form-label">Proyectar desde (Fecha de Corte):</label>
                        <input type="date" class="form-control" name="fecha_corte" id="fecha_corte" value="<?php echo $fecha_corte; ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter"></i> Filtrar</button>
                    </div>
                </form>
            </div>

            <div class="card-body">
                <div class="table-responsive">
                    <table id="tablaProyeccion" class="table table-bordered table-striped table-hover nowrap" style="width:100%">
                        <thead class="bg-dark text-white">
                            <tr>
                                <th>Obra / Proyecto</th>
                                <th>FUFI</th>
                                <th>Monto Def.</th>
                                <?php foreach ($todos_los_meses as $mes): ?>
                                    <?php 
                                        // Array de meses en español para que se vea bonito
                                        $meses_es = ["01"=>"Ene","02"=>"Feb","03"=>"Mar","04"=>"Abr","05"=>"May","06"=>"Jun","07"=>"Jul","08"=>"Ago","09"=>"Sep","10"=>"Oct","11"=>"Nov","12"=>"Dic"];
                                        $anio = date('Y', strtotime($mes . '-01'));
                                        $num_mes = date('m', strtotime($mes . '-01'));
                                        $nombre_columna = $meses_es[$num_mes] . " " . $anio;
                                    ?>
                                    <th class="text-center bg-secondary"><?php echo $nombre_columna; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($obras as $obra): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($obra['descripcion']); ?></strong></td>
                                <td><?php echo htmlspecialchars($obra['fufi']); ?></td>
                                <td class="text-right text-nowrap">$ <?php echo number_format($obra['monto_def'], 2, ',', '.'); ?></td>
                                
                                <?php foreach ($todos_los_meses as $mes): ?>
                                    <td class="text-right">
                                        <?php 
                                            $valor = $obra['proyecciones'][$mes] ?? 0;
                                            if ($valor > 0) {
                                                echo '$ ' . number_format($valor, 2, ',', '.');
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div> </div> </section>
</div>

<?php include '../../includes/footer.php'; ?>

<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaProyeccion').DataTable({
        "responsive": false, // False para permitir scroll horizontal si hay muchos meses
        "scrollX": true,
        "lengthChange": false, 
        "autoWidth": false,
        "pageLength": 25,
        "dom": 'Bfrtip', // Definición de la estructura para mostrar los botones
        "buttons": [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel"></i> Exportar Excel',
                titleAttr: 'Exportar a Excel',
                className: 'btn btn-success btn-sm',
                title: 'Proyección de Obras - ' + $('#fecha_corte').val()
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm',
                orientation: 'landscape', // Horizontal para que entren los meses
                pageSize: 'LEGAL'
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Imprimir',
                className: 'btn btn-info btn-sm'
            }
        ],
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
        }
    });
});
</script>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
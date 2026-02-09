<?php
// ---------------------------------------------------------
// 1. INCLUDES Y AUTENTICACIÓN (RUTAS EXACTAS)
// ---------------------------------------------------------
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/middleware.php';

// Verificamos sesión (el middleware debería encargarse, pero por seguridad)
// if (!isset($_SESSION['user_id'])) { header("Location: /login.php"); exit; }

// Incluimos el Header (Asumo que está en public junto con el footer)
// Si tu archivo de header tiene otro nombre, ajústalo aquí.
include __DIR__ . '/../../public/_header.php'; 

// ---------------------------------------------------------
// 2. LÓGICA DE DATOS
// ---------------------------------------------------------

// A. Conexión a Base de Datos
// Asumo que tu archivo database.php crea una variable $conn o $pdo. 
// Si usas otro nombre, cámbialo abajo. Aquí uso $conn.
global $conn; 

// B. Filtros
$fecha_corte = $_GET['fecha_corte'] ?? date('Y-m-01');

// C. Consulta SQL
// Unimos: Obras -> Presupuesto -> Curva (Vigente) -> Items de Curva
$sql = "SELECT 
            o.id, 
            o.denominacion, 
            pe.monto_def, 
            pe.fufi,
            ci.periodo as fecha,  -- AJUSTAR: Nombre de columna de fecha en 'curva_items'
            ci.monto_avance as monto  -- AJUSTAR: Nombre de columna de monto en 'curva_items'
        FROM obras o
        -- 1. Presupuesto (Monto Definitivo)
        LEFT JOIN presupuesto_ejecucion pe 
            ON o.id = pe.id_obra
        -- 2. Curva Vigente (La versión aprobada)
        LEFT JOIN curvas c 
            ON o.id = c.obra_id AND c.es_vigente = 1
        -- 3. Items de la Curva (Detalle mensual)
        LEFT JOIN curva_items ci 
            ON c.id = ci.curva_id AND ci.periodo >= :fecha_corte
        WHERE o.activo = 1 
        ORDER BY o.denominacion ASC, ci.periodo ASC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fecha_corte' => $fecha_corte]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // En producción, usa error_log en vez de echo
    echo "<div class='alert alert-danger'>Error al cargar datos: " . $e->getMessage() . "</div>";
    $resultados = [];
}

// D. Procesamiento (Pivote: Filas -> Columnas)
$obras = [];
$todos_los_meses = [];

foreach ($resultados as $fila) {
    $id = $fila['id'];
    
    // Crear estructura de obra si no existe
    if (!isset($obras[$id])) {
        $obras[$id] = [
            'denominacion' => $fila['denominacion'],
            'monto_def'    => $fila['monto_def'] ?? 0,
            'fufi'         => $fila['fufi'] ?? '',
            'proyecciones' => [] 
        ];
    }

    // Agregar proyección si existe
    if (!empty($fila['fecha'])) {
        $mes_clave = date('Y-m', strtotime($fila['fecha'])); // Ej: 2026-02
        
        if (!isset($obras[$id]['proyecciones'][$mes_clave])) {
            $obras[$id]['proyecciones'][$mes_clave] = 0;
        }
        $obras[$id]['proyecciones'][$mes_clave] += $fila['monto'];
        
        // Registrar el mes para el encabezado
        if (!in_array($mes_clave, $todos_los_meses)) {
            $todos_los_meses[] = $mes_clave;
        }
    }
}
// Ordenar meses cronológicamente
sort($todos_los_meses);
?>

<div class="content-wrapper" style="padding: 20px;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Proyección de Ejecución Financiera</h1>
    </div>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-auto">
                    <label for="fecha_corte" class="form-label fw-bold">Fecha de Corte (Proyectar desde):</label>
                    <input type="date" class="form-control" name="fecha_corte" id="fecha_corte" value="<?php echo $fecha_corte; ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Actualizar Reporte
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaReporte" class="table table-striped table-bordered table-hover w-100 text-sm">
                    <thead class="table-dark">
                        <tr>
                            <th class="align-middle">Obra / Proyecto</th>
                            <th class="align-middle text-center">FUFI</th>
                            <th class="align-middle text-end">Monto Definitivo</th>
                            
                            <?php foreach ($todos_los_meses as $mes): 
                                $dt = DateTime::createFromFormat('Y-m', $mes);
                                // Formato bonito (ej: Ene 26)
                                $nombres_mes = ["Jan"=>"Ene","Feb"=>"Feb","Mar"=>"Mar","Apr"=>"Abr","May"=>"May","Jun"=>"Jun","Jul"=>"Jul","Aug"=>"Ago","Sep"=>"Sep","Oct"=>"Oct","Nov"=>"Nov","Dec"=>"Dic"];
                                $label = $nombres_mes[$dt->format('M')] . " '" . $dt->format('y');
                            ?>
                                <th class="align-middle text-center bg-secondary"><?php echo $label; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($obras as $obra): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($obra['denominacion']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($obra['fufi']); ?></td>
                            <td class="text-end fw-bold">
                                $ <?php echo number_format($obra['monto_def'], 2, ',', '.'); ?>
                            </td>
                            
                            <?php foreach ($todos_los_meses as $mes): ?>
                                <td class="text-end">
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
                        <tr class="table-secondary fw-bold">
                            <td colspan="3" class="text-end">TOTALES MES:</td>
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
    var tituloArchivo = 'Proyeccion_Ejecucion_' + $('#fecha_corte').val();

    $('#tablaReporte').DataTable({
        dom: 'Bfrtip', // Botones arriba
        paging: false, // Mostrar todo para ver el panorama completo
        scrollX: true, // Scroll horizontal si hay muchos meses
        order: [[0, 'asc']], // Ordenar por nombre de obra
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel"></i> Exportar a Excel',
                className: 'btn btn-success btn-sm me-2',
                title: tituloArchivo,
                footer: true // Incluir los totales del footer en el Excel
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
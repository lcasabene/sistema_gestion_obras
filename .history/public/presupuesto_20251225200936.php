<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

include __DIR__ . '/_header.php';

// Filtros
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$ejercicio = $_GET['ejercicio'] ?? '';
$imputacion = $_GET['imputacion'] ?? ''; // Nuevo filtro
?>

<div class="container-fluid mt-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Historial de Ejecución Presupuestaria</h5>
            <div>
                <a href="importar.php" class="btn btn-light btn-sm text-primary fw-bold">
                    <i class="bi bi-cloud-upload"></i> Importar Nueva
                </a>
            </div>
        </div>
        <div class="card-body">
            
            <form method="GET" class="row g-3 mb-4 p-3 bg-light rounded border">
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Fecha Inicio (Listado)</label>
                    <input type="date" name="fecha_inicio" class="form-control form-control-sm" value="<?php echo $fecha_inicio; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Fecha Fin (Listado)</label>
                    <input type="date" name="fecha_fin" class="form-control form-control-sm" value="<?php echo $fecha_fin; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Ejercicio</label>
                    <input type="number" name="ejercicio" class="form-control form-control-sm" placeholder="Ej: 2025" value="<?php echo $ejercicio; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Imputación</label>
                    <input type="text" name="imputacion" class="form-control form-control-sm" placeholder="Buscar código..." value="<?php echo $imputacion; ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm me-2 w-100">
                        <i class="bi bi-search"></i> Filtrar
                    </button>
                    <a href="presupuesto.php" class="btn btn-secondary btn-sm">Limpiar</a>
                </div>
            </form>

            <div class="table-responsive">
                <table id="tablaPresupuesto" class="table table-striped table-hover table-bordered w-100" style="font-size: 0.85rem;">
                    <thead class="table-dark text-center align-middle">
                        <tr>
                            <th>Fecha Listado</th>
                            <th>Ejercicio</th>
                            <th>Imputación</th>
                            <th>Denominación</th> <th>Monto Def.</th>
                            <th>Monto Comp.</th>
                            <th>Monto Ejec.</th>
                            <th>Monto Disp.</th>
                            <th>Monto Sald.</th>
                            <th>Fecha Carga</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            // Construcción de la consulta
                            // CORRECCIÓN: Eliminado GROUP BY para ver todos los registros
                            $sql = "SELECT 
                                        pe.id,
                                        pe.ejer,
                                        pe.fecha_listado,
                                        pe.imputacion,
                                        pe.denominacion1,
                                        pe.denominacion2,
                                        pe.denominacion3,
                                        pe.monto_def,
                                        pe.monto_comp,
                                        pe.monto_ejec,
                                        pe.monto_disp,
                                        pe.monto_sald,
                                        pe.fecha_carga,
                                        pe.desc_imputacion
                                    FROM 
                                        presupuesto_ejecucion pe
                                    WHERE 1=1";

                            $params = [];

                            if (!empty($fecha_inicio)) {
                                $sql .= " AND pe.fecha_listado >= ?";
                                $params[] = $fecha_inicio;
                            }

                            if (!empty($fecha_fin)) {
                                $sql .= " AND pe.fecha_listado <= ?";
                                $params[] = $fecha_fin;
                            }

                            if (!empty($ejercicio)) {
                                $sql .= " AND pe.ejer = ?";
                                $params[] = $ejercicio;
                            }

                            if (!empty($imputacion)) {
                                $sql .= " AND pe.imputacion LIKE ?";
                                $params[] = "%$imputacion%";
                            }

                            // CORRECCIÓN IMPORTANTE:
                            // Antes tenías: $sql .= " GROUP BY pe.imputacion ORDER BY pe.id DESC";
                            // Ahora solo ordenamos por ID para ver cada línea del Excel individualmente.
                            $sql .= " ORDER BY pe.id DESC";

                            // Si hay muchísimos datos, limitamos a los últimos 2000 para no trabar el navegador
                            // (Opcional, puedes quitarlo si quieres ver absolutamente todo)
                            $sql .= " LIMIT 2000"; 

                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($params);

                            foreach ($stmt as $row) {
                                // Formatear fecha listado
                                $fechaListado = $row['fecha_listado'] 
                                    ? date('d/m/Y', strtotime($row['fecha_listado'])) 
                                    : '-';
                                
                                // Formatear fecha carga
                                $fechaCarga = $row['fecha_carga'] 
                                    ? date('d/m/Y H:i', strtotime($row['fecha_carga'])) 
                                    : '-';

                                // Combinar denominaciones para mostrar info completa
                                $denominacion = trim($row['desc_imputacion']);
                                if(empty($denominacion)){
                                    $denominacion = trim($row['denominacion1'] . ' ' . $row['denominacion2']);
                                }

                                echo "<tr>";
                                echo "<td class='text-center'>{$fechaListado}</td>";
                                echo "<td class='text-center'>{$row['ejer']}</td>";
                                echo "<td class='fw-bold text-primary'>{$row['imputacion']}</td>";
                                echo "<td>" . htmlspecialchars($denominacion) . "</td>";
                                
                                // Montos formateados
                                echo "<td class='text-end'>" . number_format($row['monto_def'], 2, ',', '.') . "</td>";
                                echo "<td class='text-end'>" . number_format($row['monto_comp'], 2, ',', '.') . "</td>";
                                echo "<td class='text-end'>" . number_format($row['monto_ejec'], 2, ',', '.') . "</td>";
                                echo "<td class='text-end fw-bold text-success'>" . number_format($row['monto_disp'], 2, ',', '.') . "</td>";
                                echo "<td class='text-end text-danger'>" . number_format($row['monto_sald'], 2, ',', '.') . "</td>";
                                
                                echo "<td class='text-center small text-muted'>{$fechaCarga}</td>";
                                echo "</tr>";
                            }
                        } catch (PDOException $e) {
                            echo "<tr><td colspan='10' class='text-danger text-center'>Error al cargar datos: " . $e->getMessage() . "</td></tr>";
                        }
                        ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="4" class="text-end">Totales (Vista Actual):</th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('#tablaPresupuesto').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
            },
            "order": [[ 9, "desc" ]], // Ordenar por fecha de carga por defecto
            "pageLength": 50,
            "dom": 'Bfrtip',
            "buttons": [
                'excel', 'pdf', 'print'
            ]
        });
    });
</script>

<?php include __DIR__ . '/_footer.php'; ?>
<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

// Consultamos los registros ordenados por la fecha de versión (fecha_carga) y luego por id
$query = $pdo->query("SELECT * FROM presupuesto_ejecucion ORDER BY fecha_carga DESC, id ASC");
$registros = $query->fetchAll();

include __DIR__ . '/_header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0">Ejecución Presupuestaria</h3>
            <p class="text-muted small">Historial de versiones y estados de ejecución</p>
        </div>
        <div class="btn-group">
            <a href="importar.php" class="btn btn-success">
                <i class="bi bi-file-earmark-arrow-up"></i> Nueva Importación
            </a>
            <a href="menu.php" class="btn btn-outline-primary">
                <i class="bi bi-grid-3x3-gap"></i> Menú
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaPresupuesto" class="table table-striped table-bordered table-sm hover" style="width:100%">
                    <thead class="table-dark">
                        <tr>
                            <th>Fecha Versión</th>
                            <th>Jurisd. (TIDE)</th>
                            <th>U.O. (TIUO)</th>
                            <th>Inci/Ppal</th>
                            <th>Denominación</th>
                            <th>Monto Def.</th>
                            <th>Ejecutado</th>
                            <th>Saldo</th>
                            <th>Imputación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $r): ?>
                        <tr>
                            <td class="text-nowrap">
                                <span class="badge bg-info text-dark">
                                    <?php echo date('d/m/Y', strtotime($r['fecha_carga'])); ?>
                                </span>
                            </td>
                            <td><small title="<?php echo $r['tide']; ?>"><?php echo mb_strimwidth($r['tide'], 0, 25, "..."); ?></small></td>
                            <td><small><?php echo mb_strimwidth($r['tiuo'], 0, 25, "..."); ?></small></td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?php echo $r['inci'] . "-" . $r['ppal']; ?></span>
                            </td>
                            <td class="small"><strong><?php echo $r['denominacion1']; ?></strong></td>
                            <td class="text-end fw-bold"><?php echo number_format($r['monto_def'], 2, ',', '.'); ?></td>
                            <td class="text-end text-primary fw-bold"><?php echo number_format($r['monto_ejec'], 2, ',', '.'); ?></td>
                            <td class="text-end <?php echo ($r['monto_sald'] < 0) ? 'text-danger' : 'text-success'; ?>">
                                <?php echo number_format($r['monto_sald'], 2, ',', '.'); ?>
                            </td>
                            <td><code class="xsmall"><?php echo $r['imputacion']; ?></code></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-light border" onclick="verDetalle(<?php echo htmlspecialchars(json_encode($r)); ?>)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title">Detalle Completo de Ejecución</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="contenidoDetalle" class="row g-2 small">
            </div>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
    $('#tablaPresupuesto').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
        },
        pageLength: 25,
        order: [[0, 'desc']], // Ordenar por fecha de versión
        responsive: false, // Usamos el div table-responsive para manejar el ancho
        dom: 'Bfrtip', // Para habilitar botones de exportación si los agregas después
    });
});

function verDetalle(data) {
    let html = '';
    // Iteramos sobre el objeto para mostrar todas las columnas disponibles
    for
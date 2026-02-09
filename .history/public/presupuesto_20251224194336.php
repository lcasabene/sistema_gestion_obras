<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

// Consultamos los registros
$query = $pdo->query("SELECT * FROM presupuesto_ejecucion ORDER BY fecha_carga DESC, id ASC");
$registros = $query->fetchAll();

include __DIR__ . '/_header.php';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0">Ejecución Presupuestaria</h3>
            <span class="badge bg-secondary">Búsqueda avanzada habilitada</span>
        </div>
        <div>
            <a href="importar.php" class="btn btn-success btn-sm"><i class="bi bi-upload"></i> Importar</a>
            <a href="menu.php" class="btn btn-primary btn-sm"><i class="bi bi-house"></i> Menú</a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaPresupuesto" class="table table-hover table-sm small" style="width:100%">
                    <thead class="table-dark">
                        <tr>
                            <th>Versión</th>
                            <th>Jurisd.</th>
                            <th>Denominación</th>
                            <th>Inc/Ppal</th>
                            <th>Definitivo</th>
                            <th>Ejecutado</th>
                            <th>Saldo</th>
                            <th class="text-center">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $r): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($r['fecha_carga'])); ?></td>
                            <td><small><?php echo $r['tide']; ?></small></td>
                            <td><strong><?php echo $r['denominacion1']; ?></strong></td>
                            <td class="text-center"><?php echo $r['inci'] . "-" . $r['ppal']; ?></td>
                            <td class="text-end"><?php echo number_format($r['monto_def'], 2, ',', '.'); ?></td>
                            <td class="text-end text-primary"><?php echo number_format($r['monto_ejec'], 2, ',', '.'); ?></td>
                            <td class="text-end fw-bold"><?php echo number_format($r['monto_sald'], 2, ',', '.'); ?></td>
                            <td class="text-center">
                                <button type="button" 
                                        class="btn btn-info btn-sm btn-detalle" 
                                        data-datos='<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>'>
                                    <i class="bi bi-eye text-white"></i>
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

<div class="modal fade" id="modalDetalle" tabindex="-1" aria-labelledby="modalDetalleLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="modalDetalleLabel">Información Completa del Registro</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="listaDetalles" class="row g-3">
            </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // 1. Inicializar DataTables
    var table = $('#tablaPresupuesto').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        pageLength: 10,
        order: [[0, 'desc']]
    });

    // 2. Lógica del botón Ver Detalle
    $('.btn-detalle').on('click', function() {
        const datos = $(this).data('datos');
        let contenido = '';

        // Recorremos los 37 campos para mostrarlos en el modal
        for (const [key, value] of Object.entries(datos)) {
            contenido += `
                <div class="col-md-4">
                    <div class="p-2 border rounded bg-light">
                        <small class="text-muted d-block text-uppercase" style="font-size: 0.65rem;">${key}</small>
                        <span class="fw-bold">${value !== null ? value : '-'}</span>
                    </div>
                </div>`;
        }

        $('#listaDetalles').html(contenido);
        var myModal = new bootstrap.Modal(document.getElementById('modalDetalle'));
        myModal.show();
    });
});
</script>

<style>
    /* Estilo para que la tabla no sea gigante */
    .table td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
    .btn-info { background-color: #0dcaf0; border: none; }
</style>

<?php include __DIR__ . '/_footer.php'; ?>
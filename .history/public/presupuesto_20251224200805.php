<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

// Consultamos los registros cargados
$query = $pdo->query("SELECT * FROM presupuesto_ejecucion ORDER BY fecha_carga DESC, id ASC");
$registros = $query->fetchAll();

include __DIR__ . '/_header.php';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

<style>
    .text-xsmall { font-size: 0.75rem; line-height: 1.1; }
    .badge-presupuesto { font-family: monospace; font-size: 0.85rem; }
    .table td { vertical-align: middle; }
    .col-denominacion { min-width: 250px; max-width: 350px; }
    .dt-buttons .btn { border-radius: 5px; margin-right: 5px; font-size: 0.8rem; }
    .text-alerta { color: #dc3545 !important; font-weight: bold; } /* Rojo para consumo alto */
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0"><i class="bi bi-shield-exclamation me-2"></i>Control de Ejecución</h3>
            <p class="text-muted small">Alerta: Ejecutado ≥ 80% del Crédito Total (DEF + REEP)</p>
        </div>
        <div class="btn-group shadow-sm">
            <a href="importar.php" class="btn btn-success"><i class="bi bi-upload"></i> Importar</a>
            <a href="menu.php" class="btn btn-primary"><i class="bi bi-grid-3x3-gap"></i> Menú</a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaPresupuesto" class="table table-hover table-sm" style="width:100%">
                    <thead class="table-dark">
                        <tr>
                            <th>Versión</th>
                            <th>Inc-Ppal-Ppar</th>
                            <th>FuFi</th>
                            <th class="col-denominacion">Denominación</th>
                            <th>Crédito Total</th>
                            <th>Ejecutado</th>
                            <th>Disponible</th>
                            <th>% Consumo</th>
                            <th class="text-center no-export">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $r): 
                            // 1. Crédito Total = Definitivo + Reestimación
                            $credito_total = $r['monto_def'] + $r['monto_reep'];
                            
                            // 2. Lógica de Alerta: Ejecutado vs Crédito Total
                            $porcentaje_consumo = 0;
                            $es_critico = false;
                            
                            if ($credito_total > 0) {
                                $porcentaje_consumo = ($r['monto_ejec'] / $credito_total) * 100;
                                // Rojo si ya se ejecutó el 80% o más
                                if ($porcentaje_consumo >= 80) {
                                    $es_critico = true;
                                }
                            }
                        ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border"><?php echo date('d/m/Y', strtotime($r['fecha_carga'])); ?></span></td>
                            
                            <td class="text-center">
                                <span class="badge bg-secondary badge-presupuesto"><?php echo "{$r['inci']}-{$r['ppal']}-{$r['ppar']}"; ?></span>
                            </td>
                            
                            <td class="text-center fw-bold text-primary"><?php echo $r['fufi']; ?></td>
                            
                            <td class="col-denominacion">
                                <div class="fw-bold text-dark text-truncate"><?php echo $r['denominacion1']; ?></div>
                                <div class="text-muted text-xsmall text-truncate"><?php echo $r['denominacion2']; ?></div>
                            </td>

                            <td class="text-end fw-bold text-dark">
                                <?php echo number_format($credito_total, 2, ',', '.'); ?>
                            </td>

                            <td class="text-end <?php echo $es_critico ? 'text-alerta' : 'text-primary'; ?>">
                                <?php if($es_critico): ?><i class="bi bi-fire me-1"></i><?php endif; ?>
                                <?php echo number_format($r['monto_ejec'], 2, ',', '.'); ?>
                            </td>
                            
                            <td class="text-end text-success">
                                <?php echo number_format($r['monto_disp'], 2, ',', '.'); ?>
                            </td>

                            <td class="text-end">
                                <div class="progress" style="height: 18px; min-width: 80px;">
                                  <div class="progress-bar <?php echo $es_critico ? 'bg-danger' : 'bg-info'; ?>" 
                                       role="progressbar" 
                                       style="width: <?php echo min($porcentaje_consumo, 100); ?>%;" 
                                       aria-valuenow="<?php echo $porcentaje_consumo; ?>" 
                                       aria-valuemin="0" 
                                       aria-valuemax="100">
                                       <small><?php echo number_format($porcentaje_consumo, 0); ?>%</small>
                                  </div>
                                </div>
                            </td>

                            <td class="text-center no-export">
                                <button type="button" class="btn btn-outline-info btn-sm btn-detalle" 
                                        data-datos='<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>'>
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
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">Detalle Técnico Completo</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body bg-light">
        <div id="listaDetalles" class="row g-2"></div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaPresupuesto').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        pageLength: 25,
        dom: '<"d-flex justify-content-between mb-2"Bf>rtip',
        buttons: [
            { extend: 'excelHtml5', text: '<i class="bi bi-file-earmark-excel"></i> Excel', className: 'btn btn-success btn-sm', exportOptions: { columns: ':not(.no-export)' } },
            { extend: 'pdfHtml5', text: '<i class="bi bi-file-earmark-pdf"></i> PDF', className: 'btn btn-danger btn-sm', orientation: 'landscape', exportOptions: { columns: ':not(.no-export)' } }
        ]
    });

    $('.btn-detalle').on('click', function() {
        const datos = $(this).data('datos');
        let html = '';
        for (const [key, value] of Object.entries(datos)) {
            if(key === 'id') continue;
            html += `<div class="col-md-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body p-2"><label class="text-muted text-uppercase fw-bold" style="font-size:0.6rem;">${key}</label><div class="text-dark small">${value || 'N/A'}</div></div></div></div>`;
        }
        $('#listaDetalles').html(html);
        new bootstrap.Modal(document.getElementById('modalDetalle')).show();
    });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
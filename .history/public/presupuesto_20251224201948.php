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
    .col-denominacion { min-width: 350px; max-width: 500px; }
    .dt-buttons .btn { border-radius: 5px; margin-right: 5px; font-size: 0.8rem; }
    
    /* Diseño específico para Denominación 3 */
    .deno-3 { 
        font-size: 0.72rem; 
        color: #6c757d; 
        border-top: 1px dashed #dee2e6; 
        margin-top: 6px; 
        padding-top: 4px;
        white-space: normal; /* Permite que el texto largo baje de línea */
    }
    
    /* Clases de Alerta */
    .fila-critica { background-color: rgba(220, 53, 69, 0.03) !important; }
    .text-alerta-roja { color: #dc3545 !important; font-weight: bold; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0"><i class="bi bi-shield-fill-exclamation me-2 text-danger"></i>Seguimiento Presupuestario</h3>
            <p class="text-muted small">Alerta de Ejecución: Rojo si Ejecutado ≥ 80% de (DEF + REEP)</p>
        </div>
        <div class="btn-group shadow-sm">
            <button id="btnFiltroCritico" class="btn btn-outline-danger">
                <i class="bi bi-filter-square me-1"></i> Ver solo Críticos
            </button>
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
                            <th class="col-denominacion">Denominaciones (Jerarquía)</th>
                            <th>Crédito Total</th>
                            <th>Ejecutado</th>
                            <th>Disponible</th>
                            <th>Estado</th>
                            <th class="text-center no-export">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $r): 
                            // Cálculos Financieros
                            $credito_total = (float)$r['monto_def'] + (float)$r['monto_reep'];
                            $ejecutado = (float)$r['monto_ejec'];
                            
                            $porcentaje_consumo = 0;
                            $es_critico = false;
                            
                            if ($credito_total > 0) {
                                $porcentaje_consumo = ($ejecutado / $credito_total) * 100;
                                if ($porcentaje_consumo >= 80) { $es_critico = true; }
                            }
                        ?>
                        <tr class="<?php echo $es_critico ? 'fila-critica' : ''; ?>">
                            <td class="text-nowrap">
                                <span class="badge bg-light text-dark border">
                                    <?php echo date('d/m/Y', strtotime($r['fecha_carga'])); ?>
                                </span>
                            </td>

                            <td class="text-center">
                                <span class="badge bg-secondary badge-presupuesto">
                                    <?php echo "{$r['inci']}-{$r['ppal']}-{$r['ppar']}"; ?>
                                </span>
                            </td>

                            <td class="text-center fw-bold text-primary"><?php echo $r['fufi']; ?></td>

                            <td class="col-denominacion">
                                <div class="fw-bold text-dark"><?php echo $r['denominacion1']; ?></div>
                                <?php if(!empty($r['denominacion2'])): ?>
                                    <div class="text-muted text-xsmall mt-1">
                                        <i class="bi bi-arrow-return-right me-1"></i><?php echo $r['denominacion2']; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if(!empty($r['denominacion3'])): ?>
                                    <div class="deno-3">
                                        <i class="bi bi-info-square-fill me-1 text-secondary"></i><?php echo $r['denominacion3']; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="text-end fw-bold"><?php echo number_format($credito_total, 2, ',', '.'); ?></td>
                            
                            <td class="text-end <?php echo $es_critico ? 'text-alerta-roja' : 'text-primary'; ?>">
                                <?php if($es_critico): ?><i class="bi bi-exclamation-triangle-fill me-1"></i><?php endif; ?>
                                <?php echo number_format($ejecutado, 2, ',', '.'); ?>
                            </td>

                            <td class="text-end text-success">
                                <?php echo number_format($r['monto_disp'], 2, ',', '.'); ?>
                            </td>

                            <td class="text-center">
                                <span class="badge <?php echo $es_critico ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                    <?php echo number_format($porcentaje_consumo, 0); ?>% <?php echo $es_critico ? 'ALERTA' : ''; ?>
                                </span>
                            </td>

                            <td class="text-center no-export">
                                <button type="button" class="btn btn-outline-info btn-sm btn-detalle" 
                                        data-datos='<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>'>
                                    <i class="bi bi-search"></i>
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
        <h5 class="modal-title"><i class="bi bi-list-columns me-2"></i>Ficha Técnica Completa</h5>
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

<script>
$(document).ready(function() {
    var table = $('#tablaPresupuesto').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        pageLength: 25,
        dom: '<"d-flex justify-content-between mb-2"Bf>rtip',
        buttons: [
            { extend: 'excelHtml5', text: '<i class="bi bi-file-earmark-excel"></i> Exportar Excel', className: 'btn btn-success btn-sm', exportOptions: { columns: ':not(.no-export)' } },
            { extend: 'pdfHtml5', text: '<i class="bi bi-file-earmark-pdf"></i> PDF', className: 'btn btn-danger btn-sm', orientation: 'landscape', pageSize: 'A4', exportOptions: { columns: ':not(.no-export)' } }
        ]
    });

    // Filtro de Alerta
    $('#btnFiltroCritico').on('click', function() {
        if ($(this).hasClass('active')) {
            $(this).removeClass('active').text('Ver solo Críticos');
            table.column(7).search('').draw();
        } else {
            $(this).addClass('active').text('Ver Todos');
            table.column(7).search('ALERTA').draw();
        }
    });

    // Modal Detalle
    $('.btn-detalle').on('click', function() {
        const datos = $(this).data('datos');
        let html = '';
        for (const [key, value] of Object.entries(datos)) {
            if(key === 'id') continue;
            html += `
                <div class="col-md-3">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body p-2">
                            <label class="text-muted fw-bold" style="font-size:0.6rem;">${key.toUpperCase()}</label>
                            <div class="small text-dark text-break">${value || '-'}</div>
                        </div>
                    </div>
                </div>`;
        }
        $('#listaDetalles').html(html);
        new bootstrap.Modal(document.getElementById('modalDetalle')).show();
    });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
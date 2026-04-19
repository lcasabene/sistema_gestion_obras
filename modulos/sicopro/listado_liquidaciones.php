<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$page_title = 'Listado de Liquidaciones';

// Obtener última actualización
$stmtDate = $pdo->prepare("SELECT fecha_subida FROM sicopro_import_log WHERE tipo_importacion = 'LIQUIDACIONES' ORDER BY id DESC LIMIT 1");
$stmtDate->execute();
$lastUpdate = $stmtDate->fetchColumn();

// Consulta
$sql = "SELECT * FROM sicopro_liquidaciones ORDER BY nro_liquidacion DESC LIMIT 2000";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<style>
    /* Estilo para indicar que hay un tooltip (subrayado punteado) */
    .hover-detail {
        text-decoration: underline dotted;
        cursor: help;
    }
    /* Alinear el tooltip a la izquierda si tiene texto largo */
    .tooltip-inner {
        text-align: left;
        max-width: 300px;
    }
</style>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2><i class="bi bi-file-earmark-text"></i> <?= $page_title ?></h2>
            <small class="text-muted">Actualizado: <?= $lastUpdate ? date('d/m/Y H:i', strtotime($lastUpdate)) : 'Sin datos' ?></small>
        </div>
        <div>
            <a href="importar.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-upload"></i> Nueva Importación</a>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm">Volver</a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaLiq" class="table table-striped table-hover table-sm" style="width:100%; font-size: 0.9rem;">
                    <thead class="table-dark">
                        <tr>
                            <th>N° Liq</th>
                            <th>Fecha</th>
                            <th>Expediente</th>
                            <th>OP Sicopro</th>
                            <th>Proveedor</th>
                            <th class="text-end">Imp. Liquidado</th>
                            <th class="text-end">Retenciones</th> <th class="text-end">A Pagar</th>
                            <th>Obs.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): 
                            // 1. Calculamos Totales
                            $total_ret = $row['ret_suss'] + $row['ret_gcias'] + $row['ret_iibb'] + $row['ret_otras'] + $row['ret_multas'] + $row['fdo_rep'];

                            // 2. Construimos el detalle para el Tooltip (Solo valores > 0)
                            $detalles = [];
                            if ($row['fdo_rep'] > 0)    $detalles[] = "Fdo. Rep: <b>$" . number_format($row['fdo_rep'], 2, ',', '.') . "</b>";
                            if ($row['ret_suss'] > 0)   $detalles[] = "SUSS: <b>$" . number_format($row['ret_suss'], 2, ',', '.') . "</b>";
                            if ($row['ret_gcias'] > 0)  $detalles[] = "Ganancias: <b>$" . number_format($row['ret_gcias'], 2, ',', '.') . "</b>";
                            if ($row['ret_iibb'] > 0)   $detalles[] = "IIBB: <b>$" . number_format($row['ret_iibb'], 2, ',', '.') . "</b>";
                            if ($row['ret_multas'] > 0) $detalles[] = "Multas: <b>$" . number_format($row['ret_multas'], 2, ',', '.') . "</b>";
                            if ($row['ret_otras'] > 0)  $detalles[] = "Otras: <b>$" . number_format($row['ret_otras'], 2, ',', '.') . "</b>";

                            // Unimos con <br> para salto de línea en el tooltip
                            $tooltip_html = implode('<br>', $detalles);
                        ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= $row['nro_liquidacion'] ?></td>
                            <td data-sort="<?= strtotime($row['fecha']) ?>"><?= date('d/m/Y', strtotime($row['fecha'])) ?></td>
                            <td class="small"><?= $row['expediente'] ?></td>
                            <td><?= ($row['op_sicopro'] > 0) ? '<span class="badge bg-info text-dark">'.$row['op_sicopro'].'</span>' : '-' ?></td>
                            <td title="<?= htmlspecialchars($row['razon_social']) ?>"><?= substr(htmlspecialchars($row['razon_social']), 0, 30) ?></td>
                            
                            <td class="text-end text-muted" data-val="<?= $row['imp_liq'] ?>">
                                $<?= number_format($row['imp_liq'], 2, ',', '.') ?>
                            </td>
                            
                            <td class="text-end text-danger small" data-val="<?= $total_ret ?>">
                                <?php if ($total_ret > 0): ?>
                                    <span class="hover-detail" 
                                          data-bs-toggle="tooltip" 
                                          data-bs-html="true" 
                                          title="<?= $tooltip_html ?>">
                                        -$<?= number_format($total_ret, 2, ',', '.') ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td class="text-end fw-bold text-success" data-val="<?= $row['imp_a_pagar'] ?>">
                                $<?= number_format($row['imp_a_pagar'], 2, ',', '.') ?>
                            </td>

                            <td>
                                <?php if(!empty($row['observaciones'])): ?>
                                    <i class="bi bi-info-circle-fill text-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars($row['observaciones']) ?>"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <th colspan="5" class="text-end">TOTALES VISIBLES:</th>
                            <th class="text-end text-muted" id="totalLiq"></th>
                            <th class="text-end text-danger" id="totalRet"></th>
                            <th class="text-end text-success fs-6" id="totalPagar"></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

<script>
    function initTooltips() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            bootstrap.Tooltip.getOrCreateInstance(el);
        });
    }

    $(document).ready(function () {
        var table = $('#tablaLiq').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
            order: [[0, 'desc']],
            pageLength: 25,
            drawCallback: function() { initTooltips(); },

            footerCallback: function (row, data, start, end, display) {
                var api = this.api();
                const sumarColumna = (indice) => {
                    var celdas = api.column(indice, { page: 'current' }).nodes();
                    var total = $(celdas).map(function() {
                        return parseFloat($(this).attr('data-val')) || 0;
                    }).get().reduce((a, b) => a + b, 0);
                    return total;
                };

                var tLiq = sumarColumna(5);
                var tRet = sumarColumna(6);
                var tPagar = sumarColumna(7);

                const fmt = new Intl.NumberFormat('es-AR', {minimumFractionDigits: 2});
                
                $(api.column(5).footer()).html('$' + fmt.format(tLiq));
                $(api.column(6).footer()).html('-$' + fmt.format(tRet));
                $(api.column(7).footer()).html('$' + fmt.format(tPagar));
            }
        });
    });
</script>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
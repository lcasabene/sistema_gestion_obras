<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$page_title = 'Listado de Pagos (SIGUE)';

// 1. Obtener fecha de última carga
$stmtDate = $pdo->prepare("SELECT fecha_subida FROM sicopro_import_log WHERE tipo_importacion = 'SIGUE' ORDER BY id DESC LIMIT 1");
$stmtDate->execute();
$lastUpdate = $stmtDate->fetchColumn();

// 2. Consulta de datos
$sql = "SELECT * FROM sicopro_sigue ORDER BY fecha DESC, id DESC";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<style>
    .font-mono { font-family: 'Consolas', 'Monaco', monospace; font-size: 0.85rem; }
    .cursor-help { cursor: help; text-decoration: underline dotted #aaa; }
    .text-small-detail { font-size: 0.8rem; color: #6c757d; }
</style>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2><i class="bi bi-bank2"></i> <?= $page_title ?></h2>
            <small class="text-muted">
                <i class="bi bi-clock-history"></i> Actualizado: 
                <?= $lastUpdate ? date('d/m/Y H:i', strtotime($lastUpdate)) : 'Sin datos' ?>
            </small>
        </div>
        <div>
            <a href="importar.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-upload"></i> Nueva Importación</a>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm">Volver</a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaSigue" class="table table-striped table-hover table-sm" style="width:100%; font-size: 0.9rem;">
                    <thead class="table-dark">
                        <tr>
                            <th>Fecha</th>
                            <th>N° Pago / ID Banco</th>
                            <th>Liq. N°</th>
                            <th>Tipo</th>
                            <th>Beneficiario (Lote)</th>
                            <th>Detalle (Transf.)</th>
                            <th>Cuentas (D/C)</th>
                            <th class="text-end">Importe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): 
                            // Formato corto para cuentas bancarias
                            $debito_short = substr($row['debito'], -4);
                            $credito_short = substr($row['credito'], -4);
                            $cuentas_tooltip = "<b>Débito:</b> " . $row['debito'] . "<br><b>Crédito:</b> " . $row['credito'];

                            // Color de badge según tipo
                            $badge_class = 'bg-secondary';
                            if ($row['tipo'] === 'Proveedores') $badge_class = 'bg-primary';
                            if ($row['tipo'] === 'Cuentas Propias') $badge_class = 'bg-success';
                        ?>
                        <tr>
                            <td data-sort="<?= strtotime($row['fecha']) ?>"><?= date('d/m/Y', strtotime($row['fecha'])) ?></td>
                            
                            <td>
                                <div class="fw-bold"><?= $row['nro_pago'] ?></div>
                                <div class="text-small-detail font-mono" title="ID Banco"><?= $row['numero'] ?></div>
                            </td>
                            
                            <td class="text-center">
                                <?php if($row['liqn'] > 0): ?>
                                    <span class="badge bg-warning text-dark border border-dark"><?= $row['liqn'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td><span class="badge <?= $badge_class ?> bg-opacity-75 fw-normal"><?= $row['tipo'] ?></span></td>

                            <td title="<?= htmlspecialchars($row['obs_lote']) ?>">
                                <?= substr(htmlspecialchars($row['obs_lote']), 0, 30) ?>
                                <?php if(strlen($row['obs_lote']) > 30) echo '...'; ?>
                            </td>

                            <td>
                                <span class="cursor-help" data-bs-toggle="tooltip" title="<?= htmlspecialchars($row['obs_transferencia']) ?>">
                                    <i class="bi bi-info-circle"></i> Ver
                                </span>
                            </td>

                            <td class="text-muted small font-mono">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="<?= $cuentas_tooltip ?>" class="cursor-help">
                                    ...<?= $debito_short ?> <i class="bi bi-arrow-right-short"></i> ...<?= $credito_short ?>
                                </span>
                            </td>

                            <td class="text-end fw-bold" data-val="<?= $row['importe'] ?>">
                                $<?= number_format($row['importe'], 2, ',', '.') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <th colspan="7" class="text-end">TOTAL VISIBLE:</th>
                            <th class="text-end text-primary fs-6" id="totalSigue"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    $(document).ready(function () {
        // Inicializar tooltips de Bootstrap
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) { return new bootstrap.Tooltip(tooltipTriggerEl) })

        $('#tablaSigue').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
            order: [[0, 'desc']], // Ordenar por fecha reciente
            pageLength: 25,
            
            // Re-activar tooltips al cambiar de página en la tabla
            drawCallback: function() {
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            },

            // Sumar el total visible
            footerCallback: function (row, data, start, end, display) {
                var api = this.api();
                
                // Sumar columna 7 (Importe) usando data-val
                var total = api.column(7, { page: 'current' }).nodes().to$().map(function() {
                    return parseFloat($(this).attr('data-val')) || 0;
                }).get().reduce((a, b) => a + b, 0);

                var fmt = new Intl.NumberFormat('es-AR', {minimumFractionDigits: 2});
                $(api.column(7).footer()).html('$' + fmt.format(total));
            }
        });
    });
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
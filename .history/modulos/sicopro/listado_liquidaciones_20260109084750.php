<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$page_title = 'Listado de Liquidaciones';

// 1. Obtener fecha de última actualización
$stmtDate = $pdo->prepare("SELECT fecha_subida FROM sicopro_import_log WHERE tipo_importacion = 'LIQUIDACIONES' ORDER BY id DESC LIMIT 1");
$stmtDate->execute();
$lastUpdate = $stmtDate->fetchColumn();

// 2. Consulta de Liquidaciones (Limitamos a las últimas 2000 para rapidez inicial)
// Ordenamos por Nro Liquidación descendente (las más nuevas primero)
$sql = "SELECT * FROM sicopro_liquidaciones ORDER BY nro_liquidacion DESC LIMIT 2000";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2><i class="bi bi-file-earmark-text"></i> <?= $page_title ?></h2>
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
                <table id="tablaLiq" class="table table-striped table-hover table-sm" style="width:100%; font-size: 0.9rem;">
                    <thead class="table-dark">
                        <tr>
                            <th>N° Liq</th>
                            <th>Fecha</th>
                            <th>Expediente</th>
                            <th>OP Sicopro</th>
                            <th>Razón Social / Proveedor</th>
                            <th class="text-end">Imp. Liquidado</th>
                            <th class="text-end">Retenciones</th>
                            <th class="text-end">A Pagar</th>
                            <th>Obs.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): 
                            // Calculamos retenciones totales sumando los campos de retención o restando Liq - Pagar
                            $retenciones = $row['ret_suss'] + $row['ret_gcias'] + $row['ret_iibb'] + $row['ret_otras'] + $row['ret_multas'] + $row['fdo_rep'];
                        ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= $row['nro_liquidacion'] ?></td>
                            <td><?= date('d/m/Y', strtotime($row['fecha'])) ?></td>
                            <td class="text-nowrap small"><?= $row['expediente'] ?></td>
                            <td>
                                <?php if($row['op_sicopro'] > 0): ?>
                                    <span class="badge bg-info text-dark"><?= $row['op_sicopro'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td title="<?= htmlspecialchars($row['razon_social']) ?>">
                                <?= substr(htmlspecialchars($row['razon_social']), 0, 35) ?>
                            </td>
                            
                            <td class="text-end text-muted">
                                $<?= number_format($row['imp_liq'], 2, ',', '.') ?>
                            </td>
                            
                            <td class="text-end text-danger small">
                                <?= $retenciones > 0 ? '-$'.number_format($retenciones, 2, ',', '.') : '-' ?>
                            </td>

                            <td class="text-end fw-bold text-success">
                                $<?= number_format($row['imp_a_pagar'], 2, ',', '.') ?>
                            </td>

                            <td>
                                <?php if(!empty($row['observaciones'])): ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-secondary" 
                                            data-bs-toggle="tooltip" data-bs-placement="left" 
                                            title="<?= htmlspecialchars($row['observaciones']) ?>">
                                        <i class="bi bi-info-circle-fill"></i>
                                    </button>
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

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.7/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>

<script>
    $(document).ready(function () {
        // Inicializar Tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })

        // DataTables
        $('#tablaLiq').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
            order: [[0, 'desc']], // Ordenar por N° Liq descendente
            pageLength: 25,
            
            // Callback para sumar totales en el pie de página
            footerCallback: function (row, data, start, end, display) {
                var api = this.api();
                var intVal = function (i) {
                    return typeof i === 'string' ? i.replace(/[\$,]/g, '') * 1 : typeof i === 'number' ? i : 0;
                };
                
                // Función auxiliar para sumar columna visible
                const sumarColumna = (indice) => {
                    return api.column(indice, { page: 'current' }).data().reduce(function (a, b) {
                        // Limpiar formato moneda ($ 1.000,00 -> 1000.00)
                        let cleanB = typeof b === 'string' ? b.replace('$','').replace(/\./g,'').replace(',','.') : b;
                        // Limpiar signo negativo si es retención
                        cleanB = cleanB.toString().replace('-',''); 
                        return intVal(a) + parseFloat(cleanB);
                    }, 0);
                };

                // Sumar columnas 5 (Liq), 6 (Ret), 7 (Pagar)
                var totalLiq = sumarColumna(5);
                var totalRet = sumarColumna(6);
                var totalPagar = sumarColumna(7);

                // Formateador
                const fmt = new Intl.NumberFormat('es-AR', {minimumFractionDigits: 2});

                $(api.column(5).footer()).html('$' + fmt.format(totalLiq));
                $(api.column(6).footer()).html('-$' + fmt.format(totalRet));
                $(api.column(7).footer()).html('$' + fmt.format(totalPagar));
            }
        });
    });
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
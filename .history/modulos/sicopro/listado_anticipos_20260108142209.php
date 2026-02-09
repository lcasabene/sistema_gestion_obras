<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Validar parámetro GET
$tipo = $_GET['tipo'] ?? 'TOTAL_ANTICIPADO';
$titulos = [
    'TOTAL_ANTICIPADO' => 'Total Anticipado por Ejercicio',
    'SOLICITADO' => 'Solicitado y No Anticipado',
    'SIN_PAGO' => 'Anticipado Sin Pago a Proveedor'
];
$page_title = $titulos[$tipo] ?? 'Listado SICOPRO';

// Obtener última actualización
$stmtDate = $pdo->prepare("SELECT fecha_subida FROM sicopro_import_log WHERE tipo_importacion = ? ORDER BY id DESC LIMIT 1");
$stmtDate->execute([$tipo]);
$lastUpdate = $stmtDate->fetchColumn();

// Consulta (Traemos TODO, el filtrado lo hace DataTables en el navegador)
$stmt = $pdo->prepare("SELECT * FROM sicopro_anticipos_tgf WHERE tipo_origen = ? ORDER BY vto_comp DESC");
$stmt->execute([$tipo]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2><?= htmlspecialchars($page_title) ?></h2>
            <small class="text-muted">
                <i class="bi bi-clock-history"></i> Actualizado: 
                <?= $lastUpdate ? date('d/m/Y H:i', strtotime($lastUpdate)) : 'Sin datos' ?>
            </small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="toggleContrasiento">
                <label class="form-check-label user-select-none" for="toggleContrasiento">Ver Contrasientos</label>
            </div>
            
            <div class="vr"></div> <a href="importar.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Nueva Importación</a>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm">Volver</a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaDatos" class="table table-striped table-hover table-sm" style="width:100%">
                    <thead class="table-dark">
                        <tr>
                            <th>Ejer.</th>
                            <th>Vto. Comp</th>
                            <th>Nro TGF</th>
                            <th>Actuación</th>
                            <th>O.Pago</th>
                            <th>Proveedor</th>
                            <th>Nro. Comp</th>
                            <th>Importe</th>
                            <th>F. Anticipo</th>
                            <th>Contrasiento</th> 
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                        <tr class="<?= ($row['contrasiento'] === 'Si' || $row['contrasiento'] === 'SI') ? 'table-warning text-muted fst-italic' : '' ?>">
                            <td><?= $row['ejer'] ?></td>
                            <td><?= $row['vto_comp'] ?></td>
                            <td><?= $row['nro_tgf'] ?></td>
                            <td class="small"><?= $row['actuacion'] ?></td>
                            <td><?= $row['o_pago'] ?></td>
                            <td title="<?= htmlspecialchars($row['proveedor']) ?>">
                                <?= substr(htmlspecialchars($row['proveedor']), 0, 30) ?>...
                            </td>
                            <td><?= $row['n_comp'] ?></td>
                            <td class="text-end fw-bold <?php echo ($row['importe'] < 0) ? 'text-danger' : ''; ?>">
                                $<?= number_format($row['importe'], 2, ',', '.') ?>
                            </td>
                            <td><?= $row['f_anticipo'] ?></td>
                            <td><?= $row['contrasiento'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="7" class="text-end">Total Visible:</th>
                            <th class="text-end" id="totalFooter"></th>
                            <th colspan="2"></th>
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
<script>
    // Filtro personalizado de DataTables
    $.fn.dataTable.ext.search.push(
        function(settings, data, dataIndex) {
            var mostrarContrasientos = $('#toggleContrasiento').is(':checked');
            var esContrasiento = data[9] === 'Si' || data[9] === 'SI'; // Columna índice 9 (Contrasiento)

            // Si el switch está APAGADO, y la fila ES contrasiento -> Ocultar
            if (!mostrarContrasientos && esContrasiento) {
                return false;
            }
            return true;
        }
    );

    $(document).ready(function () {
        var table = $('#tablaDatos').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
            order: [[1, 'desc']], 
            pageLength: 25,
            columnDefs: [
                {
                    target: 9,
                    visible: true, // Lo dejamos visible o false si quieres ocultar la columna totalmente
                    searchable: true
                }
            ],
            // Calcular total solo de lo visible
            footerCallback: function (row, data, start, end, display) {
                var api = this.api();
                var intVal = function (i) {
                    return typeof i === 'string' ? i.replace(/[\$,]/g, '') * 1 : typeof i === 'number' ? i : 0;
                };

                // Total de columna 7 (Importe) basado solo en filas filtradas
                var total = api.column(7, { page: 'current' }).data().reduce(function (a, b) {
                    let cleanB = b.replace('$','').replace(/\./g,'').replace(',','.'); 
                    return intVal(a) + parseFloat(cleanB);
                }, 0);
                
                $('#totalFooter').html('$' + new Intl.NumberFormat('es-AR', {minimumFractionDigits: 2}).format(total));
            }
        });

        // Evento al cambiar el switch
        $('#toggleContrasiento').on('change', function () {
            table.draw();
        });
        
        // Ocultar visualmente la columna Contrasiento al inicio si prefieres que no ocupe espacio
        // table.column(9).visible(false); 
    });
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Obtener fecha de última actualización del log (solo esto consultamos directo aquí)
$stmtLog = $pdo->prepare("SELECT fecha_subida, ultima_fecha_dato FROM sicopro_import_log WHERE tipo_importacion = 'SICOPRO' ORDER BY id DESC LIMIT 1");
$stmtLog->execute();
$logData = $stmtLog->fetch(PDO::FETCH_ASSOC);

// Nota: Ya no hacemos la consulta SELECT * aquí. La hace data_sicopro.php
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2>Base SICOPRO (Completa)</h2>
            <div class="text-muted small">
                Importado: <?= $logData ? date('d/m/Y H:i', strtotime($logData['fecha_subida'])) : '-' ?> | 
                <strong>Último Movimiento (MOVFERE): <?= $logData['ultima_fecha_dato'] ?? '-' ?></strong>
            </div>
        </div>
        <a href="../../public/menu.php" class="btn btn-secondary btn-sm">Volver</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaSicopro" class="table table-striped table-hover table-sm" style="width:100%; font-size: 0.85rem;">
                    <thead class="table-dark">
                        <tr>
                            <th>Ejer</th>
                            <th>Trámite</th>
                            <th>Expediente</th>
                            <th>Proveedor</th>
                            <th>Detalle</th>
                            <th class="text-end">Importe</th>
                            <th>Fecha Op.</th>
                            <th>Nro Comp.</th>
                            <th>Fecha Reg.</th>
                        </tr>
                    </thead>
                    <tbody>
                        </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>

<script>
  $('#tablaSicopro').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
        processing: true,
        serverSide: true,
        dom: 'Bfrtip', // Asegúrate de tener la 'B' para los botones de Excel
        
        // --- NUEVA CONFIGURACIÓN DE REGISTROS ---
        lengthMenu: [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "Todos"] ],
        pageLength: 25, // Valor por defecto al cargar
        // ----------------------------------------

        buttons: [
            {
                extend: 'excelHtml5',
                text: 'Exportar Excel',
                className: 'btn btn-success btn-sm',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }
            },
            'pageLength' // Añade este botón para que aparezca el selector de cantidad
        ],
        ajax: {
            url: 'data_sicopro.php',
            type: 'POST'
        },
        order: [[0, 'desc']],
        columnDefs: [
            { targets: 5, className: 'text-end' }
        ]
    });
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
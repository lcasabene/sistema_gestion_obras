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

<script>
    $(document).ready(function () {
        $('#tablaSicopro').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
            processing: true, // Muestra cartel de "Cargando..."
            serverSide: true, // ACTIVA EL MODO SERVIDOR
            ajax: {
                url: 'data_sicopro.php', // Llama al archivo PHP que creamos
                type: 'POST'
            },
            pageLength: 25,
            order: [[0, 'desc']], // Ordenar por Ejer (Columna 0) por defecto
            columnDefs: [
                { targets: 5, className: 'text-end' } // Alinear importe a la derecha
            ]
        });
    });
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

include __DIR__ . '/../../public/_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Comprobantes ARCA</h3>
        <p class="text-muted small mb-0">Comprobantes importados desde AFIP "Mis Comprobantes"</p>
    </div>
    <div>
        <a href="arca_import.php" class="btn btn-outline-primary me-2">
            <i class="bi bi-upload me-1"></i> Importar CSV
        </a>
        <a href="../../public/menu.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Volver
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-bold py-3">
        <i class="bi bi-table me-2 text-primary"></i> Listado de Comprobantes
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tablaFacturas" class="table table-striped table-bordered align-middle" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Fecha</th>
                        <th>Empresa (Emisor)</th>
                        <th>Tipo / Número</th>
                        <th class="text-end">Importe Total</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaFacturas').DataTable({
        processing: true,
        serverSide: true,
        ajax: { url: "obtener_factura.php", type: "POST" },
        columns: [
            { data: 0 },
            { data: 1 },
            { data: 2 },
            { data: 3 },
            { data: 4, className: 'text-end' },
            { data: 5 },
            { data: 6, orderable: false }
        ],
        order: [[0, "desc"]],
        language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json" }
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listado de Facturas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h4>Gestión de Comprobantes ARCA</h4>
        </div>
        <a href="../../public/menu.php" class="btn btn-secondary shadow-sm">
    <i class="bi bi-arrow-left me-2"></i> Volver
</a>
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaFacturas" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Empresa (Emisor)</th>
                            <th>Comprobante</th>
                            <th>Importe Total</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaFacturas').DataTable({
        "processing": true,
        "serverSide": true, // IMPORTANTE: Activa el modo servidor
        "ajax": {
            "url": "obtener_factura.php",
            "type": "POST"
        },
        "columns": [
            { "data": 0 }, // ID
            { "data": 1 }, // Fecha
            { "data": 2 }, // Empresa
            { "data": 3 }, // Numero
            { "data": 4 }, // Importe
            { "data": 5 }, // Estado
            { "data": 6, "orderable": false }  // Botones (no ordenable)
        ],
        "order": [[ 0, "desc" ]], // Ordenar por ID descendente por defecto
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json" // Traducir al español
        }
    });
});
</script>

</body>
</html>
<?php
// modulos/certificados/certificados_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// 1. Consulta a la base de datos
$sql = "SELECT c.*, o.denominacion as obra, e.razon_social as empresa 
        FROM certificados c
        JOIN obras o ON c.obra_id = o.id
        LEFT JOIN empresas e ON c.empresa_id = e.id
        WHERE o.activo = 1 
        ORDER BY c.id DESC";
$certs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// 2. Funciones de Formato (Estandarizadas)
function fmtM($v) { 
    return number_format((float)$v, 2, ',', '.'); 
}
function fmtPct($v) { 
    // Formatea con 2 decimales y agrega el % al final
    return number_format((float)$v, 2, ',', '.') . '%'; 
}
function fmtFri($v) { 
    return number_format((float)$v, 4, ',', '.'); 
}

// 3. Captura de mensajes
$msg = $_GET['msg'] ?? '';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

<div class="container my-4">
    
    <?php if($msg === 'deleted'): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill"></i> Certificado eliminado correctamente.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if($msg === 'ok'): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill"></i> Certificado guardado correctamente.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-0"><i class="bi bi-file-earmark-text"></i> Gestión de Certificados</h3>
            <span class="text-muted small">Listado histórico de mediciones</span>
        </div>
        <div>
            <a href="../../public/menu.php" class="btn btn-secondary me-2 shadow-sm">
                <i class="bi bi-arrow-left-circle me-1"></i> Volver
            </a>
            <a href="../obras/obras_listado.php" class="btn btn-success fw-bold shadow-sm">
                <i class="bi bi-plus-lg"></i> Nuevo (desde Obras)
            </a>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaCertificados" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead class="table-light text-secondary small text-uppercase">
                        <tr>
                            <th>ID</th> 
                            <th>Periodo</th>
                            <th>Nº Cert</th>
                            <th>Obra / Empresa</th>
                            <th class="text-center">% Fis. Mes</th>
                            <th class="text-end">Monto Neto</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($certs as $c): ?>
                        <tr>
                            <td class="text-muted small">#<?= $c['id'] ?></td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= $c['periodo'] ?></span>
                            </td>
                            <td>
                                <span class="fw-bold">Nº <?= $c['nro_certificado'] ?></span>
                                <div class="small text-muted" style="font-size: 0.75rem;"><?= $c['tipo'] ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-primary text-truncate" style="max-width: 300px;" title="<?= htmlspecialchars($c['obra']) ?>">
                                    <?= htmlspecialchars($c['obra']) ?>
                                </div>
                                <small class="text-muted"><i class="bi bi-building"></i> <?= htmlspecialchars($c['empresa'] ?? '-') ?></small>
                            </td>
                            
                            <td class="text-center">
                                <span class="badge bg-info bg-opacity-10 text-dark border border-info">
                                    <?= fmtPct($c['avance_fisico_mensual']) ?>
                                </span>
                            </td>

                            <td class="text-end fw-bold text-success">
                                $ <?= number_format($c['monto_neto_pagar'],0,',','.') ?>
                            </td>

                            <td class="text-center">
                                <?php 
                                    $cls = 'secondary';
                                    if($c['estado']=='APROBADO') $cls='primary';
                                    if($c['estado']=='PAGADO') $cls='success';
                                    if($c['estado']=='BORRADOR') $cls='warning text-dark';
                                    if($c['estado']=='ANULADO') $cls='danger';
                                ?>
                                <span class="badge bg-<?= $cls ?>"><?= $c['estado'] ?></span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group shadow-sm">
                                    <a href="certificados_form.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    
                                    <a href="certificados_eliminar.php?id=<?= $c['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       title="Eliminar definitivamente" 
                                       onclick="return confirm('¿Estás SEGURO de eliminar este certificado?\n\nEsta acción borrará el registro y desvinculará las facturas asociadas.\nNo se puede deshacer.');">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaCertificados').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
        },
        order: [[0, 'desc']], 
        pageLength: 25,
        columnDefs: [
            { orderable: false, targets: 7 } 
        ]
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
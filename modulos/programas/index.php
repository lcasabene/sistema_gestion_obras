<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$programas = $pdo->query("
    SELECT p.*, o.nombre_organismo,
           (SELECT COUNT(*) FROM programa_desembolsos WHERE programa_id=p.id) AS cant_desembolsos,
           (SELECT COUNT(*) FROM programa_rendiciones WHERE programa_id=p.id) AS cant_rendiciones,
           (SELECT COUNT(*) FROM programa_saldos WHERE programa_id=p.id)      AS cant_saldos,
           (SELECT COALESCE(SUM(importe),0) FROM programa_desembolsos WHERE programa_id=p.id) AS total_desembolsado
    FROM programas p
    JOIN organismos_financiadores o ON o.id = p.organismo_id
    WHERE p.activo = 1
    ORDER BY o.nombre_organismo, p.nombre
")->fetchAll();
?>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2 text-success"></i>Programas</h2>
            <small class="text-muted">Gestión de programas por organismo</small>
        </div>
        <div class="d-flex gap-2">
            <a href="../../public/menu.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Menú
            </a>
            <?php if (can_edit()): ?>
            <a href="programa_form.php" class="btn btn-success btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Nuevo Programa
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaPrograms" class="table table-hover table-bordered align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Organismo</th>
                            <th>Código</th>
                            <th>Nombre del Programa</th>
                            <th>Moneda</th>
                            <th class="text-end">Monto Total</th>
                            <th class="text-end">Total Desembolsado</th>
                            <th class="text-center">Desemb.</th>
                            <th class="text-center">Rend.</th>
                            <th class="text-center">Saldos</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programas as $p): ?>
                        <tr>
                            <td class="small text-muted"><?= htmlspecialchars($p['nombre_organismo']) ?></td>
                            <td><span class="badge bg-success"><?= htmlspecialchars($p['codigo']) ?></span></td>
                            <td class="fw-semibold"><?= htmlspecialchars($p['nombre']) ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= $p['moneda'] ?></span></td>
                            <td class="text-end font-monospace"><?= $p['monto_total'] > 0 ? number_format($p['monto_total'], 2, ',', '.') : '-' ?></td>
                            <td class="text-end font-monospace text-success fw-bold"><?= number_format($p['total_desembolsado'], 2, ',', '.') ?></td>
                            <td class="text-center"><span class="badge bg-info text-dark"><?= $p['cant_desembolsos'] ?></span></td>
                            <td class="text-center"><span class="badge bg-warning text-dark"><?= $p['cant_rendiciones'] ?></span></td>
                            <td class="text-center"><span class="badge bg-primary"><?= $p['cant_saldos'] ?></span></td>
                            <td class="text-center">
                                <a href="programa_ver.php?id=<?= $p['id'] ?>" class="btn btn-outline-success btn-sm" title="Ver detalle">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (can_edit()): ?>
                                <a href="programa_form.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary btn-sm" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (can_delete()): ?>
                                <a href="programa_eliminar.php?id=<?= $p['id'] ?>"
                                   class="btn btn-outline-danger btn-sm"
                                   title="Eliminar"
                                   onclick="return confirm('¿Eliminar este programa y todos sus registros?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function(){
    $('#tablaPrograms').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
        order: [[0,'asc'],[2,'asc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: 9 }]
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

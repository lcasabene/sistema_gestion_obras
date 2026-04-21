<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

$bancos = $pdo->query("
    SELECT o.*,
           COUNT(p.id) AS total_programas
    FROM organismos_financiadores o
    LEFT JOIN programas p ON p.organismo_id = o.id AND p.activo = 1
    GROUP BY o.id
    ORDER BY o.nombre_organismo
")->fetchAll();

$flash = $_GET['msg'] ?? '';
include __DIR__ . '/../../public/_header.php';
?>

<div class="container-fluid my-4" style="max-width:1100px">

    <?php if ($flash === 'creado'): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Banco creado correctamente. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php elseif ($flash === 'eliminado'): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Banco eliminado correctamente. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php elseif ($flash === 'con_programas'): ?>
    <div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i>No se puede eliminar: el banco tiene programas asociados. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0"><i class="bi bi-bank2 me-2 text-success"></i>Bancos Financiadores</h4>
            <small class="text-muted">BID, Banco Mundial, CAF y otros organismos de financiamiento</small>
        </div>
        <div class="d-flex gap-2">
            <?php if (can_edit()): ?>
            <a href="banco_form.php" class="btn btn-success btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Nuevo Banco
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-grid me-1"></i>Programas
            </a>
            <a href="../../public/menu.php" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-house me-1"></i>Menú
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table id="tblBancos" class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width:80px">Sigla</th>
                        <th>Nombre del Banco / Organismo</th>
                        <th>País</th>
                        <th>Sitio Web</th>
                        <th>Descripción</th>
                        <th class="text-center">Programas</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center" style="width:110px">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bancos as $b): ?>
                    <tr>
                        <td>
                            <?php if (!empty($b['sigla'])): ?>
                            <span class="badge bg-primary fs-6"><?= htmlspecialchars($b['sigla']) ?></span>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= htmlspecialchars($b['nombre_organismo']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($b['pais'] ?? '–') ?></td>
                        <td class="small">
                            <?php if (!empty($b['sitio_web'])): ?>
                            <a href="<?= htmlspecialchars($b['sitio_web']) ?>" target="_blank" class="text-truncate d-inline-block" style="max-width:180px">
                                <i class="bi bi-box-arrow-up-right me-1"></i><?= htmlspecialchars($b['sitio_web']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars(mb_strimwidth($b['descripcion_programa'] ?? '', 0, 60, '…')) ?></td>
                        <td class="text-center">
                            <?php if ($b['total_programas'] > 0): ?>
                            <a href="index.php?organismo_id=<?= $b['id'] ?>" class="badge bg-success text-decoration-none">
                                <?= $b['total_programas'] ?> programa<?= $b['total_programas'] > 1 ? 's' : '' ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted small">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $b['activo']
                                ? '<span class="badge bg-success">Activo</span>'
                                : '<span class="badge bg-secondary">Inactivo</span>' ?>
                        </td>
                        <td class="text-center">
                            <?php if (can_edit()): ?>
                            <a href="banco_form.php?id=<?= $b['id'] ?>" class="btn btn-outline-primary btn-sm py-0 px-1" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (can_delete() && $b['total_programas'] == 0): ?>
                            <a href="banco_eliminar.php?id=<?= $b['id'] ?>"
                               class="btn btn-outline-danger btn-sm py-0 px-1"
                               title="Eliminar"
                               onclick="return confirm('¿Eliminar este banco financiador?')">
                                <i class="bi bi-trash"></i>
                            </a>
                            <?php elseif ($b['total_programas'] > 0): ?>
                            <span class="btn btn-outline-secondary btn-sm py-0 px-1 disabled" title="Tiene programas asociados">
                                <i class="bi bi-lock"></i>
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function(){
    $('#tblBancos').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
        pageLength: 25,
        order: [[1, 'asc']],
        columnDefs: [{ orderable: false, targets: [7] }]
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

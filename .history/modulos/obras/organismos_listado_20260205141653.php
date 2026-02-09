<?php
// organismos_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$listado = $pdo->query("SELECT * FROM organismos_financiadores WHERE activo=1 ORDER BY nombre_organismo ASC")->fetchAll();
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-0">Administración de Organismos y Programas</h3>
            <p class="text-muted small mb-0">Gestión de entes financiadores y líneas de crédito.</p>
        </div>
        <a href="../../public/menu.php" class="btn btn-secondary shadow-sm">
            <i class="bi bi-house-door"></i> Volver al Menú
        </a>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <a href="organismos_form.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Nuevo Organismo
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="80">ID</th>
                        <th width="200">Identificación (Sigla)</th>
                        <th>Descripción del Programa / N° Identificación</th>
                        <th width="100" class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listado)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">No hay organismos registrados.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($listado as $l): ?>
                        <tr>
                            <td class="text-muted"><?= $l['id'] ?></td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($l['nombre_organismo']) ?></span></td>
                            <td><?= htmlspecialchars($l['descripcion_programa']) ?></td>
                            <td class="text-center">
                                <a href="organismos_form.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-dark" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
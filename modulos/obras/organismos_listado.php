<?php
// organismos_listado.php - Con Líneas de Crédito
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$listado = $pdo->query("SELECT * FROM organismos_financiadores WHERE activo=1 ORDER BY nombre_organismo ASC")->fetchAll();

// Cargar líneas de crédito por organismo
$lineasPorOrg = [];
try {
    $lcs = $pdo->query("SELECT * FROM lineas_credito WHERE activo=1 ORDER BY organismo_id, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lcs as $lc) {
        $lineasPorOrg[$lc['organismo_id']][] = $lc;
    }
} catch (Exception $e) { /* table may not exist yet */ }
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
                        <th width="180">Organismo</th>
                        <th width="200">Descripción</th>
                        <th>Líneas de Crédito</th>
                        <th width="100" class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listado)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">No hay organismos registrados.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($listado as $l): 
                            $lineas = $lineasPorOrg[$l['id']] ?? [];
                        ?>
                        <tr>
                            <td class="text-muted"><?= $l['id'] ?></td>
                            <td><span class="badge bg-info text-dark fs-6"><?= htmlspecialchars($l['nombre_organismo']) ?></span></td>
                            <td class="small"><?= htmlspecialchars($l['descripcion_programa'] ?? '') ?></td>
                            <td>
                                <?php if (empty($lineas)): ?>
                                    <span class="text-muted small">Sin líneas</span>
                                <?php else: ?>
                                    <?php foreach($lineas as $lc): ?>
                                        <div class="mb-1">
                                            <span class="badge bg-warning text-dark font-monospace"><?= htmlspecialchars($lc['codigo']) ?></span>
                                            <span class="small text-muted"><?= htmlspecialchars($lc['descripcion'] ?? '') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
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
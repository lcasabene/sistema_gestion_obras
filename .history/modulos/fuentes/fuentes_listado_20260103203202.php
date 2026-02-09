<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Consulta solo fuentes activas
$stmt = $pdo->query("SELECT * FROM fuentes_financiamiento WHERE activo=1 ORDER BY codigo ASC");
$fuentes = $stmt->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0">Fuentes de Financiamiento</h2>
        <div class="text-muted">Organismos y orígenes de fondos.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="../../public/index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Volver al Inicio</a>
        <a href="fuentes_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nueva Fuente</a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table id="tablaFuentes" class="table table-hover table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th width="100">Código</th>
                        <th>Nombre / Descripción</th>
                        <th width="120" class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fuentes as $f): ?>
                    <tr>
                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($f['codigo']); ?></td>
                        <td><?php echo htmlspecialchars($f['nombre']); ?></td>
                        <td class="text-center">
                            <a href="fuentes_form.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="fuentes_eliminar.php?id=<?php echo $f['id']; ?>" 
                               class="btn btn-sm btn-outline-danger" 
                               title="Eliminar"
                               onclick="return confirm('¿Está seguro de eliminar esta fuente?');">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    $('#tablaFuentes').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
        order: [[0, 'asc']], // Ordenar por código
        pageLength: 25
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
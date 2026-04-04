<?php
// regiones_listado.php - CRUD de Regiones (configurable)
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$mensaje = '';
$tipo_alerta = '';

// GUARDAR / ACTUALIZAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];
    $rid = (int)($_POST['region_id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');

    try {
        if ($accion === 'guardar' && !empty($nombre)) {
            if ($rid > 0) {
                $pdo->prepare("UPDATE regiones SET nombre = ? WHERE id = ?")->execute([$nombre, $rid]);
                $mensaje = "Región actualizada.";
            } else {
                $pdo->prepare("INSERT INTO regiones (nombre, activo) VALUES (?, 1)")->execute([$nombre]);
                $mensaje = "Región creada.";
            }
            $tipo_alerta = "success";
        } elseif ($accion === 'eliminar' && $rid > 0) {
            $pdo->prepare("UPDATE regiones SET activo = 0 WHERE id = ?")->execute([$rid]);
            $mensaje = "Región desactivada.";
            $tipo_alerta = "warning";
        }
    } catch (PDOException $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

$regiones = $pdo->query("SELECT * FROM regiones WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../public/_header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-0"><i class="bi bi-geo-alt"></i> Regiones</h3>
            <p class="text-muted small mb-0">Configuración de regiones geográficas.</p>
        </div>
        <a href="../../public/menu.php" class="btn btn-secondary shadow-sm">
            <i class="bi bi-house-door"></i> Volver al Menú
        </a>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show" role="alert">
            <?= $mensaje ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-5">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="bi bi-plus-lg"></i> Agregar / Editar Región
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="accion" value="guardar">
                        <input type="hidden" name="region_id" id="editId" value="0">
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Nombre de la Región *</label>
                            <input type="text" name="nombre" id="editNombre" class="form-control" required placeholder="Ej: Confluencia">
                        </div>
                        <button type="submit" class="btn btn-primary fw-bold">
                            <i class="bi bi-save me-1"></i> Guardar
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="limpiarForm()">Limpiar</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Regiones Activas (<?= count($regiones) ?>)</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nombre</th>
                                <th width="120" class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($regiones)): ?>
                                <tr><td colspan="2" class="text-center py-3 text-muted">No hay regiones configuradas.</td></tr>
                            <?php else: ?>
                                <?php foreach($regiones as $r): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($r['nombre']) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary" onclick="editar(<?= $r['id'] ?>, '<?= htmlspecialchars($r['nombre'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Desactivar esta región?')">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="region_id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function editar(id, nombre) {
    document.getElementById('editId').value = id;
    document.getElementById('editNombre').value = nombre;
    document.getElementById('editNombre').focus();
}
function limpiarForm() {
    document.getElementById('editId').value = 0;
    document.getElementById('editNombre').value = '';
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

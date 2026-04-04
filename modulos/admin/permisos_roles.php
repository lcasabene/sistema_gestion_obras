<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

if (!is_admin()) {
    die("Acceso denegado.");
}

$mensaje = '';
$tipo_alerta = '';

// GUARDAR permisos de un rol
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rol_id'])) {
    $rol_id = (int)$_POST['rol_id'];
    $modulos_sel = $_POST['modulos'] ?? [];

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM rol_modulos WHERE rol_id = ?")->execute([$rol_id]);
        $stmt = $pdo->prepare("INSERT INTO rol_modulos (rol_id, modulo_clave) VALUES (?, ?)");
        foreach ($modulos_sel as $clave) {
            $stmt->execute([$rol_id, $clave]);
        }
        $pdo->commit();
        $mensaje = "Permisos actualizados correctamente.";
        $tipo_alerta = "success";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "Error: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// Cargar roles y módulos
$roles   = $pdo->query("SELECT * FROM roles ORDER BY nombre")->fetchAll();
$modulos = $pdo->query("SELECT * FROM modulos WHERE activo=1 ORDER BY nombre")->fetchAll();

// Rol seleccionado (GET o POST redirect)
$rol_activo = (int)($_GET['rol_id'] ?? ($_POST['rol_id'] ?? ($roles[0]['id'] ?? 0)));

// Permisos actuales del rol seleccionado
$permisos_actuales = [];
if ($rol_activo) {
    $stmt = $pdo->prepare("SELECT modulo_clave FROM rol_modulos WHERE rol_id = ?");
    $stmt->execute([$rol_activo]);
    $permisos_actuales = array_column($stmt->fetchAll(), 'modulo_clave');
}

$es_admin_rol = false;
foreach ($roles as $r) {
    if ($r['id'] == $rol_activo && $r['nombre'] === 'ADMIN') {
        $es_admin_rol = true;
        break;
    }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Permisos por Rol</h3>
        <p class="text-muted small mb-0">Asigne qué módulos puede ver cada rol</p>
    </div>
    <div>
        <a href="modulos_admin.php" class="btn btn-outline-primary me-2">
            <i class="bi bi-grid-3x3-gap me-1"></i> Gestionar Módulos
        </a>
        <a href="usuarios.php" class="btn btn-outline-secondary me-2">
            <i class="bi bi-people me-1"></i> Usuarios
        </a>
        <a href="../../public/menu.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Volver
        </a>
    </div>
</div>

<?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipo_alerta ?> shadow-sm"><?= $mensaje ?></div>
<?php endif; ?>

<!-- Tabla de capacidades por rol -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white fw-bold py-3">
        <i class="bi bi-table me-2 text-primary"></i> Capacidades por tipo de rol
    </div>
    <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0 text-center">
            <thead class="table-light">
                <tr>
                    <th class="text-start ps-3">Característica</th>
                    <th><span class="badge bg-secondary fs-6">Consulta</span></th>
                    <th><span class="badge bg-primary fs-6">Editor</span></th>
                    <th><span class="badge bg-dark fs-6">Admin</span></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-start ps-3 fw-bold">Ver datos</td>
                    <td><i class="bi bi-check-square-fill text-success fs-5"></i></td>
                    <td><i class="bi bi-check-square-fill text-success fs-5"></i></td>
                    <td><i class="bi bi-check-square-fill text-success fs-5"></i></td>
                </tr>
                <tr>
                    <td class="text-start ps-3 fw-bold">Crear / Editar registros</td>
                    <td><i class="bi bi-x-square-fill text-danger fs-5"></i></td>
                    <td><i class="bi bi-check-square-fill text-success fs-5"></i></td>
                    <td><i class="bi bi-check-square-fill text-success fs-5"></i></td>
                </tr>
                <tr>
                    <td class="text-start ps-3 fw-bold">Eliminar registros</td>
                    <td><i class="bi bi-x-square-fill text-danger fs-5"></i></td>
                    <td><i class="bi bi-exclamation-triangle-fill text-warning fs-5"></i> <small class="text-muted">Opcional por módulo</small></td>
                    <td><i class="bi bi-check-square-fill text-success fs-5"></i></td>
                </tr>
                <tr>
                    <td class="text-start ps-3 fw-bold">Gestionar usuarios</td>
                    <td><i class="bi bi-x-square-fill text-danger fs-5"></i></td>
                    <td><i class="bi bi-x-square-fill text-danger fs-5"></i></td>
                    <td><i class="bi bi-check-square-fill text-success fs-5"></i></td>
                </tr>
                <tr>
                    <td class="text-start ps-3 fw-bold">Configuración técnica</td>
                    <td><i class="bi bi-x-square-fill text-danger fs-5"></i></td>
                    <td><i class="bi bi-x-square-fill text-danger fs-5"></i></td>
                    <td><i class="bi bi-check-square-fill text-success fs-5"></i></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white text-muted small">
        <i class="bi bi-info-circle me-1"></i>
        Aquí abajo configurás <strong>qué módulos</strong> puede ver cada rol.
        Las capacidades de acción (crear/editar/eliminar) son fijas por tipo de rol.
    </div>
</div>

<div class="row g-4">
    <!-- Selector de rol -->
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold py-3">
                <i class="bi bi-person-badge me-2 text-primary"></i> Roles
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($roles as $rol): ?>
                <a href="?rol_id=<?= $rol['id'] ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                          <?= $rol['id'] == $rol_activo ? 'active' : '' ?>">
                    <?= htmlspecialchars($rol['nombre']) ?>
                    <?php if ($rol['nombre'] === 'ADMIN'): ?>
                        <span class="badge bg-dark ms-1">Superadmin</span>
                    <?php else: ?>
                        <?php
                        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM rol_modulos WHERE rol_id=?");
                        $stmt2->execute([$rol['id']]);
                        $cnt = $stmt2->fetchColumn();
                        ?>
                        <span class="badge bg-secondary rounded-pill"><?= $cnt ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Módulos del rol -->
    <div class="col-md-9">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-grid me-2 text-primary"></i> Módulos habilitados</span>
                <?php
                $rol_nombre = '';
                foreach ($roles as $r) { if ($r['id'] == $rol_activo) { $rol_nombre = $r['nombre']; break; } }
                ?>
                <span class="badge bg-primary fs-6"><?= htmlspecialchars($rol_nombre) ?></span>
            </div>
            <div class="card-body p-4">

                <?php if ($es_admin_rol): ?>
                <div class="alert alert-dark mb-0">
                    <i class="bi bi-shield-fill-check me-2"></i>
                    El rol <strong>ADMIN</strong> tiene acceso a <em>todos los módulos</em> del sistema por defecto.
                    No es necesario asignar permisos individuales.
                </div>

                <?php elseif ($rol_activo): ?>
                <form method="POST">
                    <input type="hidden" name="rol_id" value="<?= $rol_activo ?>">

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <p class="text-muted small mb-0">Tilde los módulos a los que este rol tendrá acceso:</p>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="toggleAll(true)">
                                Seleccionar todo
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)">
                                Deseleccionar todo
                            </button>
                        </div>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($modulos as $mod): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card border <?= in_array($mod['clave'], $permisos_actuales) ? 'border-primary bg-primary bg-opacity-10' : 'border-light' ?> h-100">
                                <div class="card-body p-3">
                                    <div class="form-check">
                                        <input class="form-check-input modulo-check" type="checkbox"
                                               name="modulos[]"
                                               value="<?= $mod['clave'] ?>"
                                               id="mod_<?= $mod['clave'] ?>"
                                               <?= in_array($mod['clave'], $permisos_actuales) ? 'checked' : '' ?>
                                               onchange="this.closest('.card').className = this.checked ? 'card border border-primary bg-primary bg-opacity-10 h-100' : 'card border border-light h-100'">
                                        <label class="form-check-label fw-bold" for="mod_<?= $mod['clave'] ?>">
                                            <i class="bi <?= htmlspecialchars($mod['icono'] ?? 'bi-app') ?> me-1 text-primary"></i>
                                            <?= htmlspecialchars($mod['nombre']) ?>
                                        </label>
                                    </div>
                                    <?php if ($mod['descripcion']): ?>
                                        <p class="text-muted small mb-0 mt-1 ms-4"><?= htmlspecialchars($mod['descripcion']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-5">
                            <i class="bi bi-check-lg me-1"></i> Guardar permisos
                        </button>
                    </div>
                </form>

                <?php else: ?>
                <p class="text-muted text-center py-4">Seleccione un rol de la lista.</p>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script>
function toggleAll(check) {
    document.querySelectorAll('.modulo-check').forEach(function(cb) {
        cb.checked = check;
        cb.closest('.card').className = check
            ? 'card border border-primary bg-primary bg-opacity-10 h-100'
            : 'card border border-light h-100';
    });
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

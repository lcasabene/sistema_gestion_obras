<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

if (!is_admin()) {
    die("Acceso denegado.");
}

$mensaje = '';
$tipo_alerta = '';

// -------------------------------------------------------
// ACCIONES POST
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        // Alta o edición de módulo
        $id          = (int)($_POST['id'] ?? 0);
        $clave       = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['clave'] ?? '')));
        $nombre      = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $icono       = trim($_POST['icono'] ?? 'bi-app');
        $activo      = isset($_POST['activo']) ? 1 : 0;

        if (!$clave || !$nombre) {
            $mensaje = "La clave y el nombre son obligatorios.";
            $tipo_alerta = "danger";
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE modulos SET nombre=?, descripcion=?, icono=?, activo=? WHERE id=?")
                        ->execute([$nombre, $descripcion, $icono, $activo, $id]);
                    $mensaje = "Módulo <strong>$nombre</strong> actualizado.";
                } else {
                    $pdo->prepare("INSERT INTO modulos (clave, nombre, descripcion, icono, activo) VALUES (?,?,?,?,?)")
                        ->execute([$clave, $nombre, $descripcion, $icono, $activo]);
                    $mensaje = "Módulo <strong>$nombre</strong> registrado correctamente.";
                }
                $tipo_alerta = "success";
            } catch (PDOException $e) {
                $mensaje = "Error: " . $e->getMessage();
                $tipo_alerta = "danger";
            }
        }

    } elseif ($accion === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE modulos SET activo = 1 - activo WHERE id = ?")->execute([$id]);
        header("Location: modulos_admin.php?ok=toggle");
        exit;

    } elseif ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        // Verificar que no tenga permisos asignados
        $en_uso = (int)$pdo->prepare("SELECT COUNT(*) FROM rol_modulos WHERE modulo_clave = (SELECT clave FROM modulos WHERE id=?)")
            ->execute([$id]) ? $pdo->query("SELECT COUNT(*) FROM rol_modulos WHERE modulo_clave = (SELECT clave FROM modulos WHERE id=$id)")->fetchColumn() : 0;
        if ($en_uso > 0) {
            $mensaje = "No se puede eliminar: el módulo tiene permisos asignados a $en_uso rol(es). Primero quitá los permisos en <a href='permisos_roles.php'>Permisos por Rol</a>.";
            $tipo_alerta = "warning";
        } else {
            $pdo->prepare("DELETE FROM modulos WHERE id=?")->execute([$id]);
            $mensaje = "Módulo eliminado.";
            $tipo_alerta = "success";
        }
    }
}

if (isset($_GET['ok'])) {
    $mensaje = "Cambio guardado.";
    $tipo_alerta = "success";
}

// -------------------------------------------------------
// CARGAR DATOS
// -------------------------------------------------------
$modulos_registrados = $pdo->query("SELECT * FROM modulos ORDER BY activo DESC, nombre")->fetchAll();
$claves_registradas  = array_column($modulos_registrados, 'clave');

// Auto-detectar carpetas en modulos/ que no están registradas
$carpetas_sin_registrar = [];
$dir_modulos = realpath(__DIR__ . '/..');
if ($dir_modulos && is_dir($dir_modulos)) {
    foreach (new DirectoryIterator($dir_modulos) as $item) {
        if ($item->isDir() && !$item->isDot()) {
            $carpeta = $item->getFilename();
            if (!in_array($carpeta, $claves_registradas)) {
                $carpetas_sin_registrar[] = $carpeta;
            }
        }
    }
    sort($carpetas_sin_registrar);
}

// Módulo a editar (si viene por GET)
$editar = null;
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM modulos WHERE id=?");
    $stmt->execute([(int)$_GET['editar']]);
    $editar = $stmt->fetch();
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Gestión de Módulos</h3>
        <p class="text-muted small mb-0">Registrá y configurá los módulos disponibles en el sistema</p>
    </div>
    <div>
        <a href="permisos_roles.php" class="btn btn-outline-danger me-2">
            <i class="bi bi-shield-lock me-1"></i> Permisos por Rol
        </a>
        <a href="usuarios.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Volver
        </a>
    </div>
</div>

<?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipo_alerta ?> shadow-sm"><?= $mensaje ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- COLUMNA IZQUIERDA: Formulario -->
    <div class="col-md-4">

        <!-- Carpetas sin registrar -->
        <?php if (!empty($carpetas_sin_registrar)): ?>
        <div class="card shadow-sm border-warning mb-3">
            <div class="card-header bg-warning-subtle fw-bold py-2">
                <i class="bi bi-folder-plus me-2 text-warning"></i> Carpetas no registradas
            </div>
            <div class="card-body p-2">
                <p class="text-muted small mb-2">Click en una carpeta para pre-cargar el formulario:</p>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($carpetas_sin_registrar as $carpeta): ?>
                    <button type="button" class="btn btn-sm btn-outline-warning"
                            onclick="precargar('<?= htmlspecialchars($carpeta) ?>')">
                        <i class="bi bi-folder me-1"></i><?= htmlspecialchars($carpeta) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Formulario alta/edición -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-bold py-3">
                <i class="bi bi-<?= $editar ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
                <?= $editar ? 'Editar módulo' : 'Registrar nuevo módulo' ?>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="formModulo">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id" value="<?= $editar['id'] ?? 0 ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Clave <span class="text-danger">*</span></label>
                        <input type="text" name="clave" id="inputClave" class="form-control font-monospace"
                               value="<?= htmlspecialchars($editar['clave'] ?? '') ?>"
                               placeholder="ej: nuevo_modulo"
                               pattern="[a-z0-9_]+" title="Solo letras minúsculas, números y guion bajo"
                               <?= $editar ? 'readonly' : 'required' ?>>
                        <div class="form-text">Nombre de la carpeta en <code>modulos/</code>. No se puede cambiar luego.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre visible <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" id="inputNombre" class="form-control"
                               value="<?= htmlspecialchars($editar['nombre'] ?? '') ?>"
                               placeholder="ej: Mi Nuevo Módulo" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Descripción</label>
                        <input type="text" name="descripcion" class="form-control"
                               value="<?= htmlspecialchars($editar['descripcion'] ?? '') ?>"
                               placeholder="Breve descripción del módulo">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Ícono Bootstrap Icons</label>
                        <div class="input-group">
                            <span class="input-group-text" id="iconPreview">
                                <i class="bi <?= htmlspecialchars($editar['icono'] ?? 'bi-app') ?>"></i>
                            </span>
                            <input type="text" name="icono" id="inputIcono" class="form-control font-monospace"
                                   value="<?= htmlspecialchars($editar['icono'] ?? 'bi-app') ?>"
                                   placeholder="bi-app"
                                   oninput="document.querySelector('#iconPreview i').className='bi '+this.value">
                        </div>
                        <div class="form-text">
                            Ver íconos en <a href="https://icons.getbootstrap.com" target="_blank">icons.getbootstrap.com</a>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="activo" id="activoCheck"
                                   <?= (!$editar || $editar['activo']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="activoCheck">Módulo activo</label>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-check-lg me-1"></i> <?= $editar ? 'Guardar cambios' : 'Registrar módulo' ?>
                        </button>
                        <?php if ($editar): ?>
                        <a href="modulos_admin.php" class="btn btn-outline-secondary">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- COLUMNA DERECHA: Listado -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul me-2 text-primary"></i> Módulos registrados</span>
                <span class="badge bg-secondary rounded-pill"><?= count($modulos_registrados) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Ícono</th>
                            <th>Clave</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th class="text-center">Estado</th>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modulos_registrados as $mod): ?>
                        <tr class="<?= !$mod['activo'] ? 'opacity-50' : '' ?>">
                            <td class="ps-3 text-center">
                                <i class="bi <?= htmlspecialchars($mod['icono'] ?? 'bi-app') ?> fs-5 text-primary"></i>
                            </td>
                            <td>
                                <code class="small"><?= htmlspecialchars($mod['clave']) ?></code>
                            </td>
                            <td class="fw-bold"><?= htmlspecialchars($mod['nombre']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($mod['descripcion'] ?? '') ?></td>
                            <td class="text-center">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="accion" value="toggle">
                                    <input type="hidden" name="id" value="<?= $mod['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $mod['activo'] ? 'btn-success' : 'btn-secondary' ?>"
                                            title="<?= $mod['activo'] ? 'Activo - click para desactivar' : 'Inactivo - click para activar' ?>">
                                        <i class="bi bi-<?= $mod['activo'] ? 'check-circle' : 'x-circle' ?>"></i>
                                    </button>
                                </form>
                            </td>
                            <td class="text-end pe-3">
                                <a href="?editar=<?= $mod['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                                   title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('¿Eliminar el módulo <?= htmlspecialchars($mod['nombre']) ?>?\nSe perderán los permisos asignados.')">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= $mod['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                Los módulos inactivos no aparecen en el panel de permisos ni en el menú.
                La <strong>clave</strong> debe coincidir con la carpeta en <code>modulos/</code>.
            </div>
        </div>
    </div>
</div>

<script>
function precargar(carpeta) {
    document.getElementById('inputClave').value = carpeta;
    document.getElementById('inputNombre').value = carpeta.charAt(0).toUpperCase() + carpeta.slice(1).replace(/_/g, ' ');
    document.getElementById('formModulo').scrollIntoView({ behavior: 'smooth' });
    document.getElementById('inputNombre').focus();
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

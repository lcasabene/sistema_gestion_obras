<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

if (!is_admin()) {
    die("Acceso denegado.");
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: usuarios.php");
    exit;
}

$mensaje = "";
$error = "";

// Cargar datos del usuario
$stmt = $pdo->prepare("SELECT u.*, r.id as rol_id FROM usuarios u LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id LEFT JOIN roles r ON r.id = ur.rol_id WHERE u.id = ? LIMIT 1");
$stmt->execute([$id]);
$u = $stmt->fetch();

if (!$u) {
    header("Location: usuarios.php");
    exit;
}

// Roles disponibles
$roles = $pdo->query("SELECT * FROM roles ORDER BY nombre ASC")->fetchAll();

// -------------------------------------------------------
// PROCESAR FORMULARIO
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion   = $_POST['accion'] ?? '';
    
    if ($accion === 'datos') {
        $nombre  = trim($_POST['nombre'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $activo  = isset($_POST['activo']) ? 1 : 0;
        $rol_id  = (int)($_POST['rol_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, activo=? WHERE id=?")
                ->execute([$nombre, $email, $activo, $id]);

            // Actualizar rol
            $pdo->prepare("DELETE FROM usuario_roles WHERE usuario_id=?")->execute([$id]);
            if ($rol_id > 0) {
                $pdo->prepare("INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (?,?)")->execute([$id, $rol_id]);
            }

            $pdo->commit();
            $mensaje = "Datos actualizados correctamente.";
            // Recargar datos
            $stmt->execute([$id]);
            $u = $stmt->fetch();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }

    } elseif ($accion === 'password') {
        $pass_nueva    = $_POST['password_nueva'] ?? '';
        $pass_confirma = $_POST['password_confirma'] ?? '';

        if (strlen($pass_nueva) < 6) {
            $error = "La contraseña debe tener al menos 6 caracteres.";
        } elseif ($pass_nueva !== $pass_confirma) {
            $error = "Las contraseñas no coinciden.";
        } else {
            $hash = password_hash($pass_nueva, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET password_hash=? WHERE id=?")->execute([$hash, $id]);
            $mensaje = "Contraseña actualizada correctamente.";
        }
    }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0"><i class="bi bi-person-gear"></i> Editar Usuario</h3>
        <p class="text-muted small mb-0"><?= htmlspecialchars($u['usuario']) ?></p>
    </div>
    <a href="usuarios.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
</div>

<?php if ($mensaje): ?>
    <div class="alert alert-success shadow-sm"><?= $mensaje ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm"><?= $error ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Datos generales -->
    <div class="col-md-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-bold py-3">
                <i class="bi bi-person-fill me-2 text-primary"></i> Datos del Usuario
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <input type="hidden" name="accion" value="datos">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Usuario (Login)</label>
                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($u['usuario']) ?>" disabled>
                        <div class="form-text">El nombre de usuario no se puede cambiar.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre Completo</label>
                        <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($u['nombre']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Correo Electrónico</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Rol de Acceso</label>
                        <select name="rol_id" class="form-select">
                            <option value="">Sin rol</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>" <?= $u['rol_id'] == $r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="activo" id="activo" <?= $u['activo'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="activo">Usuario activo</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i> Guardar cambios
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Cambio de contraseña -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-bold py-3">
                <i class="bi bi-key-fill me-2 text-warning"></i> Cambiar Contraseña
            </div>
            <div class="card-body p-4">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="accion" value="password">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nueva Contraseña</label>
                        <input type="password" name="password_nueva" class="form-control" 
                               autocomplete="new-password" minlength="6" required
                               placeholder="Mínimo 6 caracteres">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Confirmar Contraseña</label>
                        <input type="password" name="password_confirma" class="form-control" 
                               autocomplete="new-password" required
                               placeholder="Repetir contraseña">
                    </div>

                    <button type="submit" class="btn btn-warning px-4">
                        <i class="bi bi-lock me-1"></i> Cambiar contraseña
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 mt-3">
            <div class="card-body p-3">
                <div class="small text-muted">
                    <div><strong>Último login:</strong> <?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : 'Nunca' ?></div>
                    <div><strong>Creado:</strong> <?= date('d/m/Y', strtotime($u['created_at'])) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

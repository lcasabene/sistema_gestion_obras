<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

// Seguridad: Solo ADMIN puede crear usuarios
if ($_SESSION['rol'] !== 'Admin') {
    die("Acceso denegado.");
}

$mensaje = "";
$error = "";

// Obtener roles para el desplegable
$stmtRoles = $pdo->query("SELECT * FROM roles ORDER BY nombre ASC");
$rolesDisponibles = $stmtRoles->fetchAll();

// ---------------------------------------------------------
// PROCESAR FORMULARIO (POST)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario']);
    $nombre   = trim($_POST['nombre']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $rol_id   = $_POST['rol_id'];

    if (empty($usuario) || empty($password) || empty($rol_id)) {
        $error = "Usuario, Contraseña y Rol son obligatorios.";
    } else {
        try {
            // Iniciamos transacción: O se guardan los dos (usuario y rol) o ninguno.
            $pdo->beginTransaction();

            // 1. Insertar Usuario
            $passHash = password_hash($password, PASSWORD_DEFAULT);
            $sqlUser = "INSERT INTO usuarios (usuario, nombre, email, password_hash, activo) VALUES (?, ?, ?, ?, 1)";
            $stmt = $pdo->prepare($sqlUser);
            $stmt->execute([$usuario, $nombre, $email, $passHash]);
            
            // Obtener el ID generado automáticamente
            $nuevo_id = $pdo->lastInsertId();

            // 2. Asignar Rol
            $sqlRol = "INSERT INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)";
            $stmtRol = $pdo->prepare($sqlRol);
            $stmtRol->execute([$nuevo_id, $rol_id]);

            // Confirmar cambios
            $pdo->commit();
            $mensaje = "¡Usuario <strong>$usuario</strong> creado exitosamente!";
            
            // Limpiar formulario (opcional)
            $usuario = $nombre = $email = "";

        } catch (PDOException $e) {
            $pdo->rollBack();
            // Error 23000 suele ser "Duplicate entry" (Usuario ya existe)
            if ($e->getCode() == 23000) {
                $error = "Error: El nombre de usuario '$usuario' ya existe.";
            } else {
                $error = "Error en base de datos: " . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../../menu/_header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow border-0">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-person-plus-fill"></i> Nuevo Usuario</h5>
                </div>
                <div class="card-body p-4">

                    <?php if ($mensaje): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= $mensaje ?>
                            <a href="usuarios.php" class="btn btn-sm btn-success ms-3">Ir al listado</a>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i> <?= $error ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" autocomplete="off">
                        
                        <h6 class="text-muted border-bottom pb-2 mb-3">Datos de Cuenta</h6>
                        
                        <div class="mb-3">
                            <label for="usuario" class="form-label fw-bold">Usuario (Login) *</label>
                            <input type="text" class="form-control" name="usuario" id="usuario" required 
                                   placeholder="ej: jperalta" value="<?= isset($usuario) && !$mensaje ? htmlspecialchars($usuario) : '' ?>">
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label for="password" class="form-label fw-bold">Contraseña *</label>
                                <input type="password" class="form-control" name="password" id="password" required autocomplete="new-password">
                            </div>
                            <div class="col-md-6">
                                <label for="rol_id" class="form-label fw-bold text-primary">Rol de Acceso *</label>
                                <select name="rol_id" id="rol_id" class="form-select border-primary" required>
                                    <option value="">Seleccione...</option>
                                    <?php foreach ($rolesDisponibles as $r): ?>
                                        <option value="<?= $r['id'] ?>">
                                            <?= $r['nombre'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <h6 class="text-muted border-bottom pb-2 mb-3 mt-4">Datos Personales</h6>

                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre Completo</label>
                            <input type="text" class="form-control" name="nombre" id="nombre" 
                                   placeholder="ej: Juan Peralta" value="<?= isset($nombre) && !$mensaje ? htmlspecialchars($nombre) : '' ?>">
                        </div>

                        <div class="mb-4">
                            <label for="email" class="form-label">Correo Electrónico</label>
                            <input type="email" class="form-control" name="email" id="email" 
                                   placeholder="juan@empresa.com" value="<?= isset($email) && !$mensaje ? htmlspecialchars($email) : '' ?>">
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="usuarios.php" class="btn btn-outline-secondary me-md-2">Cancelar</a>
                            <button type="submit" class="btn btn-success px-4">Crear Usuario</button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../menu/_footer.php'; ?>
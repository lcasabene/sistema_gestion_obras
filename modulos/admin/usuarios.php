<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php'; // Asumimos que conexion.php está en la raíz
require_login();

// Seguridad: Solo ADMIN puede ver esto
if (!is_admin()) {
    die("Acceso denegado. Se requieren permisos de Administrador.");
}

include __DIR__ . '/../../public/_header.php';

// Consulta: Traemos usuarios + su rol (haciendo JOIN con usuarios_roles y roles)
// Según tus imágenes: usuarios.id se une con usuarios_roles.usuario_id
$sql = "SELECT u.id, u.usuario, u.nombre, u.email, u.activo, r.nombre as rol_nombre 
        FROM usuarios u 
        LEFT JOIN usuario_roles ur ON u.id = ur.usuario_id 
        LEFT JOIN roles r ON ur.rol_id = r.id 
        ORDER BY u.id ASC";

try {
    $stmt = $pdo->query($sql);
    $usuarios = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error de consulta: " . $e->getMessage());
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0"><i class="bi bi-people-fill"></i> Administración de Usuarios</h3>
        <p class="text-muted small mb-0">Gestión de accesos y roles del sistema</p>
    </div>
    <div>
        <a class="btn btn-secondary me-2" href="../../public/menu.php">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <a href="permisos_roles.php" class="btn btn-outline-danger me-2">
            <i class="bi bi-shield-lock"></i> Permisos por Módulo
        </a>
        <a href="crear_usuario.php" class="btn btn-success">
            <i class="bi bi-person-plus-fill"></i> Nuevo Usuario
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Usuario</th>
                        <th>Nombre Completo</th>
                        <th>Rol Asignado</th>
                        <th>Estado</th>
                        <th class="text-end pe-4">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($usuarios) > 0): ?>
                        <?php foreach ($usuarios as $user): ?>
                        <tr>
                            <td class="ps-4 text-muted small">#<?= $user['id'] ?></td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($user['usuario']) ?></td>
                            <td><?= htmlspecialchars($user['nombre']) ?></td>
                            <td>
                                <?php 
                                $rol = $user['rol_nombre'] ?? 'SIN ROL';
                                $badgeClass = match(strtolower($rol)) {
                                    'admin'    => 'bg-dark',
                                    'editor'   => 'bg-primary',
                                    'consulta' => 'bg-secondary',
                                    default    => 'bg-light text-dark border'
                                };
                                ?>
                                <span class="badge rounded-pill <?= $badgeClass ?>">
                                    <?= htmlspecialchars($rol) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['activo']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="editar_usuario.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar usuario">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No se encontraron usuarios.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
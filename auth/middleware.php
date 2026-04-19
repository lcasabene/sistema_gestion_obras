<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/session_config.php';

// Iniciar sesión de forma segura
if (!secure_session_start()) {
    header("Location: " . BASE_URL . "auth/login.php?expired=1");
    exit;
}

function require_login(): void {
    if (!is_session_valid()) {
        secure_session_destroy();
        header("Location: " . BASE_URL . "auth/login.php?expired=1");
        exit;
    }
}

function current_user_role_names(): array {
    return $_SESSION['user_roles'] ?? [];
}

/** Comprueba si el usuario activo tiene rol Admin (insensible a mayúsculas) */
function is_admin(): bool {
    foreach (current_user_role_names() as $r) {
        if (strcasecmp($r, 'admin') === 0) return true;
    }
    return false;
}

function require_role(array $allowed_roles): void {
    require_login();
    $roles = current_user_role_names();
    foreach ($allowed_roles as $ar) {
        if (in_array($ar, $roles, true)) return;
    }
    http_response_code(403);
    echo "Acceso denegado.";
    exit;
}

/**
 * Devuelve el nivel del rol del usuario actual (1=Consulta, 2=Editor, 3=Admin).
 * Admin por nombre siempre retorna 3 sin consultar DB.
 */
function current_user_nivel(): int {
    if (is_admin()) return 3;

    global $pdo;
    if (!isset($pdo)) return 1;

    $roles = current_user_role_names();
    if (empty($roles)) return 1;

    try {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("SELECT MAX(COALESCE(nivel,1)) FROM roles WHERE nombre IN ($placeholders)");
        $stmt->execute($roles);
        return (int)($stmt->fetchColumn() ?: 1);
    } catch (Exception $e) {
        return 1;
    }
}

/** Editor o Admin: puede crear y editar registros */
function can_edit(): bool {
    return current_user_nivel() >= 2;
}

/** Solo Admin: puede eliminar registros */
function can_delete(): bool {
    return current_user_nivel() >= 3;
}

function require_can_edit(): void {
    if (!can_edit()) { http_response_code(403); echo "Acceso denegado."; exit; }
}

function require_can_delete(): void {
    if (!can_delete()) { http_response_code(403); echo "Acceso denegado."; exit; }
}

/**
 * Verifica si el usuario actual puede acceder a un módulo.
 * Admin siempre tiene acceso. Para otros roles consulta rol_modulos.
 */
function can_access_module(string $modulo_clave): bool {
    if (is_admin()) return true;

    $roles = current_user_role_names();
    if (empty($roles)) return false;

    global $pdo;
    if (!isset($pdo)) return false;

    try {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM rol_modulos rm
            JOIN roles r ON r.id = rm.rol_id
            WHERE r.nombre IN ($placeholders)
              AND rm.modulo_clave = ?
        ");
        $stmt->execute(array_merge($roles, [$modulo_clave]));
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return true; // Si la tabla no existe aún, no bloquear
    }
}

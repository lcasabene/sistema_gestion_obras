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

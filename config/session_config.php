<?php
/**
 * Configuración segura de sesiones para hosting compartido (Byhost)
 * Este archivo debe ser incluido antes de cualquier session_start()
 */

// Configurar parámetros de sesión seguros para hosting compartido
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

// Para hosting compartido, no usar cookies seguras (HTTPS) unless disponible
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
    $secure_cookie = true;
} else {
    ini_set('session.cookie_secure', 0);
    $secure_cookie = false;
}

// Tiempo de vida de la sesión (en segundos) - reducido para hosting compartido
$session_lifetime = 6 * 60 * 60; // 6 horas (reducido de 8 para hosting)
ini_set('session.gc_maxlifetime', $session_lifetime);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100); // Limpieza más frecuente en hosting compartido

// Configurar el tiempo de expiración de la cookie para hosting compartido
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/sistema_gestion_obras/', // Ruta específita para Byhost
    'domain' => '',
    'secure' => $secure_cookie,
    'httponly' => true,
    'samesite' => 'Lax'
]);

/**
 * Inicia sesión con configuración segura para hosting compartido
 */
function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        
        // Regenerar ID de sesión periódicamente para prevenir fixation
        // Frecuencia reducida para hosting compartido
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 2400) { // Cada 40 minutos (reducido)
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
        
        // Verificar tiempo de actividad
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_lifetime)) {
            // Sesión expirada
            session_unset();
            session_destroy();
            return false;
        }
        $_SESSION['last_activity'] = time();
    }
    return true;
}

/**
 * Verifica si la sesión está activa y válida
 */
function is_session_valid() {
    return isset($_SESSION['user_id']) && 
           isset($_SESSION['last_activity']) && 
           (time() - $_SESSION['last_activity'] < $session_lifetime);
}

/**
 * Destruye sesión de forma segura
 */
function secure_session_destroy() {
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

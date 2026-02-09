<?php
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Iniciar sesión de forma segura
secure_session_start();

$usuario = trim($_POST['usuario'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($usuario === '' || $password === '') {
    header("Location: login.php?err=1");
    exit;
}

// Buscar usuario
$stmt = $pdo->prepare("SELECT id, usuario, nombre, password_hash, activo FROM usuarios WHERE usuario = ? LIMIT 1");
$stmt->execute([$usuario]);
$u = $stmt->fetch();

if (!$u || (int)$u['activo'] !== 1 || !password_verify($password, $u['password_hash'])) {
    header("Location: login.php?err=1");
    exit;
}

// Regenerar ID de sesión para prevenir fixation
session_regenerate_id(true);

// Roles del usuario
$stmt = $pdo->prepare("
    SELECT r.nombre
    FROM usuario_roles ur
    INNER JOIN roles r ON r.id = ur.rol_id
    WHERE ur.usuario_id = ?
");
$stmt->execute([$u['id']]);
$roles = array_map(fn($row) => $row['nombre'], $stmt->fetchAll());

// Establecer variables de sesión
$_SESSION['user_id'] = (int)$u['id'];
$_SESSION['user_usuario'] = $u['usuario'];
$_SESSION['user_nombre'] = $u['nombre'];
$_SESSION['user_roles'] = $roles;
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time();
$_SESSION['last_regeneration'] = time();

// Auditoría login
$stmt = $pdo->prepare("INSERT INTO auditoria_log (usuario_id, entidad, entidad_id, accion, detalle) VALUES (?, 'usuarios', ?, 'LOGIN', ?)");
$stmt->execute([(int)$u['id'], (int)$u['id'], 'Login exitoso']);

// Actualizar último login
$stmt = $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?");
$stmt->execute([(int)$u['id']]);

header("Location: " . (defined('BASE_URL') ? BASE_URL : '') . "public/index.php");
exit;

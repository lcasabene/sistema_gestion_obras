<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();

require_once __DIR__ . '/../../config/database.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header("Location: obras_listado.php");
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE obras SET activo=0 WHERE id=?");
    $stmt->execute([$id]);

    $stmt = $pdo->prepare("INSERT INTO auditoria_log (usuario_id, entidad, entidad_id, accion, detalle) VALUES (?, 'obras', ?, 'DELETE', ?)");
    $stmt->execute([$_SESSION['user_id'] ?? null, $id, "Baja lógica de obra"]);
} catch (Exception $e) {}

header("Location: obras_listado.php");
exit;

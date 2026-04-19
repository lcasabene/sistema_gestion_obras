<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_can_delete();

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $pdo->prepare("UPDATE programas SET activo=0 WHERE id=?")->execute([$id]);
}
header("Location: index.php?msg=eliminado");
exit;

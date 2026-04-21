<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
require_can_delete();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: bancos_listado.php"); exit; }

// Verificar que no tenga programas asociados
$s = $pdo->prepare("SELECT COUNT(*) FROM programas WHERE organismo_id=? AND activo=1");
$s->execute([$id]);
if ((int)$s->fetchColumn() > 0) {
    header("Location: bancos_listado.php?msg=con_programas");
    exit;
}

$pdo->prepare("DELETE FROM organismos_financiadores WHERE id=?")->execute([$id]);
header("Location: bancos_listado.php?msg=eliminado");
exit;

<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
require_can_delete();

$id          = (int)($_GET['id'] ?? 0);
$programa_id = (int)($_GET['programa_id'] ?? 0);
if ($id) {
    // Verificar si tiene saldos asociados
    $st = $pdo->prepare("SELECT COUNT(*) FROM programa_saldos WHERE cuenta_id=?");
    $st->execute([$id]);
    if ($st->fetchColumn() > 0) {
        header("Location: programa_ver.php?id=$programa_id#tabCuentas&err=tiene_saldos");
        exit;
    }
    $pdo->prepare("DELETE FROM programa_cuentas WHERE id=?")->execute([$id]);
}
header("Location: programa_ver.php?id=$programa_id#tabCuentas");
exit;

<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
require_can_delete();

$id          = (int)($_GET['id'] ?? 0);
$programa_id = (int)($_GET['programa_id'] ?? 0);
if ($id) {
    $pdo->prepare("DELETE FROM programa_saldos WHERE id=?")->execute([$id]);
}
header("Location: programa_ver.php?id=$programa_id#tabSaldos");
exit;

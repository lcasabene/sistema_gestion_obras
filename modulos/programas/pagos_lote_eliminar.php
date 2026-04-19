<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
require_can_delete();

$lote_id     = $_GET['lote_id'] ?? '';
$programa_id = (int)($_GET['programa_id'] ?? 0);

if ($lote_id && $programa_id) {
    $pdo->prepare("DELETE FROM programa_pagos_importados WHERE lote_id=? AND programa_id=?")
        ->execute([$lote_id, $programa_id]);
}

header("Location: programa_ver.php?id=$programa_id#tabPagos");
exit;

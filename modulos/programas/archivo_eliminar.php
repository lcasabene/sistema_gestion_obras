<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
require_can_delete();

$id   = (int)($_GET['id'] ?? 0);
$back = $_GET['back'] ?? 'index.php';

if ($id) {
    $stmt = $pdo->prepare("SELECT nombre_guardado FROM programa_archivos WHERE id=?");
    $stmt->execute([$id]);
    $arch = $stmt->fetch();
    if ($arch) {
        $ruta = __DIR__ . '/../../uploads/programas/' . $arch['nombre_guardado'];
        if (file_exists($ruta)) unlink($ruta);
        $pdo->prepare("DELETE FROM programa_archivos WHERE id=?")->execute([$id]);
    }
}
header("Location: " . $back);
exit;

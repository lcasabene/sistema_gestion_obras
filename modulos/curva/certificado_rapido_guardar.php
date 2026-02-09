<?php
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}

$basePath = __DIR__ . '/../../';
require_once $basePath . 'config/database.php';

$obra_id = (int)($_POST['obra_id'] ?? 0);
$nro = (int)($_POST['nro'] ?? 0);
$periodo = trim($_POST['periodo'] ?? '');
$fecha_cert = $_POST['fecha_cert'] ?? null;
$avance_fisico_periodo = (float)($_POST['avance_fisico_periodo'] ?? 0);
$avance_fisico_acum = (float)($_POST['avance_fisico_acum'] ?? 0);

$monto_certificado = (float)($_POST['monto_certificado'] ?? 0);
$anticipo_desc = (float)($_POST['anticipo_desc'] ?? 0);
$fondo_reparo = (float)($_POST['fondo_reparo'] ?? 0);
$multas = (float)($_POST['multas'] ?? 0);
$otros_desc = (float)($_POST['otros_desc'] ?? 0);
$importe_a_pagar = (float)($_POST['importe_a_pagar'] ?? 0);

$estado = $_POST['estado'] ?? 'APROBADO';
$observaciones = $_POST['observaciones'] ?? '';

if ($obra_id <= 0 || $nro <= 0 || $periodo === '' || !$fecha_cert) {
    http_response_code(400);
    echo "Parámetros inválidos.";
    exit;
}

try {
    $st = $pdo->prepare("
        INSERT INTO certificados
        (obra_id, nro, periodo, fecha_cert,
         avance_fisico_periodo, avance_fisico_acum,
         monto_certificado, anticipo_desc, fondo_reparo, multas, otros_desc,
         importe_a_pagar, factura_id, estado, observaciones, activo)
        VALUES
        (?,?,?,?, ?,?,
         ?,?,?,?,?, ?,
         ?, NULL, ?, ?, 1)
    ");
    $st->execute([
        $obra_id, $nro, $periodo, $fecha_cert,
        $avance_fisico_periodo, $avance_fisico_acum,
        $monto_certificado, $anticipo_desc, $fondo_reparo, $multas, $otros_desc,
        $importe_a_pagar, $estado, $observaciones
    ]);

    header("Location: ../curva/curva_view.php?obra_id=" . $obra_id);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo "Error: " . htmlspecialchars($e->getMessage());
    exit;
}

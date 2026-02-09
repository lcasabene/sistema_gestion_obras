<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

function parseMonto($v) {
    if ($v === null || $v === '') return 0;
    if (is_float($v) || is_int($v)) return $v;

    $s = trim((string)$v);
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    return (float)$s;
}

try {

    $pdo->beginTransaction();

    $obra_id            = (int)$_POST['obra_id'];
    $version_prev_id   = (int)$_POST['version_prev_id'];
    $periodo           = $_POST['periodo'];
    $tipo              = $_POST['tipo'];
    $cert_id           = (int)$_POST['cert_id'];
    $curva_item_id     = (int)$_POST['curva_item_id'];
    $nro_certificado   = (int)$_POST['nro_certificado'];
    $avance_fisico     = (float)$_POST['avance_fisico'] / 100;  // 👈 CLAVE
    $fri               = $_POST['fri'] !== '' ? (float)$_POST['fri'] : null;

    $monto_bruto       = parseMonto($_POST['monto_bruto']);
    $fondo_reparo_m    = parseMonto($_POST['fondo_reparo_monto']);
    $anticipo_desc     = parseMonto($_POST['anticipo_descuento']);
    $multas_monto      = parseMonto($_POST['multas_monto']);

    // 1. Aplicar avance físico
    $monto_certificado = $monto_bruto * $avance_fisico;

    // 2. Descuentos
    $total_descuentos = $fondo_reparo_m + $anticipo_desc + $multas_monto;

    // 3. Neto a pagar
    $monto_neto_pagar = $monto_certificado - $total_descuentos;
    if ($monto_neto_pagar < 0) $monto_neto_pagar = 0;

    $stmt = $pdo->prepare("
        INSERT INTO certificados (
            obra_id, version_prev_id, periodo, tipo, cert_id, curva_item_id, nro_certificado,
            avance_fisico_mensual, fri,
            monto_bruto, fondo_reparo_monto, anticipo_descuento, multas_monto,
            monto_neto_pagar, estado, fecha_creacion
        ) VALUES (
            :obra_id, :version_prev_id, :periodo, :tipo, :cert_id, :curva_item_id, :nro_certificado,
            :avance_fisico, :fri,
            :monto_bruto, :fondo_reparo_m, :anticipo_desc, :multas_monto,
            :monto_neto_pagar, 'BORRADOR', NOW()
        )
    ");

    $stmt->execute([
        ':obra_id' => $obra_id,
        ':version_prev_id' => $version_prev_id,
        ':periodo' => $periodo,
        ':tipo' => $tipo,
        ':cert_id' => $cert_id,
        ':curva_item_id' => $curva_item_id,
        ':nro_certificado' => $nro_certificado,
        ':avance_fisico' => $avance_fisico * 100, // se guarda como %
        ':fri' => $fri,
        ':monto_bruto' => $monto_bruto,
        ':fondo_reparo_m' => $fondo_reparo_m,
        ':anticipo_desc' => $anticipo_desc,
        ':multas_monto' => $multas_monto,
        ':monto_neto_pagar' => $monto_neto_pagar
    ]);

    $pdo->commit();

    header('Location: certificados_listado.php?msg=ok');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: certificados_listado.php?err=' . urlencode($e->getMessage()));
    exit;
}

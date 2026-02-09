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
$curva_version_id = (int)($_POST['curva_version_id'] ?? 0);
$pct = $_POST['pct'] ?? [];

if ($obra_id <= 0 || $curva_version_id <= 0) { http_response_code(400); echo "Parámetros inválidos."; exit; }

try {
    $pdo->beginTransaction();

    // traer detalle por periodo (bruto/anticipo) para recalcular montos por fuente
    $st = $pdo->prepare("
      SELECT periodo, monto_bruto_plan, anticipo_pago_plan, anticipo_recupero_plan
      FROM curva_detalle
      WHERE curva_version_id=?
      ORDER BY periodo
    ");
    $st->execute([$curva_version_id]);
    $det = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$det) throw new Exception("Curva sin detalle.");

    $fuentes = $pdo->query("SELECT id FROM fuentes_financiamiento WHERE activo=1")->fetchAll(PDO::FETCH_COLUMN);
    if (!$fuentes) throw new Exception("No hay FUFI activas.");

    $up = $pdo->prepare("
      INSERT INTO curva_detalle_fuente
      (curva_version_id, periodo, fuente_id, porcentaje_fuente,
       monto_bruto_plan, anticipo_pago_plan, anticipo_recupero_plan, monto_neto_plan)
      VALUES (?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
       porcentaje_fuente=VALUES(porcentaje_fuente),
       monto_bruto_plan=VALUES(monto_bruto_plan),
       anticipo_pago_plan=VALUES(anticipo_pago_plan),
       anticipo_recupero_plan=VALUES(anticipo_recupero_plan),
       monto_neto_plan=VALUES(monto_neto_plan)
    ");

    foreach ($det as $d) {
        $per = $d['periodo'];
        $brutoMes = (float)$d['monto_bruto_plan'];
        $aPagoMes = (float)$d['anticipo_pago_plan'];
        $aRecMes  = (float)$d['anticipo_recupero_plan'];

        // leer porcentajes editados
        $rowPct = $pct[$per] ?? [];
        $sumPct = 0.0;
        foreach ($fuentes as $fid) $sumPct += (float)($rowPct[$fid] ?? 0);

        if ($sumPct <= 0) throw new Exception("Período $per: la suma de porcentajes no puede ser 0.");
        // Normalizar a 100
        foreach ($fuentes as $fid) {
            $p = (float)($rowPct[$fid] ?? 0);
            $pNorm = ($p / $sumPct) * 100.0;

            $brutoF = round($brutoMes * ($pNorm/100.0), 2);

            // anticipo_pago_plan y anticipo_recupero_plan por fuente:
            // - por defecto, repartimos ambos por pari passu del período.
            //   (si querés “anticipo pago 100% a una fuente”, eso se mantiene en la generación inicial.
            //    pero al editar por período, vos podés forzar 100% poniendo 100 a esa fuente y 0 al resto, para ese período.)
            $pagoF = ($aPagoMes > 0) ? round($aPagoMes * ($pNorm/100.0), 2) : 0.0;
            $recF  = ($aRecMes > 0)  ? round($aRecMes * ($pNorm/100.0), 2)  : 0.0;

            $netoF = round($brutoF - $recF + $pagoF, 2);

            $up->execute([$curva_version_id, $per, (int)$fid, round($pNorm,3), $brutoF, $pagoF, $recF, $netoF]);
        }
    }

    $pdo->commit();
    header("Location: curva_view.php?obra_id=".$obra_id);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Error: " . htmlspecialchars($e->getMessage());
    exit;
}

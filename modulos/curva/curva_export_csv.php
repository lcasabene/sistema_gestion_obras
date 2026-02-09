<?php
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}

$basePath = __DIR__ . '/../../';
require_once $basePath . 'config/database.php';

$obra_id = (int)($_GET['obra_id'] ?? 0);
if ($obra_id <= 0) {
    http_response_code(400);
    echo "obra_id inválido";
    exit;
}

try {
    // Obra
    $st = $pdo->prepare("SELECT id, denominacion FROM obras WHERE id=? LIMIT 1");
    $st->execute([$obra_id]);
    $obra = $st->fetch(PDO::FETCH_ASSOC);
    if (!$obra) throw new Exception("Obra no encontrada.");

    // Curva vigente
    $st = $pdo->prepare("SELECT id, nro_version FROM curva_version WHERE obra_id=? AND es_vigente=1 LIMIT 1");
    $st->execute([$obra_id]);
    $curva = $st->fetch(PDO::FETCH_ASSOC);
    if (!$curva) throw new Exception("No hay curva vigente.");

    // Detalle
    $st = $pdo->prepare("
        SELECT periodo, porcentaje_plan, monto_bruto_plan, anticipo_pago_plan, anticipo_recupero_plan, monto_neto_plan
        FROM curva_detalle
        WHERE curva_version_id=?
        ORDER BY periodo
    ");
    $st->execute([$curva['id']]);
    $detalles = $st->fetchAll(PDO::FETCH_ASSOC);

    // Real cert
    $realCertByPeriodo = [];
    $st = $pdo->prepare("
        SELECT c.periodo, COALESCE(SUM(c.importe_a_pagar),0) AS real_cert
        FROM certificados c
        WHERE c.obra_id = ? AND c.activo = 1
        GROUP BY c.periodo
    ");
    $st->execute([$obra_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $realCertByPeriodo[$r['periodo']] = (float)$r['real_cert'];
    }

    // Real pagado
    $realPagoByPeriodo = [];
    $st = $pdo->prepare("
        SELECT c.periodo, COALESCE(SUM(p.importe_pagado),0) AS real_pagado
        FROM pagos p
        INNER JOIN certificados c ON c.id = p.certificado_id
        WHERE c.obra_id = ?
          AND c.activo = 1
          AND (p.estado IS NULL OR p.estado <> 'ANULADO')
        GROUP BY c.periodo
    ");
    $st->execute([$obra_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $realPagoByPeriodo[$r['periodo']] = (float)$r['real_pagado'];
    }

    // Plan ajustado (mismo criterio que vista)
    $planTotalNeto = 0.0;
    foreach ($detalles as $d) $planTotalNeto += (float)$d['monto_neto_plan'];

    $realBaseByPeriodo = [];
    foreach ($detalles as $d) {
        $per = $d['periodo'];
        $rp = (float)($realPagoByPeriodo[$per] ?? 0);
        $rc = (float)($realCertByPeriodo[$per] ?? 0);
        $realBaseByPeriodo[$per] = ($rp > 0) ? $rp : $rc;
    }

    $ultimoPeriodoReal = null;
    foreach ($detalles as $d) {
        $per = $d['periodo'];
        if (($realBaseByPeriodo[$per] ?? 0) > 0) $ultimoPeriodoReal = $per;
    }

    $realAcumHasta = 0.0;
    if ($ultimoPeriodoReal !== null) {
        foreach ($detalles as $d) {
            $per = $d['periodo'];
            $realAcumHasta += (float)($realBaseByPeriodo[$per] ?? 0);
            if ($per === $ultimoPeriodoReal) break;
        }
    }

    $remanente = $planTotalNeto - $realAcumHasta;
    if ($remanente < 0) $remanente = 0;

    $pesosFuturos = [];
    $sumPesos = 0.0;
    $yaEsFuturo = ($ultimoPeriodoReal === null);

    foreach ($detalles as $d) {
        $per = $d['periodo'];
        if ($ultimoPeriodoReal !== null) {
            if ($per === $ultimoPeriodoReal) { $yaEsFuturo = true; continue; }
            if (!$yaEsFuturo) continue;
        }
        $peso = (float)$d['monto_neto_plan'];
        $pesosFuturos[$per] = $peso;
        $sumPesos += $peso;
    }

    $planAjustado = [];
    foreach ($detalles as $d) {
        $per = $d['periodo'];

        if (($realBaseByPeriodo[$per] ?? 0) > 0) {
            $planAjustado[$per] = round((float)$realBaseByPeriodo[$per], 2);
            continue;
        }

        if ($ultimoPeriodoReal !== null && isset($pesosFuturos[$per]) && $sumPesos > 0) {
            $planAjustado[$per] = round($remanente * ($pesosFuturos[$per] / $sumPesos), 2);
        } else {
            $planAjustado[$per] = round((float)$d['monto_neto_plan'], 2);
        }
    }

    // Output CSV
    $filename = "curva_obra_{$obra_id}_v{$curva['nro_version']}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    // BOM UTF-8 para Excel
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // separador ; (Excel AR)
    $sep = ';';

    // cabecera
    $headers = [
        'Periodo',
        '%Mes',
        'BrutoPlan',
        'AnticipoPago',
        'AnticipoRecupero',
        'NetoPlan',
        'RealCert_aPagar',
        'RealPagado',
        'DesvioPagadoMenosPlan',
        'PlanAjustado'
    ];
    fputs($out, implode($sep, $headers) . "\n");

    foreach ($detalles as $d) {
        $per = $d['periodo'];
        $neto = (float)$d['monto_neto_plan'];

        $realCert = (float)($realCertByPeriodo[$per] ?? 0);
        $realPago = (float)($realPagoByPeriodo[$per] ?? 0);
        $desvio = ($realPago > 0) ? ($realPago - $neto) : 0;

        $row = [
            $per,
            number_format((float)$d['porcentaje_plan'], 2, ',', '.'),
            number_format((float)$d['monto_bruto_plan'], 2, ',', '.'),
            number_format((float)$d['anticipo_pago_plan'], 2, ',', '.'),
            number_format((float)$d['anticipo_recupero_plan'], 2, ',', '.'),
            number_format($neto, 2, ',', '.'),
            number_format($realCert, 2, ',', '.'),
            number_format($realPago, 2, ',', '.'),
            number_format($desvio, 2, ',', '.'),
            number_format((float)($planAjustado[$per] ?? $neto), 2, ',', '.')
        ];

        fputs($out, implode($sep, $row) . "\n");
    }

    fclose($out);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo "Error exportando: " . htmlspecialchars($e->getMessage());
    exit;
}

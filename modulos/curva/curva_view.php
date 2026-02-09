<?php
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}

$basePath = __DIR__ . '/../../';
require_once $basePath . 'config/database.php';

if (file_exists($basePath . 'public/_header.php')) {
    require_once $basePath . 'public/_header.php';
}

$obra_id = (int)($_GET['obra_id'] ?? 0);
if ($obra_id <= 0) {
    echo '<div class="container my-4"><div class="alert alert-warning">Obra inválida.</div></div>';
    if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';
    exit;
}

try {
    // Obra
    $st = $pdo->prepare("SELECT id, denominacion, monto_actualizado FROM obras WHERE id=? LIMIT 1");
    $st->execute([$obra_id]);
    $obra = $st->fetch(PDO::FETCH_ASSOC);
    if (!$obra) throw new Exception("No se encontró la obra.");

    // Curva vigente
    $st = $pdo->prepare("
        SELECT id, nro_version, modo, fecha_desde, fecha_hasta
        FROM curva_version
        WHERE obra_id = ? AND es_vigente = 1
        LIMIT 1
    ");
    $st->execute([$obra_id]);
    $curva = $st->fetch(PDO::FETCH_ASSOC);
    if (!$curva) throw new Exception("La obra no tiene curva vigente.");

    // Detalle
    $st = $pdo->prepare("
        SELECT
            periodo,
            porcentaje_plan,
            monto_plan,
            monto_bruto_plan,
            anticipo_pago_plan,
            anticipo_recupero_plan,
            monto_neto_plan
        FROM curva_detalle
        WHERE curva_version_id = ?
        ORDER BY periodo
    ");
    $st->execute([$curva['id']]);
    $detalles = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$detalles) throw new Exception("La curva vigente no tiene detalle cargado.");

    // Real certificado (importe_a_pagar) por periodo
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

    // Real pagado por periodo (sum pagos agrupado por periodo del certificado)
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

} catch (Throwable $e) {
    echo '<div class="container my-4"><div class="alert alert-danger">Error: '.htmlspecialchars($e->getMessage()).'</div></div>';
    if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';
    exit;
}

// ==========================
// Plan ajustado respetando plazo
// ==========================
$planTotalNeto = 0.0;
foreach ($detalles as $d) $planTotalNeto += (float)$d['monto_neto_plan'];

// Real base (preferimos pagado; si no, certificado)
$realBaseByPeriodo = [];
foreach ($detalles as $d) {
    $per = $d['periodo'];
    $rp = (float)($realPagoByPeriodo[$per] ?? 0);
    $rc = (float)($realCertByPeriodo[$per] ?? 0);
    $realBaseByPeriodo[$per] = ($rp > 0) ? $rp : $rc;
}

// último periodo con real
$ultimoPeriodoReal = null;
foreach ($detalles as $d) {
    $per = $d['periodo'];
    if (($realBaseByPeriodo[$per] ?? 0) > 0) $ultimoPeriodoReal = $per;
}

// real acumulado hasta último real
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

// pesos futuros por plan original
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

// plan ajustado
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

// ==========================
// Render
// ==========================
$acum_pct = 0.0;
$acum_bruto = 0.0;
$acum_neto = 0.0;

$acum_real_cert = 0.0;
$acum_real_pago = 0.0;
$acum_plan_adj  = 0.0;
?>

<style>
/* Compacto y legible */
.table-curva th, .table-curva td { font-size: 12px; white-space: nowrap; }
.table-curva thead th { position: sticky; top: 0; z-index: 2; }
.curva-scroll { overflow-x: auto; }
.badge-soft { background: #f2f4f7; color: #333; border: 1px solid #e5e7eb; }
</style>

<div class="container my-4">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h3 class="mb-1">Curva de Avance – Plan vs Real</h3>
            <div class="text-muted">
                Obra #<?= (int)$obra['id'] ?> — <?= htmlspecialchars($obra['denominacion']) ?>
            </div>
            <div class="text-muted small">
                <span class="badge badge-soft">Versión <?= (int)$curva['nro_version'] ?></span>
                <span class="badge badge-soft">Modo <?= htmlspecialchars($curva['modo']) ?></span>
                <span class="badge badge-soft"><?= htmlspecialchars($curva['fecha_desde']) ?> a <?= htmlspecialchars($curva['fecha_hasta']) ?></span>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="curva_listado.php" class="btn btn-secondary">Volver</a>
            <a href="curva_fuentes_edit.php?obra_id=<?= $obra_id ?>" class="btn btn-outline-dark">
  Editar FUFI por período
</a>

            <a href="curva_form_generate.php?obra_id=<?= $obra_id ?>" class="btn btn-outline-primary">Nueva versión</a>

            <!-- Export CSV (abre en Excel) -->
            <a href="curva_export_csv.php?obra_id=<?= $obra_id ?>" class="btn btn-success">
                Exportar a Excel (CSV)
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body curva-scroll">
            <table class="table table-bordered table-sm align-middle mb-0 table-curva">
                <thead class="table-light">
                    <tr class="text-center">
                        <th>Período</th>
                        <th>% Mes</th>
                        <th>% Acum.</th>
                        <th>Bruto plan</th>
                        <th>Anticipo pago</th>
                        <th>Anticipo recupero</th>
                        <th>Neto plan</th>
                        <th>Neto acum.</th>
                        <th>Real cert. (a pagar)</th>
                        <th>Real pagado</th>
                        <th>Desvío (Pagado - Plan)</th>
                        <th>Plan ajustado</th>
                        <th>Ajustado acum.</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($detalles as $d):
                    $per = $d['periodo'];

                    $acum_pct   += (float)$d['porcentaje_plan'];
                    $acum_bruto += (float)$d['monto_bruto_plan'];
                    $acum_neto  += (float)$d['monto_neto_plan'];

                    $realCertMes = (float)($realCertByPeriodo[$per] ?? 0);
                    $realPagoMes = (float)($realPagoByPeriodo[$per] ?? 0);
                    $acum_real_cert += $realCertMes;
                    $acum_real_pago += $realPagoMes;

                    $planAdjMes = (float)($planAjustado[$per] ?? (float)$d['monto_neto_plan']);
                    $acum_plan_adj += $planAdjMes;

                    $desvioPago = $realPagoMes - (float)$d['monto_neto_plan'];
                ?>
                    <tr>
                        <td class="text-center"><?= htmlspecialchars($per) ?></td>
                        <td class="text-end"><?= number_format((float)$d['porcentaje_plan'], 2, ',', '.') ?> %</td>
                        <td class="text-end"><?= number_format($acum_pct, 2, ',', '.') ?> %</td>

                        <td class="text-end">$ <?= number_format((float)$d['monto_bruto_plan'], 2, ',', '.') ?></td>

                        <td class="text-end text-primary">
                            <?= ((float)$d['anticipo_pago_plan'] > 0) ? '$ '.number_format((float)$d['anticipo_pago_plan'], 2, ',', '.') : '—' ?>
                        </td>

                        <td class="text-end text-danger">
                            <?= ((float)$d['anticipo_recupero_plan'] > 0) ? '$ '.number_format((float)$d['anticipo_recupero_plan'], 2, ',', '.') : '—' ?>
                        </td>

                        <td class="text-end fw-semibold">$ <?= number_format((float)$d['monto_neto_plan'], 2, ',', '.') ?></td>
                        <td class="text-end fw-semibold">$ <?= number_format($acum_neto, 2, ',', '.') ?></td>

                        <td class="text-end"><?= ($realCertMes > 0) ? '$ '.number_format($realCertMes, 2, ',', '.') : '—' ?></td>
                        <td class="text-end"><?= ($realPagoMes > 0) ? '$ '.number_format($realPagoMes, 2, ',', '.') : '—' ?></td>

                        <td class="text-end <?= ($realPagoMes > 0 ? ($desvioPago >= 0 ? 'text-success' : 'text-danger') : '') ?>">
                            <?= ($realPagoMes > 0) ? '$ '.number_format($desvioPago, 2, ',', '.') : '—' ?>
                        </td>

                        <td class="text-end">$ <?= number_format($planAdjMes, 2, ',', '.') ?></td>
                        <td class="text-end fw-semibold">$ <?= number_format($acum_plan_adj, 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

                <tfoot class="table-light">
                    <tr class="fw-bold">
                        <td colspan="3" class="text-end">Totales</td>
                        <td class="text-end">$ <?= number_format($acum_bruto, 2, ',', '.') ?></td>
                        <td colspan="2"></td>
                        <td class="text-end">$ <?= number_format($acum_neto, 2, ',', '.') ?></td>
                        <td class="text-end">$ <?= number_format($acum_neto, 2, ',', '.') ?></td>
                        <td class="text-end">$ <?= number_format($acum_real_cert, 2, ',', '.') ?></td>
                        <td class="text-end">$ <?= number_format($acum_real_pago, 2, ',', '.') ?></td>
                        <td class="text-end"><?= ($acum_real_pago > 0) ? '$ '.number_format(($acum_real_pago - $acum_neto), 2, ',', '.') : '—' ?></td>
                        <td class="text-end">$ <?= number_format($acum_plan_adj, 2, ',', '.') ?></td>
                        <td class="text-end">$ <?= number_format($acum_plan_adj, 2, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>

            <div class="text-muted small mt-2">
                * Exportación: CSV abre en Excel y conserva todas las columnas.<br>
                * “Plan ajustado” redistribuye el remanente dentro de los meses restantes (sin extender el plazo).
            </div>

        </div>
    </div>

</div>

<?php
if (file_exists($basePath . 'public/_footer.php')) {
    require_once $basePath . 'public/_footer.php';
}
?>

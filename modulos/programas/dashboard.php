<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

$filtro_org = (int)($_GET['organismo_id'] ?? 0);

// ── Organismos para filtro ──────────────────────────────────────────────────
$organismos = $pdo->query("SELECT id, nombre_organismo FROM organismos_financiadores ORDER BY nombre_organismo")->fetchAll();

// ── Datos por programa ──────────────────────────────────────────────────────
$where = $filtro_org ? "WHERE p.organismo_id = $filtro_org AND p.activo=1" : "WHERE p.activo=1";

$rows = $pdo->query("
    SELECT
        p.id,
        p.codigo,
        p.nombre        AS prog_nombre,
        p.monto_total,
        p.moneda,
        p.fecha_inicio,
        p.fecha_fin,
        o.id            AS org_id,
        o.nombre_organismo,

        COALESCE(d.total_desemb, 0)  AS total_desemb,
        COALESCE(d.n_desemb, 0)      AS n_desemb,
        COALESCE(d.moneda_desemb,'') AS moneda_desemb,

        COALESCE(r.total_fuente, 0)  AS total_fuente,
        COALESCE(r.total_contra, 0)  AS total_contra,
        COALESCE(r.n_rend, 0)        AS n_rend,

        COALESCE(s.saldo_me, 0)      AS saldo_me,
        COALESCE(s.saldo_mn, 0)      AS saldo_mn,
        COALESCE(s.moneda_me,'USD')  AS moneda_me,
        COALESCE(s.fecha_saldo,'')   AS fecha_saldo,

        COALESCE(pi.n_pagos, 0)      AS n_pagos

    FROM programas p
    JOIN organismos_financiadores o ON o.id = p.organismo_id

    LEFT JOIN (
        SELECT programa_id,
               SUM(importe)  AS total_desemb,
               COUNT(*)      AS n_desemb,
               MAX(moneda)   AS moneda_desemb
        FROM programa_desembolsos
        GROUP BY programa_id
    ) d ON d.programa_id = p.id

    LEFT JOIN (
        SELECT programa_id,
               SUM(total_fuente_externa) AS total_fuente,
               SUM(total_contraparte)    AS total_contra,
               COUNT(*)                  AS n_rend
        FROM programa_rendiciones
        GROUP BY programa_id
    ) r ON r.programa_id = p.id

    LEFT JOIN (
        SELECT s1.programa_id,
               SUM(s1.saldo_moneda_extranjera) AS saldo_me,
               SUM(s1.saldo_moneda_nacional)   AS saldo_mn,
               MAX(s1.moneda_extranjera)        AS moneda_me,
               MAX(s1.fecha)                   AS fecha_saldo
        FROM programa_saldos s1
        WHERE s1.fecha = (
            SELECT MAX(s2.fecha) FROM programa_saldos s2
            WHERE s2.programa_id = s1.programa_id
        )
        GROUP BY s1.programa_id
    ) s ON s.programa_id = p.id

    LEFT JOIN (
        SELECT programa_id, COUNT(*) AS n_pagos
        FROM programa_pagos_importados
        GROUP BY programa_id
    ) pi ON pi.programa_id = p.id

    $where
    ORDER BY o.nombre_organismo, p.nombre
")->fetchAll();

// ── Agrupar por organismo ───────────────────────────────────────────────────
$byOrg = [];
foreach ($rows as $r) {
    $byOrg[$r['org_id']]['label']  = $r['nombre_organismo'];
    $byOrg[$r['org_id']]['progs'][] = $r;
}

// ── Totales globales ────────────────────────────────────────────────────────
$tot_desemb  = array_sum(array_column($rows, 'total_desemb'));
$tot_fuente  = array_sum(array_column($rows, 'total_fuente'));
$tot_contra  = array_sum(array_column($rows, 'total_contra'));
$tot_saldo_me = array_sum(array_column($rows, 'saldo_me'));
$tot_saldo_mn = array_sum(array_column($rows, 'saldo_mn'));
$tot_pagos   = array_sum(array_column($rows, 'n_pagos'));

function fmt($n) { return number_format((float)$n, 2, ',', '.'); }

include __DIR__ . '/../../public/_header.php';
?>

<style>
.kpi-card { border-left: 4px solid; }
.kpi-desemb  { border-color: #0d6efd; }
.kpi-rend    { border-color: #198754; }
.kpi-saldo   { border-color: #fd7e14; }
.kpi-saldopn { border-color: #20c997; }
.kpi-pagos   { border-color: #6f42c1; }
.org-header { background: #343a40; color:#fff; }
.prog-row:hover { background: #f8f9fa; }
</style>

<div class="container-fluid my-4" style="max-width:1400px">

    <!-- Cabecera + filtro -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <h4 class="fw-bold mb-0">
            <i class="bi bi-bar-chart-line me-2 text-secondary"></i>
            Dashboard Programas Financiados
        </h4>
        <form class="d-flex gap-2 align-items-center" method="GET">
            <select name="organismo_id" class="form-select form-select-sm" style="min-width:220px" onchange="this.form.submit()">
                <option value="">Todos los bancos financiadores</option>
                <?php foreach ($organismos as $o): ?>
                <option value="<?= $o['id'] ?>" <?= $o['id'] == $filtro_org ? 'selected':'' ?>>
                    <?= htmlspecialchars($o['nombre_organismo']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
            <a href="../../public/menu.php" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-house me-1"></i>Menú
            </a>
        </form>
    </div>

    <!-- KPIs globales -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl">
            <div class="card kpi-card kpi-desemb shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Total Desembolsado</div>
                    <div class="fs-4 fw-bold text-primary"><?= fmt($tot_desemb) ?></div>
                    <div class="text-muted small"><?= count($rows) ?> programas activos</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl">
            <div class="card kpi-card kpi-rend shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Total Rendido</div>
                    <div class="fs-4 fw-bold text-success"><?= fmt($tot_fuente) ?></div>
                    <div class="text-muted small">Contraparte: <?= fmt($tot_contra) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl">
            <div class="card kpi-card kpi-saldo shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small fw-semibold text-uppercase mb-1">
                        <i class="bi bi-currency-dollar me-1"></i>Saldo Bancario USD
                    </div>
                    <div class="fs-4 fw-bold" style="color:#fd7e14"><?= fmt($tot_saldo_me) ?></div>
                    <div class="text-muted small">Moneda extranjera (últimos registros)</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl">
            <div class="card kpi-card kpi-saldopn shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small fw-semibold text-uppercase mb-1">
                        <i class="bi bi-cash me-1"></i>Saldo Bancario Pesos
                    </div>
                    <div class="fs-4 fw-bold" style="color:#20c997"><?= fmt($tot_saldo_mn) ?></div>
                    <div class="text-muted small">Moneda nacional (últimos registros)</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl">
            <div class="card kpi-card kpi-pagos shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Pagos Importados</div>
                    <div class="fs-4 fw-bold" style="color:#6f42c1"><?= number_format($tot_pagos) ?></div>
                    <div class="text-muted small">registros totales</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla por organismo -->
    <?php foreach ($byOrg as $oid => $orgData): ?>
    <?php
        $progs = $orgData['progs'];
        $sub_desemb  = array_sum(array_column($progs, 'total_desemb'));
        $sub_fuente  = array_sum(array_column($progs, 'total_fuente'));
        $sub_contra  = array_sum(array_column($progs, 'total_contra'));
        $sub_saldo_me = array_sum(array_column($progs, 'saldo_me'));
        $sub_saldo_mn = array_sum(array_column($progs, 'saldo_mn'));
    ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header org-header d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold">
                <i class="bi bi-bank2 me-2"></i>Banco: <?= htmlspecialchars($orgData['label']) ?>
            </span>
            <span class="badge bg-secondary"><?= count($progs) ?> programa<?= count($progs)>1?'s':'' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.85rem">
                <thead class="table-light">
                    <tr>
                        <th>Programa</th>
                        <th class="text-center">Moneda</th>
                        <th class="text-end">Monto Total</th>
                        <th class="text-end text-primary">Desembolsado</th>
                        <th class="text-end" style="color:#198754">Rendido (Fuente)</th>
                        <th class="text-end text-muted">Rendido (Contra.)</th>
                        <th class="text-end" style="color:#fd7e14">Saldo USD</th>
                        <th class="text-end" style="color:#20c997">Saldo Pesos ($)</th>
                        <th class="text-center text-muted">Ult. Saldo</th>
                        <th class="text-center" style="color:#6f42c1">Pagos</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($progs as $p): ?>
                    <?php
                        $pct_rend = $p['total_desemb'] > 0
                            ? min(100, round($p['total_fuente'] / $p['total_desemb'] * 100, 1))
                            : 0;
                        $pct_color = $pct_rend >= 80 ? 'success' : ($pct_rend >= 50 ? 'warning' : 'danger');
                    ?>
                    <tr class="prog-row">
                        <td>
                            <a href="programa_ver.php?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($p['prog_nombre']) ?>
                            </a>
                            <div class="text-muted small"><?= htmlspecialchars($p['codigo']) ?></div>
                            <?php if ($p['total_desemb'] > 0): ?>
                            <div class="progress mt-1" style="height:4px;width:120px" title="<?= $pct_rend ?>% rendido">
                                <div class="progress-bar bg-<?= $pct_color ?>" style="width:<?= $pct_rend ?>%"></div>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge bg-secondary"><?= htmlspecialchars($p['moneda']) ?></span></td>
                        <td class="text-end font-monospace"><?= fmt($p['monto_total']) ?></td>
                        <td class="text-end font-monospace fw-bold text-primary"><?= fmt($p['total_desemb']) ?></td>
                        <td class="text-end font-monospace" style="color:#198754"><?= fmt($p['total_fuente']) ?></td>
                        <td class="text-end font-monospace text-muted"><?= fmt($p['total_contra']) ?></td>
                        <td class="text-end font-monospace fw-bold" style="color:#fd7e14">
                            <?= $p['saldo_me'] != 0 ? fmt($p['saldo_me']).'<span class="text-muted small ms-1">'.htmlspecialchars($p['moneda_me']).'</span>' : '<span class="text-muted">–</span>' ?>
                        </td>
                        <td class="text-end font-monospace fw-bold" style="color:#20c997">
                            <?= $p['saldo_mn'] != 0 ? '$&nbsp;'.fmt($p['saldo_mn']) : '<span class="text-muted">–</span>' ?>
                        </td>
                        <td class="text-center small text-muted"><?= $p['fecha_saldo'] ? date('d/m/Y', strtotime($p['fecha_saldo'])) : '–' ?></td>
                        <td class="text-center">
                            <?php if ($p['n_pagos'] > 0): ?>
                            <span class="badge" style="background:#6f42c1"><?= number_format($p['n_pagos']) ?></span>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="programa_ver.php?id=<?= $p['id'] ?>" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Ver programa">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="programa_ver.php?id=<?= $p['id'] ?>#tabDesemb" class="btn btn-outline-primary btn-sm py-0 px-1" title="Desembolsos">
                                <i class="bi bi-arrow-down-circle"></i>
                            </a>
                            <a href="programa_ver.php?id=<?= $p['id'] ?>#tabRend" class="btn btn-outline-success btn-sm py-0 px-1" title="Rendiciones">
                                <i class="bi bi-check-circle"></i>
                            </a>
                            <a href="programa_ver.php?id=<?= $p['id'] ?>#tabSaldos" class="btn btn-outline-warning btn-sm py-0 px-1" title="Saldos">
                                <i class="bi bi-bank"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-secondary fw-bold">
                    <tr>
                        <td colspan="2" class="text-end small text-muted">SUBTOTAL</td>
                        <td></td>
                        <td class="text-end font-monospace text-primary"><?= fmt($sub_desemb) ?></td>
                        <td class="text-end font-monospace" style="color:#198754"><?= fmt($sub_fuente) ?></td>
                        <td class="text-end font-monospace text-muted"><?= fmt($sub_contra) ?></td>
                        <td class="text-end font-monospace fw-bold" style="color:#fd7e14"><?= fmt($sub_saldo_me) ?></td>
                        <td class="text-end font-monospace fw-bold" style="color:#20c997">$ <?= fmt($sub_saldo_mn) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($byOrg)): ?>
    <div class="alert alert-info">No hay programas activos<?= $filtro_org ? ' para ese organismo' : '' ?>.</div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

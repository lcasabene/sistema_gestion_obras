<?php
// modulos/liquidaciones/liquidacion_ver.php
// Vista de detalle de liquidación confirmada (solo lectura)
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo "<div class='alert alert-danger m-4'>ID no especificado.</div>"; exit; }

$stmt = $pdo->prepare("
    SELECT l.*, 
           e.razon_social, e.cuit, e.condicion_iva, e.ganancias_condicion,
           o.denominacion AS obra_denominacion,
           u.nombre AS usuario_nombre,
           uc.nombre AS usuario_confirmacion_nombre,
           ua.nombre AS usuario_anulacion_nombre
    FROM liquidaciones l
    JOIN empresas e ON e.id = l.empresa_id
    JOIN obras o ON o.id = l.obra_id
    JOIN usuarios u ON u.id = l.usuario_id
    LEFT JOIN usuarios uc ON uc.id = l.usuario_confirmacion_id
    LEFT JOIN usuarios ua ON ua.id = l.usuario_anulacion_id
    WHERE l.id = ?
");
$stmt->execute([$id]);
$liq = $stmt->fetch();
if (!$liq) { echo "<div class='alert alert-danger m-4'>Liquidación no encontrada.</div>"; exit; }

$stmtItems = $pdo->prepare("SELECT * FROM liquidacion_items WHERE liquidacion_id = ? AND activo = 1 ORDER BY impuesto");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

$stmtLogs = $pdo->prepare("SELECT ll.*, u.nombre AS usuario_nombre FROM liquidacion_logs ll JOIN usuarios u ON u.id = ll.usuario_id WHERE ll.liquidacion_id = ? ORDER BY ll.created_at DESC");
$stmtLogs->execute([$id]);
$logs = $stmtLogs->fetchAll();

function fmt($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtFecha($f) { return $f ? date('d/m/Y H:i', strtotime($f)) : '-'; }

$badgeMap = [
    'BORRADOR' => 'bg-secondary', 'PRELIQUIDADO' => 'bg-warning text-dark',
    'CONFIRMADO' => 'bg-success', 'ANULADO' => 'bg-danger',
];
$impNombre = [
    'GANANCIAS' => 'Imp. Ganancias', 'IVA' => 'IVA', 'SUSS' => 'SUSS', 'IIBB' => 'IIBB',
];
?>

<div class="container-fluid px-4 my-4">
    <?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success alert-dismissible fade show py-2">
        <i class="bi bi-check-circle"></i> Liquidación confirmada exitosamente. <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="text-primary fw-bold mb-0">
                <i class="bi bi-receipt-cutoff"></i> Liquidación #<?= $id ?>
                <span class="badge <?= $badgeMap[$liq['estado']] ?? 'bg-secondary' ?> ms-2"><?= $liq['estado'] ?></span>
            </h3>
            <?php if ($liq['nro_certificado_retencion']): ?>
            <p class="text-muted small mb-0">Certificado: <strong class="text-danger"><?= htmlspecialchars($liq['nro_certificado_retencion']) ?></strong></p>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <?php if ($liq['estado'] === 'CONFIRMADO'): ?>
            <a href="certificado_retencion.php?id=<?= $id ?>" class="btn btn-success btn-sm" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Certificado</a>
            <a href="liquidacion_anular.php?id=<?= $id ?>" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> Anular</a>
            <?php endif; ?>
            <a href="liquidaciones_listado.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="row g-3">
        <!-- Datos principales -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-light fw-bold"><i class="bi bi-info-circle"></i> Datos del Comprobante</div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-6"><small class="text-muted">Empresa</small><div class="fw-bold"><?= htmlspecialchars($liq['razon_social']) ?></div><div class="small text-muted">CUIT: <?= $liq['cuit'] ?></div></div>
                        <div class="col-md-6"><small class="text-muted">Obra</small><div class="fw-bold"><?= htmlspecialchars($liq['obra_denominacion']) ?></div></div>
                        <div class="col-md-3"><small class="text-muted">Origen</small><div><span class="badge bg-info"><?= $liq['tipo_comprobante_origen'] ?></span></div></div>
                        <div class="col-md-3"><small class="text-muted">Tipo</small><div class="fw-bold"><?= htmlspecialchars($liq['comprobante_tipo']) ?></div></div>
                        <div class="col-md-3"><small class="text-muted">Número</small><div class="fw-bold"><?= htmlspecialchars($liq['comprobante_numero']) ?></div></div>
                        <div class="col-md-3"><small class="text-muted">Fecha Comprobante</small><div class="fw-bold"><?= date('d/m/Y', strtotime($liq['comprobante_fecha'])) ?></div></div>
                        <div class="col-md-3"><small class="text-muted">Importe Total</small><div class="fw-bold fs-5 text-primary">$ <?= fmt($liq['comprobante_importe_total']) ?></div></div>
                        <div class="col-md-3"><small class="text-muted">IVA</small><div>$ <?= fmt($liq['comprobante_iva']) ?></div></div>
                        <div class="col-md-3"><small class="text-muted">Neto</small><div>$ <?= fmt($liq['comprobante_importe_neto']) ?></div></div>
                        <div class="col-md-3"><small class="text-muted">Fecha de Pago</small><div class="fw-bold text-danger"><?= date('d/m/Y', strtotime($liq['fecha_pago'])) ?></div></div>
                    </div>
                </div>
            </div>

            <!-- Detalle retenciones -->
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-light fw-bold"><i class="bi bi-list-check"></i> Detalle de Retenciones</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Impuesto</th><th>Condición</th><th class="text-end">Base</th><th class="text-end">Mínimo</th><th class="text-end">Base Sujeta</th><th class="text-end">Alícuota</th><th class="text-end">Retención</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $it): ?>
                            <tr>
                                <td><span class="badge bg-primary"><?= $impNombre[$it['impuesto']] ?? $it['impuesto'] ?></span></td>
                                <td class="small"><?= $it['condicion_fiscal'] ?></td>
                                <td class="text-end">$ <?= fmt($it['base_calculo']) ?></td>
                                <td class="text-end">$ <?= fmt($it['minimo_no_sujeto']) ?></td>
                                <td class="text-end">$ <?= fmt($it['base_sujeta']) ?></td>
                                <td class="text-end"><?= number_format($it['alicuota_aplicada'], 2, ',', '.') ?>%</td>
                                <td class="text-end fw-bold text-danger">$ <?= fmt($it['importe_retencion']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Resumen formato SIGUE-UPEFE + Auditoría -->
        <div class="col-lg-5">
            <?php
            // Sumar retenciones por tipo desde items
            $retSums = ['SUSS' => 0, 'GANANCIAS' => 0, 'IIBB' => 0, 'IVA' => 0];
            foreach ($items as $it) { $retSums[$it['impuesto']] = ($retSums[$it['impuesto']] ?? 0) + (float)$it['importe_retencion']; }
            ?>
            <div class="card shadow-sm border-0 mb-3" style="font-size:0.85rem">
                <div class="card-header bg-dark text-white fw-bold text-center py-1">LIQUIDACIÓN #<?= $id ?></div>
                <div class="card-body p-2">
                    <?php if ($liq['expediente'] || $liq['op_sicopro']): ?>
                    <div class="small text-muted mb-1">
                        <?php if ($liq['expediente']): ?>Exp: <?= htmlspecialchars($liq['expediente']) ?> <?php endif; ?>
                        <?php if ($liq['op_sicopro']): ?>| O.P. SICOPRO: <?= htmlspecialchars($liq['op_sicopro']) ?><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($liq['obra_tipo'] ?? ''): ?>
                    <div class="small mb-1">Tipo obra: <strong><?= $liq['obra_tipo'] ?></strong></div>
                    <?php endif; ?>

                    <table class="table table-sm table-bordered mb-2">
                        <tr class="table-secondary"><td class="fw-bold">IMPORTE LIQUIDADO</td><td class="text-end fw-bold fs-6">$<?= fmt($liq['comprobante_importe_total']) ?></td></tr>
                        <tr><td class="text-muted small">Base imponible (Total - IVA)</td><td class="text-end">$<?= fmt($liq['base_imponible']) ?></td></tr>
                    </table>

                    <table class="table table-sm table-bordered mb-2">
                        <thead><tr class="table-warning text-center"><th colspan="3">RETENCIONES Y/O DEDUCCIONES</th></tr></thead>
                        <tbody>
                            <tr><td>Fondo de Reparo:</td><td class="text-end fw-bold">$<?= fmt($liq['fondo_reparo_monto'] ?? 0) ?></td><td class="small text-muted"><?= htmlspecialchars($liq['fondo_reparo_obs'] ?? '') ?></td></tr>
                            <tr><td>Retención SUSS:</td><td class="text-end fw-bold">$<?= fmt($retSums['SUSS']) ?></td><td class="small text-muted"><?= htmlspecialchars($liq['obs_suss'] ?? '') ?></td></tr>
                            <tr><td>Ret. Imp. Ganancias:</td><td class="text-end fw-bold">$<?= fmt($retSums['GANANCIAS']) ?></td><td class="small text-muted"><?= htmlspecialchars($liq['obs_ganancias'] ?? '') ?></td></tr>
                            <tr><td>Ret. IIBB:</td><td class="text-end fw-bold">$<?= fmt($retSums['IIBB']) ?></td><td class="small text-muted"><?= htmlspecialchars($liq['obs_iibb'] ?? '') ?></td></tr>
                            <?php if ($retSums['IVA'] > 0): ?>
                            <tr><td>Ret. IVA:</td><td class="text-end fw-bold">$<?= fmt($retSums['IVA']) ?></td><td></td></tr>
                            <?php endif; ?>
                            <tr><td>Ret. OTRAS:</td><td class="text-end fw-bold">$<?= fmt($liq['ret_otras_monto'] ?? 0) ?></td><td class="small text-muted"><?= htmlspecialchars($liq['ret_otras_obs'] ?? '') ?></td></tr>
                            <tr><td>Multas:</td><td class="text-end fw-bold">$<?= fmt($liq['multas_monto'] ?? 0) ?></td><td class="small text-muted"><?= htmlspecialchars($liq['multas_obs'] ?? '') ?></td></tr>
                        </tbody>
                    </table>

                    <table class="table table-sm table-bordered mb-2">
                        <tr class="table-danger"><td class="fw-bold">TOTAL RETENCIONES</td><td class="text-end fw-bold fs-6">$-<?= fmt($liq['total_retenciones']) ?></td></tr>
                    </table>

                    <?php if ($liq['observaciones_finales'] ?? ''): ?>
                    <div class="bg-light border rounded p-2 mb-2 small"><strong>OBSERVACIONES:</strong> <?= htmlspecialchars($liq['observaciones_finales']) ?></div>
                    <?php endif; ?>

                    <table class="table table-sm table-bordered mb-0">
                        <tr class="table-success"><td class="fw-bold fs-6">TOTAL NETO A PAGAR TESORERÍA</td><td class="text-end fw-bold fs-5 text-success">$<?= fmt($liq['neto_a_pagar']) ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if ($liq['estado'] === 'ANULADO'): ?>
            <div class="card shadow-sm border-danger mb-3">
                <div class="card-header bg-danger text-white fw-bold"><i class="bi bi-x-circle"></i> ANULADA</div>
                <div class="card-body">
                    <p class="mb-1"><strong>Motivo:</strong> <?= htmlspecialchars($liq['motivo_anulacion']) ?></p>
                    <p class="mb-0 small text-muted">Anulada el <?= fmtFecha($liq['fecha_anulacion']) ?> por <?= htmlspecialchars($liq['usuario_anulacion_nombre'] ?? '') ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Historial -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light fw-bold"><i class="bi bi-clock-history"></i> Historial</div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($logs as $log): ?>
                        <div class="list-group-item py-2">
                            <div class="d-flex justify-content-between">
                                <span class="badge bg-secondary"><?= $log['accion'] ?></span>
                                <small class="text-muted"><?= fmtFecha($log['created_at']) ?></small>
                            </div>
                            <?php if ($log['motivo']): ?><div class="small mt-1"><?= htmlspecialchars($log['motivo']) ?></div><?php endif; ?>
                            <div class="small text-muted">por <?= htmlspecialchars($log['usuario_nombre']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

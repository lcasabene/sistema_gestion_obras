<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Consulta usando la VISTA SQL que une las 5 tablas
// Agrupamos por certificado para que si tiene 2 OPs no salga duplicado (usamos GROUP_CONCAT)
$sql = "SELECT 
            c.cert_id, c.nro_certificado, c.periodo, c.obra, c.empresa, 
            c.monto_certificado, c.fecha_vencimiento,
            
            -- Agrupar OPs (si hay varias)
            GROUP_CONCAT(DISTINCT c.nro_op SEPARATOR ', ') as ops,
            GROUP_CONCAT(DISTINCT c.tgf_estado SEPARATOR ', ') as estados_tgf,
            MAX(c.tgf_fecha_envio) as fecha_envio_fondos,
            
            -- Liquidación
            MAX(c.nro_liquidacion) as nro_liquidacion,
            SUM(c.liq_monto_pagar) as monto_liquidado,
            
            -- Pago
            MAX(c.pago_fecha) as fecha_pago,
            MAX(c.pago_volante) as volante_pago
            
        FROM vista_trazabilidad_pagos c
        GROUP BY c.cert_id
        ORDER BY c.cert_id DESC";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid my-4">
    <h2 class="mb-4"><i class="bi bi-diagram-3"></i> Tablero de Trazabilidad</h2>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm" style="font-size: 0.9rem;">
                    <thead class="table-dark">
                        <tr>
                            <th>Certificado</th>
                            <th>Vencimiento</th>
                            <th>Monto Cert.</th>
                            <th>Facturación</th>
                            <th>Tesorería (OP)</th>
                            <th>Contaduría (Liq)</th>
                            <th>Banco (Pago)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($rows as $r): 
                            // Lógica de Semáforo Vencimiento
                            $hoy = date('Y-m-d');
                            $classVenc = 'text-muted';
                            $iconVenc = '';
                            if(!$r['fecha_pago']) {
                                if($r['fecha_vencimiento'] < $hoy) {
                                    $classVenc = 'text-danger fw-bold';
                                    $iconVenc = '<i class="bi bi-exclamation-circle-fill"></i>';
                                } elseif($r['fecha_vencimiento'] < date('Y-m-d', strtotime('+10 days'))) {
                                    $classVenc = 'text-warning fw-bold';
                                }
                            } else {
                                $classVenc = 'text-success text-decoration-line-through';
                            }

                            // Buscar facturas vinculadas (Consulta rápida al vuelo)
                            $stmtFac = $pdo->prepare("SELECT COUNT(*) FROM certificados_facturas WHERE certificado_id = ?");
                            $stmtFac->execute([$r['cert_id']]);
                            $cantFacturas = $stmtFac->fetchColumn();
                        ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-primary">N° <?= $r['nro_certificado'] ?> <span class="text-dark small">(<?= $r['periodo'] ?>)</span></div>
                                <div class="small text-muted text-truncate" style="max-width: 200px;" title="<?= $r['obra'] ?>">
                                    <?= $r['obra'] ?>
                                </div>
                                <div class="small text-secondary fst-italic"><?= substr($r['empresa'],0,20) ?></div>
                            </td>

                            <td class="<?= $classVenc ?>">
                                <?= $iconVenc ?> <?= $r['fecha_vencimiento'] ? date('d/m/Y', strtotime($r['fecha_vencimiento'])) : '-' ?>
                            </td>

                            <td class="fw-bold text-end">$ <?= number_format($r['monto_certificado'], 2, ',', '.') ?></td>

                            <td class="text-center">
                                <?php if($cantFacturas > 0): ?>
                                    <span class="badge bg-info text-dark" title="<?= $cantFacturas ?> facturas vinculadas"><i class="bi bi-receipt"></i> <?= $cantFacturas ?></span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Pendiente</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($r['ops']): ?>
                                    <div class="fw-bold text-primary">OP: <?= $r['ops'] ?></div>
                                    <?php if(strpos($r['estados_tgf'], 'TOTAL_ANTICIPADO') !== false): ?>
                                        <span class="badge bg-success">Fondos Enviados</span>
                                        <div class="small text-muted"><?= date('d/m/Y', strtotime($r['fecha_envio_fondos'])) ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Solicitado</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($r['nro_liquidacion']): ?>
                                    <span class="badge bg-secondary">Liq: <?= $r['nro_liquidacion'] ?></span>
                                    <div class="small text-muted">$ <?= number_format($r['monto_liquidado'], 2, ',', '.') ?></div>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($r['fecha_pago']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i> PAGADO</span>
                                    <div class="fw-bold small"><?= date('d/m/Y', strtotime($r['fecha_pago'])) ?></div>
                                    <div class="small text-muted">Vol: <?= $r['volante_pago'] ?></div>
                                <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-75">Impago</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
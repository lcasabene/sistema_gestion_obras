<?php
// modulos/certificados/trazabilidad.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// CONSULTA MAESTRA
// 1. GROUP_CONCAT(DISTINCT c.nro_op...) evita repetir OPs si el join trae multiples filas
// 2. Subconsulta para traer FACTURAS concatenadas
$sql = "SELECT 
            c.cert_id, 
            c.nro_certificado, 
            c.periodo, 
            c.obra, 
            c.empresa, 
            c.monto_certificado, 
            c.fecha_vencimiento,
            
            -- OPs y Estados
            GROUP_CONCAT(DISTINCT c.nro_op SEPARATOR ', ') as ops,
            GROUP_CONCAT(DISTINCT c.tgf_estado SEPARATOR ', ') as estados_tgf,
            MAX(c.tgf_fecha_envio) as fecha_envio_fondos,
            
            -- Liquidación
            MAX(c.nro_liquidacion) as nro_liquidacion,
            SUM(c.liq_monto_pagar) as monto_liquidado,
            
            -- Pago
            MAX(c.pago_fecha) as fecha_pago,
            MAX(c.pago_volante) as volante_pago,
            
            -- FACTURAS (Subconsulta para traer números)
            (SELECT GROUP_CONCAT(CONCAT(ca.punto_venta, '-', ca.numero) SEPARATOR ', ') 
             FROM certificados_facturas cf 
             JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id 
             WHERE cf.certificado_id = c.cert_id
            ) as facturas_numeros

        FROM vista_trazabilidad_pagos c
        GROUP BY c.cert_id
        ORDER BY c.cert_id DESC";

try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error SQL (verificar vista): " . $e->getMessage();
    $rows = [];
}

// Helper formato
function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
?>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="bi bi-diagram-3-fill text-primary"></i> Tablero de Trazabilidad</h2>
        <div>
            <a href="certificados_listado.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-striped mb-0" style="font-size: 0.9rem;">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th class="py-3 ps-3">Certificado / Obra</th>
                            <th class="py-3 text-center">Vencimiento</th>
                            <th class="py-3 text-end">Monto Cert.</th>
                            <th class="py-3">Facturas</th>
                            <th class="py-3">Tesorería (OP)</th>
                            <th class="py-3">Contaduría (Liq)</th>
                            <th class="py-3 pe-3">Banco (Pago)</th>
                            <th class="py-3 text-center"><i class="bi bi-whatsapp"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($rows) == 0): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No hay datos para mostrar</td></tr>
                        <?php endif; ?>

                        <?php foreach($rows as $r): 
                            // Semáforo Vencimiento
                            $hoy = date('Y-m-d');
                            $classVenc = 'text-muted';
                            $badgeVenc = '';
                            
                            if($r['fecha_pago']) {
                                $classVenc = 'text-success text-decoration-line-through opacity-50';
                                $estadoTexto = "PAGADO";
                            } else {
                                if($r['fecha_vencimiento']) {
                                    if($r['fecha_vencimiento'] < $hoy) {
                                        $classVenc = 'text-danger fw-bold';
                                        $badgeVenc = '<span class="badge bg-danger">VENCIDO</span>';
                                        $estadoTexto = "VENCIDO";
                                    } elseif($r['fecha_vencimiento'] < date('Y-m-d', strtotime('+15 days'))) {
                                        $classVenc = 'text-warning fw-bold';
                                        $badgeVenc = '<span class="badge bg-warning text-dark">PRÓXIMO</span>';
                                        $estadoTexto = "PRÓXIMO A VENCER";
                                    } else {
                                        $classVenc = 'text-success';
                                        $estadoTexto = "EN FECHA";
                                    }
                                } else {
                                    $estadoTexto = "PENDIENTE";
                                }
                            }
                            
                            // Preparar datos para el portapapeles (JS)
                            $dataCopy = [
                                "Certificado" => "N° " . $r['nro_certificado'] . " (" . $r['periodo'] . ")",
                                "Obra" => $r['obra'],
                                "Empresa" => $r['empresa'],
                                "Monto" => "$ " . fmtM($r['monto_certificado']),
                                "Estado" => $estadoTexto,
                                "Facturas" => $r['facturas_numeros'] ?: 'Sin cargar',
                                "OP" => $r['ops'] ?: 'Sin OP',
                                "Liq" => $r['nro_liquidacion'] ? "N° ".$r['nro_liquidacion'] : 'No',
                                "Pago" => $r['fecha_pago'] ? date('d/m/Y', strtotime($r['fecha_pago'])) : 'No'
                            ];
                            $jsonCopy = htmlspecialchars(json_encode($dataCopy), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td class="ps-3">
                                <div class="d-flex align-items-center">
                                    <div class="me-2">
                                        <a href="certificados_form.php?id=<?= $r['cert_id'] ?>" class="btn btn-sm btn-outline-primary fw-bold" title="Editar">
                                            N° <?= $r['nro_certificado'] ?>
                                        </a>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark text-truncate" style="max-width: 250px;" title="<?= $r['obra'] ?>">
                                            <?= $r['obra'] ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= mb_substr($r['empresa'], 0, 20) ?> | <strong><?= $r['periodo'] ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="text-center <?= $classVenc ?>">
                                <div><?= $r['fecha_vencimiento'] ? date('d/m/Y', strtotime($r['fecha_vencimiento'])) : '-' ?></div>
                                <?= $badgeVenc ?>
                            </td>

                            <td class="text-end fw-bold text-dark">
                                $ <?= fmtM($r['monto_certificado']) ?>
                            </td>

                            <td>
                                <?php if($r['facturas_numeros']): ?>
                                    <div class="small fw-bold text-dark"><i class="bi bi-receipt"></i> <?= $r['facturas_numeros'] ?></div>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($r['ops']): ?>
                                    <div class="fw-bold text-primary">OP: <?= $r['ops'] ?></div>
                                    <?php if(strpos($r['estados_tgf'], 'TOTAL_ANTICIPADO') !== false): ?>
                                        <span class="badge bg-success">Enviado</span>
                                        <span class="small text-muted"><?= $r['fecha_envio_fondos'] ? date('d/m', strtotime($r['fecha_envio_fondos'])) : '' ?></span>
                                    <?php elseif($r['estados_tgf'] == 'NO_APLICA'): ?>
                                        <span class="badge bg-secondary">Directo</span>
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
                                    <?php if($r['monto_liquidado'] > 0): ?>
                                        <div class="small text-muted mt-1">$ <?= fmtM($r['monto_liquidado']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="pe-3">
                                <?php if($r['fecha_pago']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i> PAGADO</span>
                                    <div class="small text-dark fw-bold"><?= date('d/m/Y', strtotime($r['fecha_pago'])) ?></div>
                                <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-50">Impago</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center">
                                <button class="btn btn-sm btn-light border shadow-sm" onclick='copiarDatos(<?= $jsonCopy ?>)' title="Copiar para WhatsApp">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function copiarDatos(data) {
    // Formato amigable para WhatsApp
    let texto = `*ESTADO CERTIFICADO* 📋\n\n`;
    texto += `*Obra:* ${data.Obra}\n`;
    texto += `*Certificado:* ${data.Certificado}\n`;
    texto += `*Monto:* ${data.Monto}\n`;
    texto += `*Factura:* ${data.Facturas}\n`;
    texto += `------------------\n`;
    texto += `*Estado:* ${data.Estado}\n`;
    texto += `*OP:* ${data.OP}\n`;
    texto += `*Liq:* ${data.Liq}\n`;
    texto += `*Pago:* ${data.Pago}`;

    navigator.clipboard.writeText(texto).then(() => {
        // Feedback visual simple (alert temporal o toast)
        let el = document.createElement('div');
        el.innerHTML = '<div style="position:fixed; top:20px; right:20px; z-index:9999; background:#198754; color:white; padding:10px 20px; border-radius:5px; box-shadow:0 2px 5px rgba(0,0,0,0.2);">¡Copiado!</div>';
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 2000);
    }).catch(err => {
        console.error('Error al copiar: ', err);
    });
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
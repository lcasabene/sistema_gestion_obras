<?php
// modulos/certificados/trazabilidad.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// CONSULTA MAESTRA (Basada en la vista optimizada)
// modulos/certificados/trazabilidad.php

// ... (tus requires e includes anteriores siguen igual)

// CONSULTA MAESTRA (VISTA INTEGRADA COMO SUBQUERY)
// Como no podemos crear VIEWs en el servidor, definimos la lógica aquí mismo.
$sql = "SELECT 
            main.cert_id, 
            main.nro_certificado, 
            main.periodo, 
            main.obra, 
            main.empresa, 
            main.monto_certificado, 
            main.fecha_vencimiento,
            
            -- Concatenamos OPs
            GROUP_CONCAT(DISTINCT main.nro_op SEPARATOR ', ') as ops,
            GROUP_CONCAT(DISTINCT main.tgf_estado SEPARATOR ', ') as estados_tgf,
            MAX(main.tgf_fecha_envio) as fecha_envio_fondos,
            
            -- Detalle de Pagos
            GROUP_CONCAT(main.liq_detalle_texto SEPARATOR '<hr class=\"my-1\">') as detalle_completo_pagos,
            SUM(main.liq_monto_pagar) as monto_liquidado,
            
            -- Estado General
            MAX(main.pago_fecha) as fecha_pago,
            
            -- Facturas (Subconsulta externa se mantiene igual)
            (SELECT GROUP_CONCAT(
                CONCAT(DATE_FORMAT(ca.fecha, '%d/%m/%y'), ' ', ca.punto_venta, '-', ca.numero) 
                SEPARATOR '<br>') 
             FROM certificados_facturas cf 
             JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id 
             WHERE cf.certificado_id = main.cert_id
            ) as facturas_detalle

        FROM (
            /* --- INICIO DE LA LÓGICA DE LA VISTA ORIGINAL --- */
            SELECT 
                `c`.`id` AS `cert_id`, 
                `c`.`nro_certificado` AS `nro_certificado`, 
                `c`.`periodo` AS `periodo`, 
                `c`.`fecha_vencimiento` AS `fecha_vencimiento`, 
                `c`.`monto_neto_pagar` AS `monto_certificado`, 
                `o`.`denominacion` AS `obra`, 
                `e`.`razon_social` AS `empresa`, 
                `cop`.`nro_op` AS `nro_op`, 
                `cop`.`ejercicio` AS `op_ejercicio`, 
                `cop`.`fuente_financiamiento` AS `op_fuente`, 
                CASE WHEN `cop`.`fuente_financiamiento` in ('1111','1114','1115') THEN `tgf`.`tipo_origen` ELSE 'NO_APLICA' END AS `tgf_estado`, 
                CASE WHEN `cop`.`fuente_financiamiento` in ('1111','1114','1115') THEN `tgf`.`f_anticipo` ELSE NULL END AS `tgf_fecha_envio`, 
                `liq_final`.`detalle_pagos` AS `liq_detalle_texto`, 
                `liq_final`.`total_monto` AS `liq_monto_pagar`, 
                `liq_final`.`ultima_fecha` AS `pago_fecha`, 
                CASE WHEN `liq_final`.`ultima_fecha` is not null THEN `liq_final`.`volantes` WHEN `pago_sicopro`.`movtrnu` is not null THEN concat('PA-',`pago_sicopro`.`movtrnu`) ELSE NULL END AS `pago_volante` 
            FROM `certificados` `c` 
            JOIN `obras` `o` ON `c`.`obra_id` = `o`.`id` 
            LEFT JOIN `empresas` `e` ON `c`.`empresa_id` = `e`.`id` 
            LEFT JOIN `certificados_ops` `cop` ON `c`.`id` = `cop`.`certificado_id` 
            LEFT JOIN `sicopro_anticipos_tgf` `tgf` ON `cop`.`nro_op` = `tgf`.`o_pago` AND `cop`.`ejercicio` = `tgf`.`ejer` 
            LEFT JOIN (
                SELECT 
                    `l`.`op_sicopro` AS `op_sicopro`,
                    `l`.`ejer` AS `ejer`,
                    sum(`l`.`monto_bruto`) AS `total_monto`,
                    max(`p`.`fecha_pago`) AS `ultima_fecha`,
                    group_concat(distinct `p`.`volantes` separator '/') AS `volantes`,
                    group_concat(concat('Liq: <b>',`l`.`nro_liquidacion`,'</b>',' ($ ',format(`l`.`monto_bruto`,2,'de_DE'),')',case when `p`.`fecha_pago` is not null then concat(' <i class=\"bi bi-arrow-right\"></i> ',date_format(`p`.`fecha_pago`,'%d/%m/%Y')) else ' <span class=\"text-danger\">(Pendiente)</span>' end) separator '<br>') AS `detalle_pagos` 
                FROM (
                    SELECT 
                        `sicopro_liquidaciones`.`op_sicopro` AS `op_sicopro`,
                        `sicopro_liquidaciones`.`ejer` AS `ejer`,
                        `sicopro_liquidaciones`.`nro_liquidacion` AS `nro_liquidacion`,
                        sum(coalesce(`sicopro_liquidaciones`.`imp_liq`,`sicopro_liquidaciones`.`imp_a_pagar`)) AS `monto_bruto` 
                    FROM `sicopro_liquidaciones` 
                    GROUP BY `sicopro_liquidaciones`.`op_sicopro`,`sicopro_liquidaciones`.`ejer`,`sicopro_liquidaciones`.`nro_liquidacion`
                ) `l` 
                LEFT JOIN (
                    SELECT 
                        `sicopro_sigue`.`liqn` AS `liqn`,
                        `sicopro_sigue`.`ejer` AS `ejer`,
                        max(`sicopro_sigue`.`fecha`) AS `fecha_pago`,
                        group_concat(distinct `sicopro_sigue`.`nro_pago` separator ', ') AS `volantes` 
                    FROM `sicopro_sigue` 
                    GROUP BY `sicopro_sigue`.`liqn`,`sicopro_sigue`.`ejer`
                ) `p` ON `l`.`nro_liquidacion` = `p`.`liqn` AND `l`.`ejer` = `p`.`ejer`
                GROUP BY `l`.`op_sicopro`,`l`.`ejer`
            ) `liq_final` ON `cop`.`nro_op` = `liq_final`.`op_sicopro` AND `cop`.`ejercicio` = `liq_final`.`ejer` 
            LEFT JOIN (
                SELECT 
                    `sicopro_principal`.`movnupa` AS `movnupa`,
                    `sicopro_principal`.`movejer` AS `movejer`,
                    max(`sicopro_principal`.`movfeop`) AS `fecha`,
                    max(`sicopro_principal`.`movtrnu`) AS `movtrnu` 
                FROM `sicopro_principal` 
                WHERE `sicopro_principal`.`movtrti` = 'PA' 
                GROUP BY `sicopro_principal`.`movnupa`,`sicopro_principal`.`movejer`
            ) `pago_sicopro` ON `cop`.`nro_op` = `pago_sicopro`.`movnupa` AND `cop`.`ejercicio` = `pago_sicopro`.`movejer`
            /* --- FIN DE LA LÓGICA DE LA VISTA --- */
        ) AS main
        GROUP BY main.cert_id
        ORDER BY main.cert_id DESC";

try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error SQL: " . $e->getMessage();
    $rows = [];
}

function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
?>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="bi bi-diagram-3-fill text-primary"></i> Tablero de Trazabilidad</h2>
        <a href="certificados_listado.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <?php if(isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-striped mb-0 small">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th class="py-3 ps-3">Certificado</th>
                            <th class="py-3 text-center">Vencimiento</th>
                            <th class="py-3 text-end">Monto Cert.</th>
                            <th class="py-3">Facturas</th>
                            <th class="py-3">Tesorería</th>
                            <th class="py-3" style="min-width: 250px;">Detalle Liquidación y Pago</th>
                            <th class="py-3 text-center"><i class="bi bi-whatsapp"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($rows)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No hay datos</td></tr>
                        <?php endif; ?>

                        <?php foreach($rows as $r): 
                            $hoy = date('Y-m-d');
                            $classVenc = 'text-muted'; $badgeVenc = ''; $estadoTexto = 'PENDIENTE';
                            
                            if($r['fecha_pago']) {
                                $classVenc = 'text-success text-decoration-line-through opacity-50';
                                $estadoTexto = "PAGADO (Total o Parcial)";
                            } elseif($r['fecha_vencimiento']) {
                                if($r['fecha_vencimiento'] < $hoy) {
                                    $classVenc = 'text-danger fw-bold'; $badgeVenc = '<span class="badge bg-danger">VENCIDO</span>'; $estadoTexto = "VENCIDO";
                                } elseif($r['fecha_vencimiento'] < date('Y-m-d', strtotime('+15 days'))) {
                                    $classVenc = 'text-warning fw-bold'; $badgeVenc = '<span class="badge bg-warning text-dark">PRÓXIMO</span>'; $estadoTexto = "PRÓXIMO";
                                } else {
                                    $classVenc = 'text-success'; $estadoTexto = "EN FECHA";
                                }
                            }
                            
                            // Preparar datos para Copiar
                            $facturasTexto = str_replace('<br>', ', ', $r['facturas_detalle'] ?? 'Sin cargar');
                            $pagosTexto = strip_tags(str_replace(['<br>', '<hr class="my-1">'], ["\n", "\n---\n"], $r['detalle_completo_pagos'] ?? 'Pendiente'));
                            
                            $dataCopy = [
                                "Certificado" => "N° " . $r['nro_certificado'] . " (" . $r['periodo'] . ")",
                                "Obra" => $r['obra'],
                                "Empresa" => $r['empresa'],
                                "Vencimiento" => $r['fecha_vencimiento'] ? date('d/m/Y', strtotime($r['fecha_vencimiento'])) : 'S/D',
                                "Monto" => "$ " . fmtM($r['monto_certificado']),
                                "Estado" => $estadoTexto,
                                "Facturas" => $facturasTexto,
                                "OP" => $r['ops'] ?: 'Sin OP',
                                "DetallePagos" => $pagosTexto
                            ];
                            $jsonCopy = htmlspecialchars(json_encode($dataCopy), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td class="ps-3">
                                <div class="d-flex align-items-center">
                                    <a href="certificados_form.php?id=<?= $r['cert_id'] ?>" class="btn btn-sm btn-outline-primary fw-bold me-2">
                                        N° <?= $r['nro_certificado'] ?>
                                    </a>
                                    <div>
                                        <div class="fw-bold text-truncate" style="max-width: 220px;" title="<?= $r['obra'] ?>"><?= $r['obra'] ?></div>
                                        <div class="text-muted"><?= mb_substr($r['empresa'], 0, 15) ?> | <strong><?= $r['periodo'] ?></strong></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center <?= $classVenc ?>">
                                <div><?= $r['fecha_vencimiento'] ? date('d/m/Y', strtotime($r['fecha_vencimiento'])) : '-' ?></div>
                                <?= $badgeVenc ?>
                            </td>
                            <td class="text-end fw-bold">$ <?= fmtM($r['monto_certificado']) ?></td>
                            
                            <td>
                                <?php if($r['facturas_detalle']): ?>
                                    <div class="text-dark" style="font-size:0.85rem; line-height:1.2;">
                                        <?= $r['facturas_detalle'] ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
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
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td style="font-size: 0.85rem;">
                                <?php if($r['detalle_completo_pagos']): ?>
                                    <?= $r['detalle_completo_pagos'] ?>
                                <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-50">Impago</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center">
                                <button class="btn btn-sm btn-light border shadow-sm" onclick='copiarDatos(<?= $jsonCopy ?>)' title="Copiar resumen">
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
    let t = `*ESTADO DE PAGO* 📄\n\n`;
    t += `*Obra:* ${data.Obra}\n`;
    t += `*Empresa:* ${data.Empresa}\n`; // <-- Agregado
    t += `*Certif:* ${data.Certificado}\n`;
    t += `*Vencimiento:* ${data.Vencimiento}\n`; // <-- Agregado
    t += `*Monto:* ${data.Monto}\n`;
    t += `*Facturas:* ${data.Facturas}\n`;
    t += `------------------\n`;
    t += `*OP:* ${data.OP}\n`;
    t += `*Pagos:* \n${data.DetallePagos}`;

    navigator.clipboard.writeText(t).then(() => {
        let el = document.createElement('div');
        el.innerHTML = '¡Copiado!';
        el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;background:#198754;color:fff;padding:8px 15px;border-radius:4px;font-weight:bold;box-shadow:0 2px 5px rgba(0,0,0,0.3)';
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 2000);
    });
}
</script>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
<?php
// modulos/liquidaciones/certificado_retencion.php
// Genera certificado de retención HTML (imprimible como PDF)
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo "ID no especificado."; exit; }

// Obtener liquidación con datos relacionados
$stmt = $pdo->prepare("
    SELECT l.*, 
           e.razon_social, e.cuit, e.condicion_iva, e.ganancias_condicion,
           o.denominacion AS obra_denominacion,
           u.nombre AS usuario_nombre,
           uc.nombre AS usuario_confirmacion_nombre
    FROM liquidaciones l
    JOIN empresas e ON e.id = l.empresa_id
    JOIN obras o ON o.id = l.obra_id
    JOIN usuarios u ON u.id = l.usuario_id
    LEFT JOIN usuarios uc ON uc.id = l.usuario_confirmacion_id
    WHERE l.id = ? AND l.estado = 'CONFIRMADO'
");
$stmt->execute([$id]);
$liq = $stmt->fetch();
if (!$liq) { echo "<div class='alert alert-danger m-4'>Liquidación no encontrada o no está confirmada.</div>"; exit; }

// Items de retención
$stmtItems = $pdo->prepare("SELECT * FROM liquidacion_items WHERE liquidacion_id = ? AND activo = 1 ORDER BY impuesto");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

function fmt($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtFecha($f) { return $f ? date('d/m/Y', strtotime($f)) : '-'; }

$condicionMap = [
    'RI' => 'Responsable Inscripto',
    'MONOTRIBUTO' => 'Monotributista',
    'EXENTO' => 'Exento',
    'NO_CATEGORIZADO' => 'No Categorizado',
];

$impuestoNombre = [
    'GANANCIAS' => 'Impuesto a las Ganancias (RG 830)',
    'IVA'       => 'Impuesto al Valor Agregado',
    'SUSS'      => 'SUSS – Seguridad Social',
    'IIBB'      => 'Ingresos Brutos',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Certificado de Retención <?= htmlspecialchars($liq['nro_certificado_retencion']) ?></title>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; }
        }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #333; background: #f0f0f0; }
        .certificado { max-width: 800px; margin: 20px auto; background: #fff; padding: 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { border-bottom: 3px solid #0d6efd; padding-bottom: 15px; margin-bottom: 20px; }
        .header h2 { margin: 0; color: #0d6efd; font-size: 18px; }
        .header .nro { font-size: 22px; font-weight: bold; color: #dc3545; float: right; }
        .seccion { margin-bottom: 15px; }
        .seccion h4 { font-size: 12px; text-transform: uppercase; color: #6c757d; border-bottom: 1px solid #dee2e6; padding-bottom: 4px; margin-bottom: 8px; }
        .grid { display: flex; flex-wrap: wrap; gap: 5px; }
        .grid .item { flex: 1 1 48%; }
        .grid .item label { font-size: 10px; color: #999; display: block; margin-bottom: 1px; }
        .grid .item span { font-size: 12px; font-weight: 600; }
        table.det { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table.det th, table.det td { border: 1px solid #dee2e6; padding: 6px 8px; font-size: 11px; }
        table.det th { background: #f8f9fa; text-align: center; font-weight: 700; }
        table.det .num { text-align: right; font-family: 'Consolas', monospace; }
        .total-box { background: #f8f9fa; border: 2px solid #0d6efd; border-radius: 6px; padding: 10px 15px; text-align: center; margin: 15px 0; }
        .total-box .label { font-size: 10px; color: #6c757d; text-transform: uppercase; }
        .total-box .amount { font-size: 24px; font-weight: 700; color: #0d6efd; }
        .footer { border-top: 1px solid #dee2e6; padding-top: 10px; margin-top: 30px; font-size: 10px; color: #999; display: flex; justify-content: space-between; }
        .firma { margin-top: 40px; display: flex; justify-content: space-around; }
        .firma .box { text-align: center; width: 200px; }
        .firma .box .linea { border-top: 1px solid #333; margin-top: 50px; padding-top: 5px; font-size: 10px; }
        .btn-bar { text-align: center; margin: 20px auto; max-width: 800px; }
        .btn-bar button, .btn-bar a { padding: 8px 20px; margin: 0 5px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 13px; }
        .btn-print { background: #0d6efd; color: #fff; }
        .btn-back { background: #6c757d; color: #fff; }
    </style>
</head>
<body>

<div class="btn-bar no-print">
    <button class="btn-print" onclick="window.print()"><b>🖨️ Imprimir / Guardar PDF</b></button>
    <a class="btn-back" href="liquidaciones_listado.php">← Volver al Listado</a>
</div>

<div class="certificado">
    <div class="header">
        <span class="nro">N° <?= htmlspecialchars($liq['nro_certificado_retencion']) ?></span>
        <h2>CERTIFICADO DE RETENCIÓN</h2>
        <div style="font-size:11px; color:#666;"><?= APP_NAME ?> – Sistema de Gestión de Obras</div>
    </div>

    <div class="seccion">
        <h4>Datos del Agente de Retención</h4>
        <div class="grid">
            <div class="item"><label>Organismo</label><span><?= APP_NAME ?></span></div>
            <div class="item"><label>Fecha de Emisión</label><span><?= fmtFecha($liq['fecha_confirmacion']) ?></span></div>
        </div>
    </div>

    <div class="seccion">
        <h4>Datos del Sujeto Retenido</h4>
        <div class="grid">
            <div class="item"><label>Razón Social</label><span><?= htmlspecialchars($liq['razon_social']) ?></span></div>
            <div class="item"><label>CUIT</label><span><?= $liq['cuit'] ?></span></div>
            <div class="item"><label>Condición IVA</label><span><?= $condicionMap[$liq['condicion_iva']] ?? $liq['condicion_iva'] ?></span></div>
            <div class="item"><label>Condición Ganancias</label><span><?= $liq['ganancias_condicion'] ?></span></div>
        </div>
    </div>

    <div class="seccion">
        <h4>Datos del Comprobante</h4>
        <div class="grid">
            <div class="item"><label>Obra</label><span><?= htmlspecialchars($liq['obra_denominacion']) ?></span></div>
            <div class="item"><label>Tipo / Origen</label><span><?= $liq['tipo_comprobante_origen'] ?> – <?= htmlspecialchars($liq['comprobante_tipo']) ?></span></div>
            <div class="item"><label>Número</label><span><?= htmlspecialchars($liq['comprobante_numero']) ?></span></div>
            <div class="item"><label>Fecha Comprobante</label><span><?= fmtFecha($liq['comprobante_fecha']) ?></span></div>
            <div class="item"><label>Importe Total</label><span>$ <?= fmt($liq['comprobante_importe_total']) ?></span></div>
            <div class="item"><label>Fecha de Pago</label><span style="color:#dc3545; font-weight:700;"><?= fmtFecha($liq['fecha_pago']) ?></span></div>
        </div>
    </div>

    <div class="seccion">
        <h4>Detalle de Retenciones</h4>
        <table class="det">
            <thead>
                <tr>
                    <th>Impuesto</th>
                    <th>Condición</th>
                    <th>Base Cálculo</th>
                    <th>Mínimo No Sujeto</th>
                    <th>Base Sujeta</th>
                    <th>Alícuota</th>
                    <th>Retención</th>
                </tr>
            </thead>
            <tbody>
                <?php $totalRet = 0; foreach ($items as $item): $totalRet += (float)$item['importe_retencion']; ?>
                <tr>
                    <td><b><?= $impuestoNombre[$item['impuesto']] ?? $item['impuesto'] ?></b></td>
                    <td style="text-align:center;"><?= $item['condicion_fiscal'] ?></td>
                    <td class="num">$ <?= fmt($item['base_calculo']) ?></td>
                    <td class="num">$ <?= fmt($item['minimo_no_sujeto']) ?></td>
                    <td class="num">$ <?= fmt($item['base_sujeta']) ?></td>
                    <td style="text-align:center;"><?= number_format($item['alicuota_aplicada'], 2, ',', '.') ?>%</td>
                    <td class="num" style="font-weight:700; color:#dc3545;">$ <?= fmt($item['importe_retencion']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="total-box">
        <div class="label">Total Retenido</div>
        <div class="amount">$ <?= fmt($totalRet) ?></div>
    </div>

    <div class="grid" style="margin-bottom:10px;">
        <div class="item"><label>Neto a Pagar</label><span style="font-size:16px; color:#198754;">$ <?= fmt($liq['neto_a_pagar']) ?></span></div>
        <div class="item"><label>Base Imponible</label><span>$ <?= fmt($liq['base_imponible']) ?></span></div>
    </div>

    <div class="firma">
        <div class="box">
            <div class="linea">Firma y Sello<br>Agente de Retención</div>
        </div>
        <div class="box">
            <div class="linea">Firma y Sello<br>Sujeto Retenido</div>
        </div>
    </div>

    <div class="footer">
        <span>Liquidación #<?= $liq['id'] ?> | Confirmada: <?= fmtFecha($liq['fecha_confirmacion']) ?> por <?= htmlspecialchars($liq['usuario_confirmacion_nombre'] ?? '') ?></span>
        <span>Impreso: <?= date('d/m/Y H:i') ?></span>
    </div>
</div>

</body>
</html>

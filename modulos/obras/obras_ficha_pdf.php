<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) { echo "ID no especificado."; exit; }

$sql = "SELECT o.*, e.nombre AS estado_nombre 
        FROM obras o 
        LEFT JOIN estados_obra e ON o.estado_obra_id = e.id
        WHERE o.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$obra) { echo "<div style='font-family:sans-serif;padding:20px;color:red;'>Obra no encontrada.</div>"; exit; }

$f_inicio = $obra['fecha_inicio']       ? date('d/m/Y', strtotime($obra['fecha_inicio']))       : '-';
$f_fin    = $obra['fecha_fin_prevista'] ? date('d/m/Y', strtotime($obra['fecha_fin_prevista'])) : '-';
$nombrePdf = "Ficha_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $obra['expediente']) . ".pdf";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha de Obra – <?= htmlspecialchars($obra['denominacion']) ?></title>
    <style>
        @media print { .no-print { display: none !important; } body { margin: 0; } }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #333; background: #eee; margin: 0; }
        .ficha { max-width: 820px; margin: 20px auto; background: #fff; padding: 35px 40px; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .top { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #004b8d; padding-bottom: 10px; margin-bottom: 18px; }
        .top h1 { margin: 0; font-size: 20px; color: #004b8d; text-transform: uppercase; }
        .top .exp { font-size: 12px; color: #555; }
        .sec-header { background: #004b8d; color: #fff; padding: 4px 10px; font-weight: bold; font-size: 11px; margin: 14px 0 4px; }
        table.datos { width: 100%; border-collapse: collapse; }
        table.datos td { border: 1px solid #ccc; padding: 5px 8px; vertical-align: top; }
        table.datos td.lbl { background: #f4f6f8; font-weight: bold; width: 28%; white-space: nowrap; }
        table.datos td.num { text-align: right; font-weight: bold; }
        .obs-box { border: 1px solid #ccc; padding: 8px 10px; min-height: 60px; font-size: 11px; }
        .footer { border-top: 1px solid #ccc; margin-top: 30px; padding-top: 8px; font-size: 10px; color: #999; display: flex; justify-content: space-between; }
        .btn-bar { text-align: center; margin: 16px auto; max-width: 820px; }
        .btn-bar button, .btn-bar a { display: inline-block; padding: 8px 22px; margin: 0 5px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; text-decoration: none; }
        .btn-pdf   { background: #dc3545; color: #fff; }
        .btn-print { background: #004b8d; color: #fff; }
        .btn-back  { background: #6c757d; color: #fff; }
    </style>
</head>
<body>

<div class="btn-bar no-print">
    <button class="btn-pdf"   id="btnPdf">⬇ Descargar PDF</button>
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir</button>
    <a      class="btn-back"  href="javascript:history.back()">← Volver</a>
</div>

<div class="ficha" id="fichaContenido">
    <div class="top">
        <h1>Informe de Obra</h1>
        <div class="exp"><strong>Expediente:</strong> <?= htmlspecialchars($obra['expediente']) ?></div>
    </div>

    <div class="sec-header">1. DATOS GENERALES</div>
    <table class="datos">
        <tr><td class="lbl">Nombre de la obra</td><td colspan="3"><?= htmlspecialchars($obra['denominacion']) ?></td></tr>
        <tr>
            <td class="lbl">Ubicación</td><td><?= htmlspecialchars($obra['ubicacion']) ?></td>
            <td class="lbl">Región</td><td><?= htmlspecialchars($obra['region']) ?></td>
        </tr>
    </table>

    <div class="sec-header">2. INFORMACIÓN ADMINISTRATIVA</div>
    <table class="datos">
        <tr><td class="lbl">Organismo Requiriente</td><td><?= htmlspecialchars($obra['organismo_requirente']) ?></td></tr>
        <tr><td class="lbl">Estado Actual</td><td><?= htmlspecialchars($obra['estado_nombre'] ?? '-') ?></td></tr>
        <tr><td class="lbl">Titularidad del Terreno</td><td><?= htmlspecialchars($obra['titularidad_terreno']) ?></td></tr>
    </table>

    <div class="sec-header">3. INFORMACIÓN ECONÓMICA</div>
    <table class="datos">
        <tr><td class="lbl">Monto Original</td>   <td class="num">$ <?= number_format($obra['monto_original'],   2, ',', '.') ?></td></tr>
        <tr><td class="lbl">Monto Actualizado</td><td class="num">$ <?= number_format($obra['monto_actualizado'], 2, ',', '.') ?></td></tr>
    </table>

    <div class="sec-header">4. INFORMACIÓN DE LA OBRA</div>
    <table class="datos">
        <tr>
            <td class="lbl">Plazo Original</td><td><?= htmlspecialchars($obra['plazo_dias_original']) ?> días</td>
            <td class="lbl">Superficie</td><td><?= htmlspecialchars($obra['superficie_desarrollo']) ?></td>
        </tr>
        <tr>
            <td class="lbl">Fecha Inicio</td><td><?= $f_inicio ?></td>
            <td class="lbl">Fecha Finalización</td><td><?= $f_fin ?></td>
        </tr>
    </table>

    <div class="sec-header">5. CARACTERÍSTICAS PARTICULARES</div>
    <div class="obs-box">
        <strong>Memorias / Objetivos:</strong><br><?= nl2br(htmlspecialchars($obra['memoria_objetivo'] ?? '')) ?>
    </div>
    <div class="obs-box" style="margin-top:6px;">
        <strong>Observaciones:</strong><br><?= nl2br(htmlspecialchars($obra['observaciones'] ?? '')) ?>
    </div>

    <div class="footer">
        <span><?= defined('APP_NAME') ? APP_NAME : 'Sistema de Gestión' ?> – Obra ID #<?= $obra['id'] ?></span>
        <span>Generado: <?= date('d/m/Y H:i') ?></span>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
document.getElementById('btnPdf').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Generando...';
    var opt = {
        margin:       [10, 10, 10, 10],
        filename:     '<?= addslashes($nombrePdf) ?>',
        image:        { type: 'jpeg', quality: 0.97 },
        html2canvas:  { scale: 2, useCORS: true },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(document.getElementById('fichaContenido')).save()
        .then(function() { btn.disabled = false; btn.textContent = '⬇ Descargar PDF'; });
});
</script>
</body>
</html>
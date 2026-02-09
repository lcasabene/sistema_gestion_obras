<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php'; 

use Dompdf\Dompdf;
use Dompdf\Options;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Consulta con los campos reales de tu tabla 'obras'
$sql = "SELECT o.*, e.nombre AS estado_nombre 
        FROM obras o 
        LEFT JOIN estados_obra e ON o.estado_obra_id = e.id
        WHERE o.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$obra) die("Obra no encontrada.");

$options = new Options();
$options->set('isRemoteEnabled', true); 
$dompdf = new Dompdf($options);

// Formateo de datos
$f_inicio = $obra['fecha_inicio'] ? date('d/m/Y', strtotime($obra['fecha_inicio'])) : '-';
$f_fin = $obra['fecha_fin_prevista'] ? date('d/m/Y', strtotime($obra['fecha_fin_prevista'])) : '-';

$html = '
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; padding: 20px; }
        .header-table { width: 100%; border: none; margin-bottom: 20px; }
        .title { font-size: 18px; font-weight: bold; color: #004b8d; text-transform: uppercase; }
        .section-header { background-color: #004b8d; color: white; padding: 5px 10px; font-weight: bold; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; }
        .label { background-color: #f9f9f9; font-weight: bold; width: 25%; }
        .monto { text-align: right; font-weight: bold; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="border:none;"><h1 class="title">Informe de Obra</h1></td>
            <td style="border:none; text-align:right;"><strong>Expediente:</strong> ' . $obra['expediente'] . '</td>
        </tr>
    </table>

    <div class="section-header">1. DATOS GENERALES</div>
    <table>
        <tr><td class="label">Nombre de la obra:</td><td colspan="3">' . $obra['denominacion'] . '</td></tr>
        <tr>
            <td class="label">Ubicación:</td><td>' . $obra['ubicacion'] . '</td>
            <td class="label">Región:</td><td>' . $obra['region'] . '</td>
        </tr>
    </table>

    <div class="section-header">2. INFORMACIÓN ADMINISTRATIVA</div>
    <table>
        <tr><td class="label">Organismo Requiriente:</td><td>' . $obra['organismo_requirente'] . '</td></tr>
        <tr><td class="label">Estado Actual:</td><td>' . $obra['estado_nombre'] . '</td></tr>
        <tr><td class="label">Titularidad del Terreno:</td><td>' . $obra['titularidad_terreno'] . '</td></tr>
    </table>

    <div class="section-header">3. INFORMACIÓN ECONÓMICA</div>
    <table>
        <tr><td class="label">Monto Original:</td><td class="monto">$ ' . number_format($obra['monto_original'], 2, ',', '.') . '</td></tr>
        <tr><td class="label">Monto Actualizado:</td><td class="monto">$ ' . number_format($obra['monto_actualizado'], 2, ',', '.') . '</td></tr>
    </table>

    <div class="section-header">4. INFORMACIÓN DE LA OBRA</div>
    <table>
        <tr>
            <td class="label">Plazo Original:</td><td>' . $obra['plazo_dias_original'] . ' días</td>
            <td class="label">Superficie:</td><td>' . $obra['superficie_desarrollo'] . '</td>
        </tr>
        <tr>
            <td class="label">Fecha Inicio:</td><td>' . $f_inicio . '</td>
            <td class="label">Fecha Finalización:</td><td>' . $f_fin . '</td>
        </tr>
    </table>

    <div class="section-header">5. CARACTERÍSTICAS PARTICULARES</div>
    <div style="border: 1px solid #ccc; padding: 10px; min-height: 100px; margin-top:5px;">
        <strong>Memorias/Objetivos:</strong><br>' . nl2br($obra['memoria_objetivo']) . '<br><br>
        <strong>Observaciones:</strong><br>' . nl2br($obra['observaciones']) . '
    </div>
</body>
</html>';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("Ficha_" . $obra['expediente'] . ".pdf", ["Attachment" => false]);
<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Cargar Dompdf (Asegúrate de tenerlo instalado vía Composer o manual)
require_once __DIR__ . '/../../vendor/autoload.php'; 
use Dompdf\Dompdf;
use Dompdf\Options;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Consulta extendida con los campos de tu imagen
$sql = "SELECT o.*, e.nombre AS estado_nombre, t.nombre AS tipo_nombre 
        FROM obras o 
        LEFT JOIN estados_obra e ON o.estado_obra_id = e.id
        LEFT JOIN tipos_obra t ON o.tipo_obra_id = t.id
        WHERE o.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$obra) { die("Obra no encontrada."); }

// Configuración de Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); // Para cargar logos/imágenes
$dompdf = new Dompdf($options);

// Diseño HTML (Basado en tu modelo de ficha)
$html = '
<html>
<head>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; border-bottom: 2px solid #004b8d; padding-bottom: 10px; margin-bottom: 20px; }
        .section-title { background: #f2f2f2; padding: 8px; font-weight: bold; color: #004b8d; margin-top: 15px; border-left: 4px solid #004b8d; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        .label { font-weight: bold; background-color: #fafafa; width: 30%; }
        .monto { font-family: monospace; font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h2>FICHA INFORME DE OBRA</h2>
        <p>Expediente: ' . htmlspecialchars($obra['expediente']) . '</p>
    </div>

    <div class="section-title">DATOS GENERALES</div>
    <table>
        <tr>
            <td class="label">Denominación:</td>
            <td colspan="3">' . htmlspecialchars($obra['denominacion']) . '</td>
        </tr>
        <tr>
            <td class="label">Ubicación / Región:</td>
            <td>' . htmlspecialchars($obra['ubicacion']) . ' (' . htmlspecialchars($obra['region'] ?? 'N/A') . ')</td>
            <td class="label">Estado:</td>
            <td>' . htmlspecialchars($obra['estado_nombre']) . '</td>
        </tr>
        <tr>
            <td class="label">Fecha Inicio:</td>
            <td>' . ($obra['fecha_inicio'] ? date('d/m/Y', strtotime($obra['fecha_inicio'])) : '-') . '</td>
            <td class="label">Fin Previsto:</td>
            <td>' . ($obra['fecha_fin_prevista'] ? date('d/m/Y', strtotime($obra['fecha_fin_prevista'])) : '-') . '</td>
        </tr>
    </table>

    <div class="section-title">INFORMACIÓN ECONÓMICA</div>
    <table>
        <tr>
            <td class="label">Monto Original:</td>
            <td class="monto">$ ' . number_format($obra['monto_original'], 2, ',', '.') . '</td>
        </tr>
        <tr>
            <td class="label">Monto Actualizado:</td>
            <td class="monto">$ ' . number_format($obra['monto_actualizado'], 2, ',', '.') . '</td>
        </tr>
        <tr>
            <td class="label">Anticipo Financiero:</td>
            <td>' . number_format($obra['anticipo_pct'] ?? 0, 2) . '% ($' . number_format($obra['anticipo_monto'] ?? 0, 2, ',', '.') . ')</td>
        </tr>
    </table>

    <div class="section-title">OBSERVACOINES Y MEMORIA</div>
    <div style="padding: 10px; border: 1px solid #ddd; min-height: 100px;">
        ' . nl2br(htmlspecialchars($obra['observaciones'] ?? 'Sin observaciones registradas.')) . '
    </div>

    <div style="margin-top: 30px; font-size: 10px; text-align: right; color: #777;">
        Documento generado el: ' . date('d/m/Y H:i') . '
    </div>
</body>
</html>';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Nombre del archivo basado en el expediente
$filename = "Ficha_Obra_" . str_replace('/', '-', $obra['expediente']) . ".pdf";
$dompdf->stream($filename, ["Attachment" => false]); // false para abrir en navegador
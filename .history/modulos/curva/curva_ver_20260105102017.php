<?php
// modulos/curva/curva_export.php
session_start();
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("Versión no especificada.");

// Obtener datos cabecera
$stmt = $pdo->prepare("SELECT v.*, o.denominacion FROM curva_version v JOIN obras o ON o.id = v.obra_id WHERE v.id = ?");
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cabecera) die("Versión no encontrada.");

// Obtener items
$stmtI = $pdo->prepare("SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC");
$stmtI->execute([$versionId]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// Configurar Headers para descarga Excel
$filename = "Plan_Inversion_" . preg_replace('/[^a-zA-Z0-9]/', '_', $cabecera['denominacion']) . "_V$versionId.xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Helper formato
function fmt($n) { return number_format((float)$n, 2, ',', '.'); }
function fmtFri($n) { return number_format((float)$n, 4, ',', '.'); }

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="content-type" content="text/plain; charset=UTF-8"/>
</head>
<body>
    <h3>Plan de Inversión: <?= $cabecera['denominacion'] ?></h3>
    <p>Versión ID: <?= $versionId ?> | Fecha Gen: <?= date('d/m/Y', strtotime($cabecera['fecha_creacion'])) ?></p>
    
    <table border="1">
        <thead>
            <tr style="background-color: #f0f0f0; font-weight: bold;">
                <th>Periodo</th>
                <th>Concepto</th>
                <th>% Fisico</th>
                <th>Monto Básico</th>
                <th>% Infl.</th>
                <th>FRI</th>
                <th>Redeterminación</th>
                <th>Recupero Ant.</th>
                <th>Monto Neto (A Pagar)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items as $item): ?>
            <tr>
                <td><?= date('m/Y', strtotime($item['periodo'])) ?></td>
                <td><?= utf8_decode($item['concepto']) ?></td>
                <td><?= fmt($item['porcentaje_fisico']) ?>%</td>
                <td><?= fmt($item['monto_base']) ?></td>
                <td><?= fmt($item['indice_inflacion']) ?>%</td>
                <td><?= fmtFri($item['fri']) ?></td>
                <td><?= fmt($item['redeterminacion']) ?></td>
                <td style="color:red;"><?= ($item['recupero'] > 0 ? '-' : '') . fmt($item['recupero']) ?></td>
                <td style="font-weight:bold;"><?= fmt($item['neto']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
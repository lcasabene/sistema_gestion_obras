<?php
// curva_export.php
session_start();
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("Versión no especificada.");

// 1. Obtener Datos (Misma consulta que curva_ver)
$sqlHead = "SELECT v.*, o.denominacion FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 2. Configurar Headers para forzar descarga Excel
$filename = "Curva_" . preg_replace('/[^a-z0-9]/i', '_', $cabecera['denominacion']) . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Helpers simples para el excel
function fmt($val) { return number_format((float)$val, 2, ',', '.'); }
?>

<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="content-type" content="text/plain; charset=UTF-8"/>
</head>
<body>
    <h3><?= $cabecera['denominacion'] ?></h3>
    <p>
        <strong>Monto:</strong> $ <?= fmt($cabecera['monto_presupuesto']) ?><br>
        <strong>Plazo:</strong> <?= $cabecera['plazo_meses'] ?> Meses<br>
        <strong>Mes Base:</strong> <?= $cabecera['mes_base'] ?? 'N/D' ?>
    </p>

    <table border="1">
        <thead>
            <tr style="background-color: #f0f0f0;">
                <th>Periodo</th>
                <th>Concepto</th>
                <th>% Fisico</th>
                <th>Monto Base</th>
                <th>% Infl.</th>
                <th>FRI</th>
                <th>Redeterminacion</th>
                <th>Recupero</th>
                <th>Neto a Cobrar</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items as $i): 
                $color = (stripos($i['concepto'], 'anticipo') !== false) ? '#fff3cd' : '#ffffff';
            ?>
            <tr style="background-color: <?= $color ?>;">
                <td><?= $i['periodo'] ?></td>
                <td><?= $i['concepto'] ?></td>
                <td><?= str_replace('.',',', $i['porcentaje_fisico']) ?>%</td>
                <td><?= fmt($i['monto_base']) ?></td>
                <td><?= str_replace('.',',', $i['indice_inflacion']) ?></td>
                <td><?= str_replace('.',',', $i['fri']) ?></td>
                <td><?= fmt($i['redeterminacion']) ?></td>
                <td><?= fmt($i['recupero']) ?></td>
                <td><strong><?= fmt($i['neto']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
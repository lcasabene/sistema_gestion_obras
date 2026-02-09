<?php
// modulos/curva/curva_export.php
session_start();
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("Versión no especificada.");

// 1. Obtener cabecera y ID real de la obra
$stmt = $pdo->prepare("SELECT v.*, o.id as obra_real_id, o.denominacion 
                       FROM curva_version v 
                       JOIN obras o ON o.id = v.obra_id 
                       WHERE v.id = ?");
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cabecera) die("Versión no encontrada.");

// 2. Obtener items (Planificación)
$stmtI = $pdo->prepare("SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC");
$stmtI->execute([$versionId]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. Obtener Certificados (Datos Reales) - NUEVO
$sqlCerts = "SELECT * FROM certificados WHERE obra_id = ? AND estado != 'ANULADO' ORDER BY periodo ASC";
$stmtC = $pdo->prepare($sqlCerts);
$stmtC->execute([$cabecera['obra_real_id']]);
$certificadosDB = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Mapear certificados por periodo (YYYY-MM)
$mapaCertificados = [];
foreach ($certificadosDB as $c) {
    $periodo = substr($c['periodo'], 0, 7); // "2025-01"
    $mapaCertificados[$periodo][$c['tipo']][] = $c;
}

// 4. Forzar descarga Excel
$filename = "Plan_Control_" . preg_replace('/[^a-zA-Z0-9]/', '_', $cabecera['denominacion']) . "_V$versionId.xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

function fmt($n) { return number_format((float)$n, 2, ',', '.'); }
function fmtFri($n) { return number_format((float)$n, 4, ',', '.'); }
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="content-type" content="text/plain; charset=UTF-8"/>
    <style>
        th { color: white; text-align: center; vertical-align: middle; }
        .head-plan { background-color: #6c757d; } /* Gris */
        .head-real { background-color: #198754; } /* Verde */
        .num { mso-number-format:"\#\,\#\#0\.00"; }
        .pct { mso-number-format:"0\.00%"; }
    </style>
</head>
<body>
    <h3>Control de Inversión: <?= $cabecera['denominacion'] ?></h3>
    <p>Versión ID: <?= $versionId ?> | Fecha: <?= date('d/m/Y', strtotime($cabecera['fecha_creacion'])) ?></p>
    
    <table border="1">
        <thead>
            <tr>
                <th style="background-color: #333;" rowspan="2">Periodo</th>
                <th style="background-color: #333;" rowspan="2">Concepto</th>
                
                <th class="head-plan" colspan="5">PLANIFICACIÓN</th>
                
                <th class="head-real" colspan="4">EJECUCIÓN REAL</th>
            </tr>
            <tr>
                <th class="head-plan">% Fis</th>
                <th class="head-plan">Monto Básico</th>
                <th class="head-plan">FRI Est.</th>
                <th class="head-plan">Redet. Est.</th>
                <th class="head-plan">Total Plan</th>

                <th class="head-real">% Real</th>
                <th class="head-real">Cert. Básico</th>
                <th class="head-real">Cert. Redet.</th>
                <th class="head-real">Total Real</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items as $item): 
                $per = date('Y-m', strtotime($item['periodo']));
                
                // Calcular datos REALES para este periodo
                $realFisico = 0;
                $realBasico = 0;
                $realRedet = 0;

                // Sumar Ordinarios y Anticipos (Básico)
                if(isset($mapaCertificados[$per]['ORDINARIO'])) {
                    foreach($mapaCertificados[$per]['ORDINARIO'] as $c) {
                        $realFisico += $c['avance_fisico_mensual'];
                        $realBasico += $c['monto_basico'];
                    }
                }
                if(isset($mapaCertificados[$per]['ANTICIPO'])) {
                    foreach($mapaCertificados[$per]['ANTICIPO'] as $c) {
                        // El anticipo suma dinero pero no avance físico
                        $realBasico += $c['monto_bruto']; 
                    }
                }

                // Sumar Redeterminaciones
                if(isset($mapaCertificados[$per]['REDETERMINACION'])) {
                    foreach($mapaCertificados[$per]['REDETERMINACION'] as $c) {
                        $realRedet += $c['monto_redeterminado'];
                    }
                }

                $realTotal = $realBasico + $realRedet;
            ?>
            <tr>
                <td><?= date('m/Y', strtotime($item['periodo'])) ?></td>
                <td><?= utf8_decode($item['concepto']) ?></td>
                
                <td class="pct"><?= fmt($item['porcentaje_fisico']) ?>%</td>
                <td class="num"><?= fmt($item['monto_base']) ?></td>
                <td align="center"><?= fmtFri($item['fri']) ?></td>
                <td class="num"><?= fmt($item['redeterminacion']) ?></td>
                <td class="num" style="background-color: #e2e3e5; font-weight:bold;"><?= $realTotal ?></td>

                <td class="pct" style="color: #198754; font-weight:bold;"><?= ($realFisico > 0) ? fmt($realFisico).'%' : '-' ?></td>
                <td class="num"><?= ($realBasico != 0) ? fmt($realBasico) : '-' ?></td>
                <td class="num"><?= ($realRedet != 0) ? fmt($realRedet) : '-' ?></td>
                <td class="num" style="background-color: #d1e7dd; font-weight:bold;"><?= ($realTotal != 0) ? fmt($realTotal) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
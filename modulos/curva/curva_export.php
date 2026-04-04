<?php
// modulos/curva/curva_export.php
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}
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

// 3. Obtener Certificados vinculados a ESTA versión (por curva_item_id)
$idsItems = array_column($items, 'id');
$mapaCertificados = [];
if (!empty($idsItems)) {
    $idsString = implode(',', array_map('intval', $idsItems));
    $sqlCerts = "SELECT * FROM certificados WHERE estado != 'ANULADO' AND curva_item_id IN ($idsString) ORDER BY nro_certificado ASC";
    $stmtC = $pdo->query($sqlCerts);
    while ($c = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        $mapaCertificados[$c['curva_item_id']][$c['tipo']][] = $c;
    }
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
                $itemId = $item['id'];
                $esPlanAnticipo = (stripos($item['concepto'], 'anticipo') !== false);
                
                // Calcular datos REALES para este item (por curva_item_id)
                $realFisico = 0;
                $realBasico = 0;
                $realRedet = 0;

                // Sumar Ordinarios o Anticipos según tipo de item
                $tipoPrincipal = $esPlanAnticipo ? 'ANTICIPO' : 'ORDINARIO';
                if(isset($mapaCertificados[$itemId][$tipoPrincipal])) {
                    foreach($mapaCertificados[$itemId][$tipoPrincipal] as $c) {
                        $realFisico += $c['avance_fisico_mensual'];
                        $realBasico += $c['monto_bruto'];
                    }
                }

                // Sumar Redeterminaciones
                if(isset($mapaCertificados[$itemId]['REDETERMINACION'])) {
                    foreach($mapaCertificados[$itemId]['REDETERMINACION'] as $c) {
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
                <td class="num" style="background-color: #e2e3e5; font-weight:bold;"><?= fmt($item['monto_base'] + $item['redeterminacion']) ?></td>

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
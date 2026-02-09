<?php
// modulos/curva/curva_export.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;

if (!$versionId) {
    die("Error: Versión no especificada.");
}

// 1. OBTENER CABECERA
$sqlHead = "SELECT v.*, o.id as obra_real_id, o.denominacion, o.expediente, o.monto_original, o.monto_actualizado 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cabecera) die("Versión no encontrada.");

// 2. OBTENER ÍTEMS (PLANIFICACIÓN)
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$itemsPlan = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. OBTENER CERTIFICADOS (REALIDAD)
$sqlReal = "
    SELECT 
        c.id, c.periodo, c.nro_certificado, c.tipo, c.fri,
        c.monto_basico, c.monto_redeterminado, c.avance_fisico_mensual,
        GROUP_CONCAT(CONCAT(ca.numero, ' ($ ', FORMAT(ca.importe_total, 2, 'de_DE'), ')') SEPARATOR '\n') as facturas_detalle
    FROM certificados c
    LEFT JOIN certificados_facturas cf ON c.id = cf.certificado_id
    LEFT JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id
    WHERE c.obra_id = ? AND c.estado != 'ANULADO'
    GROUP BY c.id
    ORDER BY c.tipo ASC 
";
$stmtC = $pdo->prepare($sqlReal);
$stmtC->execute([$cabecera['obra_real_id']]);
$allCerts = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por Periodo
$certsByPeriod = [];
foreach ($allCerts as $c) {
    $per = substr($c['periodo'], 0, 7);
    $certsByPeriod[$per][] = $c;
}

// 4. CONFIGURAR EXCEL
$filename = "Seguimiento_" . preg_replace('/[^a-zA-Z0-9]/', '_', $cabecera['denominacion']) . "_" . date('Ymd') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Helpers
function num($val) { return number_format((float)$val, 2, ',', '.'); }
function fri($val) { return number_format((float)$val, 4, ',', '.'); }
function pct($val) { return number_format((float)$val, 2, ',', '.') . '%'; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 12px; }
        th { background-color: #0d6efd; color: white; border: 1px solid #000; padding: 8px; text-align: center; vertical-align: middle; }
        td { border: 1px solid #999; padding: 5px; vertical-align: middle; }
        .num { text-align: right; }
        .center { text-align: center; }
        .titulo { font-size: 16px; font-weight: bold; background-color: #e9ecef; border: 1px solid #000; height: 35px; }
        .subtitulo { font-size: 12px; background-color: #f8f9fa; font-weight: bold; border: 1px solid #000; }
        
        /* Colores */
        .bg-plan { background-color: #f8f9fa; }
        .bg-basico { background-color: #d1e7dd; } 
        .bg-redet { background-color: #fff3cd; } 
        .bg-anticipo { background-color: #cfe2ff; }
        .text-red { color: #dc3545; font-weight: bold; }
        .text-green { color: #198754; font-weight: bold; }
        .border-top-strong { border-top: 2px solid #000 !important; }
        .nro-cert { font-weight: bold; font-size: 1.1em; }
    </style>
</head>
<body>

<table>
    <tr>
        <td colspan="12" class="titulo" align="center"><?= htmlspecialchars($cabecera['denominacion']) ?></td>
    </tr>
    <tr>
        <td colspan="12" class="subtitulo" align="center">
            Expediente: <?= htmlspecialchars($cabecera['expediente']) ?> | 
            Monto Vigente: $ <?= num($cabecera['monto_actualizado']) ?>
        </td>
    </tr>
    <tr><td colspan="12"></td></tr>
    
    <thead>
        <tr>
            <th colspan="3" style="background-color: #6c757d;">PLANIFICACIÓN (CURVA S)</th>
            <th colspan="7" style="background-color: #198754;">EJECUCIÓN REAL Y REDETERMINACIONES</th>
            <th colspan="2" style="background-color: #0dcaf0; color: #000;">FACTURACIÓN</th>
        </tr>
        <tr>
            <th style="width: 80px;">Periodo</th>
            <th style="width: 80px;">% Plan</th>
            <th style="width: 120px;">Monto Plan ($)</th>
            
            <th style="width: 50px;">Nº</th>
            <th style="width: 100px;">Tipo Cert.</th>
            <th style="width: 70px;">FRI</th>
            <th style="width: 80px;">% Real</th>
            <th style="width: 120px;">Monto Cert. ($)</th>
            <th style="width: 80px;">Acum. %</th>
            <th style="width: 80px;">Desvío %</th>
            
            <th style="width: 250px;">Facturas Asoc.</th>
            <th style="width: 80px;">Control</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $acumPlan = 0;
        $acumReal = 0;
        
        foreach($itemsPlan as $plan): 
            $per = date('Y-m', strtotime($plan['periodo']));
            $esAnticipoPlan = (stripos($plan['concepto'], 'anticipo') !== false);
            
            // Datos Plan
            $pctPlan = $plan['porcentaje_fisico'];
            $montoPlan = $plan['neto'];
            
            if (!$esAnticipoPlan) {
                $acumPlan += $pctPlan;
            }

            // Buscar Certificados Reales
            $certificados = $certsByPeriod[$per] ?? [];
            $cantFilas = max(1, count($certificados));
            
            // ITERAR FILAS
            for ($i = 0; $i < $cantFilas; $i++):
                $cert = $certificados[$i] ?? null;
                
                // Estilos y Datos
                $bgClass = "";
                $tipoTxt = "-";
                $nroTxt = "-";
                $friTxt = "-";
                $pctRealTxt = "-";
                $montoRealTxt = "-";
                $facturasTxt = "";
                
                if ($cert) {
                    $nroTxt = '<span class="nro-cert">'.$cert['nro_certificado'].'</span>';
                    $facturasTxt = $cert['facturas_detalle'];
                    
                    if ($cert['tipo'] == 'ORDINARIO') {
                        $bgClass = "bg-basico";
                        $tipoTxt = "Básico";
                        $pctRealTxt = pct($cert['avance_fisico_mensual']);
                        $montoRealTxt = "$ " . num($cert['monto_basico']);
                        $acumReal += $cert['avance_fisico_mensual'];
                    } 
                    elseif ($cert['tipo'] == 'REDETERMINACION') {
                        $bgClass = "bg-redet";
                        $tipoTxt = "Redet.";
                        $friTxt = fri($cert['fri']);
                        $montoRealTxt = "$ " . num($cert['monto_redeterminado']);
                    }
                    elseif ($cert['tipo'] == 'ANTICIPO') {
                        $bgClass = "bg-anticipo";
                        $tipoTxt = "Anticipo";
                        $montoRealTxt = "$ " . num($cert['monto_basico']);
                    }
                }

                // Cálculo Desvío (Solo en la primera fila no anticipo)
                $desvioTxt = "-";
                $acumRealTxt = "-";
                
                if ($i === 0 && !$esAnticipoPlan) {
                    $desvio = $acumReal - $acumPlan;
                    $acumRealTxt = pct($acumReal);
                    
                    if ($acumReal > 0) {
                        $colorDesvio = ($desvio < 0) ? 'text-red' : 'text-green';
                        $desvioTxt = '<span class="'.$colorDesvio.'">'.pct($desvio).'</span>';
                    }
                }
                
                $rowStyle = ($i === 0) ? 'class="border-top-strong"' : '';
        ?>
        <tr <?= $rowStyle ?>>
            <?php if ($i === 0): ?>
                <td class="center bg-plan fw-bold"><?= date('m/Y', strtotime($per)) ?></td>
                <td class="center bg-plan"><?= pct($pctPlan) ?></td>
                <td class="num bg-plan">$ <?= num($montoPlan) ?></td>
            <?php else: ?>
                <td class="bg-plan"></td>
                <td class="bg-plan"></td>
                <td class="bg-plan"></td>
            <?php endif; ?>

            <td class="center <?= $bgClass ?>"><?= $nroTxt ?></td>
            <td class="center <?= $bgClass ?>"><?= $tipoTxt ?></td>
            <td class="center <?= $bgClass ?>"><?= $friTxt ?></td>
            <td class="center <?= $bgClass ?>"><?= $pctRealTxt ?></td>
            <td class="num <?= $bgClass ?>"><?= $montoRealTxt ?></td>
            
            <td class="center bg-plan"><?= ($i === 0 && !$esAnticipoPlan) ? $acumRealTxt : '' ?></td>
            <td class="center bg-plan"><?= ($i === 0 && !$esAnticipoPlan) ? $desvioTxt : '' ?></td>

            <td style="font-size: 10px; white-space: pre-wrap;"><?= $facturasTxt ?></td>
            <td class="center"><?= ($facturasTxt) ? 'OK' : '-' ?></td>
        </tr>
        <?php 
            endfor; 
        endforeach; 
        ?>
    </tbody>
</table>

</body>
</html>
<?php
// modulos/curva/curva_export.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;

if (!$versionId) {
    die("Error: Versión no especificada.");
}

// 1. Obtener Cabecera
$sqlHead = "SELECT v.*, o.id as obra_real_id, o.denominacion, o.expediente, o.monto_original, o.monto_actualizado 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cabecera) die("Versión no encontrada.");

// 2. Obtener Ítems (Planificación)
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. Obtener Certificados (Realidad) y Facturas
// Traemos todo junto y lo procesamos en PHP para no duplicar filas
$sqlReal = "
    SELECT 
        c.id, c.periodo, c.tipo, c.monto_basico, c.monto_redeterminado, c.avance_fisico_mensual,
        ca.numero as factura_numero, ca.importe_total as factura_monto
    FROM certificados c
    LEFT JOIN certificados_facturas cf ON c.id = cf.certificado_id
    LEFT JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id
    WHERE c.obra_id = ? AND c.estado != 'ANULADO'
    ORDER BY c.periodo ASC
";
$stmtC = $pdo->prepare($sqlReal);
$stmtC->execute([$cabecera['obra_real_id']]);
$rawReal = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Organizar datos por Periodo
$dataReal = [];
foreach ($rawReal as $r) {
    $per = substr($r['periodo'], 0, 7); // YYYY-MM
    
    // Inicializar si no existe
    if (!isset($dataReal[$per])) {
        $dataReal[$per] = [
            'avance_fisico' => 0,
            'monto_certificado' => 0,
            'facturas' => []
        ];
    }

    // Sumar montos (evitando duplicar si un certificado tiene varias facturas)
    // Usamos un array auxiliar de certificados procesados para sumar solo una vez el monto del certificado
    if (!isset($dataReal[$per]['certs_procesados'][$r['id']])) {
        if ($r['tipo'] == 'ORDINARIO') {
            $dataReal[$per]['avance_fisico'] += $r['avance_fisico_mensual'];
        }
        // Sumamos Básico + Redet para tener el total certificado bruto
        $dataReal[$per]['monto_certificado'] += ($r['monto_basico'] + $r['monto_redeterminado']);
        $dataReal[$per]['certs_procesados'][$r['id']] = true;
    }

    // Agregar Factura (si existe)
    if ($r['factura_numero']) {
        $dataReal[$per]['facturas'][] = $r['factura_numero'] . " ($ " . number_format($r['factura_monto'], 2, ',', '.') . ")";
    }
}

// 4. Configurar Headers para descarga Excel
$filename = "Avance_" . preg_replace('/[^a-zA-Z0-9]/', '_', $cabecera['denominacion']) . "_" . date('Ymd') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Helpers
function num($val) { return number_format((float)$val, 2, ',', '.'); }
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
        .titulo { font-size: 16px; font-weight: bold; background-color: #e9ecef; border: 1px solid #000; height: 30px; }
        .subtitulo { font-size: 12px; background-color: #f8f9fa; font-weight: bold; }
        .bg-real { background-color: #d1e7dd; } /* Verde claro */
        .bg-anticipo { background-color: #fff3cd; } /* Amarillo */
    </style>
</head>
<body>

<table>
    <tr>
        <td colspan="9" class="titulo" align="center"><?= htmlspecialchars($cabecera['denominacion']) ?></td>
    </tr>
    <tr>
        <td colspan="9" class="subtitulo" align="center">
            Expediente: <?= htmlspecialchars($cabecera['expediente']) ?> | 
            Monto Vigente: $ <?= num($cabecera['monto_actualizado']) ?>
        </td>
    </tr>
    <tr><td colspan="9"></td></tr>
    
    <thead>
        <tr>
            <th colspan="4" style="background-color: #6c757d;">PLANIFICACIÓN (CURVA S)</th>
            <th colspan="3" style="background-color: #198754;">EJECUCIÓN REAL</th>
            <th colspan="2" style="background-color: #0dcaf0; color: #000;">FACTURACIÓN</th>
        </tr>
        <tr>
            <th style="width: 80px;">Periodo</th>
            <th style="width: 250px;">Concepto</th>
            <th style="width: 80px;">% Físico</th>
            <th style="width: 120px;">Monto Plan ($)</th>
            
            <th style="width: 80px;">% Real</th>
            <th style="width: 80px;">Desvío</th>
            <th style="width: 120px;">Certificado ($)</th>
            
            <th style="width: 200px;">Facturas Asoc.</th>
            <th style="width: 120px;">Monto Fact. ($)</th> </tr>
    </thead>
    <tbody>
        <?php 
        $acumPlan = 0;
        $acumReal = 0;
        
        foreach($items as $i): 
            $per = date('Y-m', strtotime($i['periodo']));
            $esAnticipo = (stripos($i['concepto'], 'anticipo') !== false);
            
            // Datos Plan
            $planPct = $i['porcentaje_fisico'];
            $planMonto = $i['neto'];
            
            // Datos Real (Buscados en el array procesado)
            $realData = $dataReal[$per] ?? null;
            $realPct = $realData ? $realData['avance_fisico'] : 0;
            $realMonto = $realData ? $dataReal[$per]['monto_certificado'] : 0;
            $facturasTxt = $realData ? implode(",\n", $realData['facturas']) : ''; // Salto de linea en celda
            
            // Cálculos
            if(!$esAnticipo) {
                $acumPlan += $planPct;
                $acumReal += $realPct;
                $desvio = $acumReal - $acumPlan;
                $styleDesvio = ($desvio < 0) ? 'color: red;' : 'color: green;';
            } else {
                $desvio = 0; $styleDesvio = '';
            }
            
            $bg = $esAnticipo ? 'class="bg-anticipo"' : '';
            $bgReal = ($realMonto > 0) ? 'class="bg-real num"' : 'class="num"';
        ?>
        <tr>
            <td <?= $bg ?> class="center"><?= date('m/Y', strtotime($per)) ?></td>
            <td <?= $bg ?>><?= htmlspecialchars($i['concepto']) ?></td>
            <td <?= $bg ?> class="center"><?= pct($planPct) ?></td>
            <td <?= $bg ?> class="num">$ <?= num($planMonto) ?></td>
            
            <td class="center"><?= ($realPct > 0 || $realMonto > 0) ? pct($realPct) : '-' ?></td>
            <td class="center" style="<?= $styleDesvio ?> font-weight:bold;">
                <?= (!$esAnticipo && ($realPct > 0 || $realMonto > 0)) ? pct($desvio) : '-' ?>
            </td>
            <td <?= $bgReal ?>>$ <?= num($realMonto) ?></td>
            
            <td style="font-size: 10px; white-space: pre-wrap;"><?= $facturasTxt ?></td>
            <td class="center"><?= ($facturasTxt) ? 'Ver detalle' : '-' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr style="background-color: #333; color: #fff; font-weight: bold;">
            <td colspan="2" align="right">ACUMULADOS:</td>
            <td align="center"><?= pct($acumPlan) ?></td>
            <td></td>
            <td align="center"><?= pct($acumReal) ?></td>
            <td colspan="4"></td>
        </tr>
    </tfoot>
</table>

</body>
</html>
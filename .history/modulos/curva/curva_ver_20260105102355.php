<?php
// modulos/curva/curva_ver.php
session_start();
// HEADER CORRECTO PARA VER EN PANTALLA (NO DESCARGAR)
header('Content-Type: text/html; charset=utf-8'); 

require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;
if (!$versionId) die("<div class='alert alert-danger m-4'>Error: Versión no especificada.</div>");

// 1. Datos Obra y Versión Actual
$sqlHead = "SELECT v.*, v.monto_presupuesto as monto_version, 
            o.id as obra_real_id, o.denominacion, o.monto_actualizado, o.monto_original, o.anticipo_pct, e.cuit as empresa_cuit 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id 
            LEFT JOIN empresas e ON o.empresa_id = e.id
            WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cabecera) die("<div class='alert alert-danger m-4'>No se encontró la versión de curva solicitada.</div>");

// --- LISTA DE TODAS LAS VERSIONES (Para el selector) ---
$sqlVersiones = "SELECT id, fecha_creacion, es_vigente FROM curva_version WHERE obra_id = ? ORDER BY id DESC";
$stmtVersiones = $pdo->prepare($sqlVersiones);
$stmtVersiones->execute([$cabecera['obra_real_id']]);
$todasLasVersiones = $stmtVersiones->fetchAll(PDO::FETCH_ASSOC);

// --- MONTO A VISUALIZAR ---
$montoVisualizar = ($cabecera['monto_version'] > 0) ? $cabecera['monto_version'] : (($cabecera['monto_original'] > 0) ? $cabecera['monto_original'] : $cabecera['monto_original']);

// 2. Planificación (Items de la Curva)
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$itemsCurva = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// 3. Certificados Reales (Cargados en el sistema)
$sqlCerts = "SELECT * FROM certificados WHERE obra_id = ? AND estado != 'ANULADO' ORDER BY periodo ASC, nro_certificado ASC";
$stmtC = $pdo->prepare($sqlCerts);
$stmtC->execute([$cabecera['obra_real_id']]);
$certificadosDB = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Mapear certificados por periodo
$mapaCertificados = [];
foreach ($certificadosDB as $c) {
    $periodo = substr($c['periodo'], 0, 7);
    $mapaCertificados[$periodo][$c['tipo']][] = $c;
}

// Helpers de Formato
function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtPct($v) { return number_format((float)$v, 2, ',', '.') . '%'; }
function fmtFri($v) { return number_format((float)$v, 4, ',', '.'); }

// Preparar Datos para Gráfico
$labels = []; $dataPlan = []; $dataReal = []; $acumPlan = 0; $acumReal = 0;
foreach ($itemsCurva as $i) {
    if (stripos($i['concepto'], 'anticipo') === false) {
        $per = date('Y-m', strtotime($i['periodo']));
        $labels[] = date('m/y', strtotime($i['periodo']));
        
        // Acumulado Planificado
        $acumPlan += $i['porcentaje_fisico'];
        $dataPlan[] = $acumPlan;
        
        // Acumulado Real
        $avanceMes = 0; $hubo = false;
        if(isset($mapaCertificados[$per]['ORDINARIO'])) {
            foreach($mapaCertificados[$per]['ORDINARIO'] as $cert) {
                $avanceMes += $cert['avance_fisico_mensual'];
                $hubo = true;
            }
        }
        
        // Solo graficamos real si hubo movimiento o si ya empezó la obra
        if ($hubo || $acumReal > 0) { 
            $acumReal += $avanceMes; 
            $dataReal[] = $acumReal; 
        } else { 
            // Si estamos muy a futuro, null para que no pinte línea en 0
            $dataReal[] = null; 
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tablero - <?= htmlspecialchars($cabecera['denominacion']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .table-vcenter td { vertical-align: middle; font-size: 0.85rem; }
        .col-plan { background-color: #f8f9fa; border-right: 1px solid #dee2e6; }
        .col-basico { background-color: #f0fdf4; } 
        .col-redet { background-color: #fff8e1; }
        .nro-cert { font-size: 0.85em; font-weight: 700; color: #0d6efd; background: #e7f1ff; padding: 2px 6px; border-radius: 4px; border: 1px solid #cce5ff; white-space: nowrap; display: inline-block; margin-right: 4px;}
        .input-fufi { text-align: right; }
        .modal-body { min-height: 400px; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="text-primary fw-bold mb-0"><i class="bi bi-kanban"></i> Gestión de Certificados</h4>
            <div class="text-muted small">
                Obra: <strong><?= htmlspecialchars($cabecera['denominacion']) ?></strong> | 
                Base Curva: <span class="badge bg-primary text-white" style="font-size: 0.9em;">$ <?= fmtM($montoVisualizar) ?></span> |
                Anticipo: <strong><?= $cabecera['anticipo_pct'] ?>%</strong>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white fw-bold">Versión:</span>
                <select class="form-select form-select-sm fw-bold border-secondary" style="max-width: 200px;" onchange="window.location.href='curva_ver.php?version_id='+this.value">
                    <?php foreach($todasLasVersiones as $ver): 
                        $fecha = date('d/m/Y', strtotime($ver['fecha_creacion']));
                        $esActual = ($ver['id'] == $versionId);
                        $txtVigente = ($ver['es_vigente']) ? ' (VIGENTE)' : '';
                        $classVigente = ($ver['es_vigente']) ? 'text-success fw-bold' : 'text-muted';
                    ?>
                        <option value="<?= $ver['id'] ?>" <?= $esActual ? 'selected' : '' ?> class="<?= $classVigente ?>">
                            V.<?= $ver['id'] ?> - <?= $fecha . $txtVigente ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <a href="curva_export.php?version_id=<?= $versionId ?>" class="btn btn-success btn-sm shadow-sm">
                <i class="bi bi-file-excel"></i> Excel
            </a>
            
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGraph">
                <i class="bi bi-graph-up"></i> Gráfico
            </button>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="collapse show mb-4" id="collapseGraph">
        <div class="card shadow-sm border-0">
            <div class="card-body" style="height: 250px;">
                <canvas id="chartCurva"></canvas>
            </div>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 table-vcenter text-center">
                <thead class="table-dark text-uppercase small">
                    <tr>
                        <th rowspan="2" style="vertical-align: middle;">Periodo</th>
                        <th colspan="4" class="bg-secondary text-white">Planificación</th>
                        <th colspan="3" class="bg-success">Certif. Básico / Anticipo</th>
                        <th colspan="3" class="bg-warning text-dark">Certif. Redeterminados</th>
                    </tr>
                    <tr>
                        <th class="bg-light text-dark">% Fis</th>
                        <th class="bg-light text-dark">Básico</th>
                        <th class="bg-light text-dark">Redet.</th>
                        <th class="bg-light text-dark fw-bold">Total</th>
                        
                        <th>ID</th><th>% Real</th><th>Monto</th>
                        <th>FRI</th><th>Monto</th><th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($itemsCurva as $item): 
                        $per = date('Y-m', strtotime($item['periodo']));
                        $esAnticipo = (stripos($item['concepto'], 'anticipo') !== false);
                        
                        // Buscamos certificados reales para este periodo
                        $certsBasicos = $mapaCertificados[$per]['ORDINARIO'] ?? [];
                        $certsRedet = $mapaCertificados[$per]['REDETERMINACION'] ?? [];
                        $certsAnticipo = $mapaCertificados[$per]['ANTICIPO'] ?? [];
                        
                        $planPct = $item['porcentaje_fisico'];
                        
                        // Valores de planificación (asegurar que existan las columnas en la BD)
                        $montoBase = $item['monto_base'] ?? 0;
                        $montoRedet = $item['redeterminacion'] ?? 0;
                        $montoNeto = $item['neto'] ?? ($montoBase + $montoRedet);
                    ?>
                    <tr>
                        <td class="fw-bold text-secondary"><?= date('m/Y', strtotime($per)) ?></td>
                        
                        <td class="col-plan fw-bold text-primary"><?= fmtPct($planPct) ?></td>
                        <td class="col-plan text-muted small">$ <?= fmtM($montoBase) ?></td>
                        <td class="col-plan text-muted small">$ <?= fmtM($montoRedet) ?></td>
                        <td class="col-plan fw-bold text-dark border-end" style="background-color: #e2e3e5;">$ <?= fmtM($montoNeto) ?></td>

                        <td class="col-basico">
                            <?php if($esAnticipo && !empty($certsAnticipo)): ?>
                                <?php foreach($certsAnticipo as $ca): ?><span class="nro-cert">CO Nº <?= $ca['nro_certificado'] ?></span><?php endforeach; ?>
                            <?php elseif(!empty($certsBasicos)): ?>
                                <?php foreach($certsBasicos as $cb): ?><div class="mb-1"><span class="nro-cert">CO Nº <?= $cb['nro_certificado'] ?></span></div><?php endforeach; ?>
                            <?php else: ?> - <?php endif; ?>
                        </td>
                        <td class="col-basico">
                            <?php if($esAnticipo): ?>
                                <span class="badge bg-secondary">Anticipo</span>
                            <?php elseif(!empty($certsBasicos)): ?>
                                <?php foreach($certsBasicos as $cb): ?><div class="fw-bold text-success mb-1"><?= fmtPct($cb['avance_fisico_mensual']) ?></div><?php endforeach; ?>
                            <?php else: ?> - <?php endif; ?>
                        </td>
                        <td class="col-basico border-end">
                            <?php if($esAnticipo && !empty($certsAnticipo)): ?>
                                <div class="small fw-bold">$ <?= fmtM($certsAnticipo[0]['monto_bruto']) ?></div>
                            <?php elseif(!empty($certsBasicos)): ?>
                                <?php foreach($certsBasicos as $cb): ?><div class="small fw-bold mb-1">$ <?= fmtM($cb['monto_basico']) ?></div><?php endforeach; ?>
                            <?php else: ?> - <?php endif; ?>
                        </td>

                        <td class="col-redet">
                            <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): echo "<div class='text-fri mb-1'>FRI: ".fmtFri($cr['fri'])."</div>"; endforeach; endif; ?>
                        </td>
                        <td class="col-redet">
                            <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): echo "<div class='small fw-bold text-dark mb-1'>$ ".fmtM($cr['monto_redeterminado'])."</div>"; endforeach; endif; ?>
                        </td>
                        <td class="col-redet">
                            <div class="d-flex justify-content-center gap-1 flex-wrap">
                                <?php if(!empty($certsRedet)): foreach($certsRedet as $cr): ?>
                                    <button class="btn btn-sm btn-link py-0 text-dark" onclick="abrirModalEdit(<?= $cr['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                <?php endforeach; endif; ?>
                                
                                <button class="btn btn-sm btn-outline-dark py-0" title="Cargar Redeterminación" onclick="abrirModalNew('REDETERMINACION', '<?= $per ?>', 0)"><i class="bi bi-plus"></i></button>
                            </div>

                            <div class="mt-1">
                                <?php if($esAnticipo): ?>
                                    <?php if(empty($certsAnticipo)): ?>
                                        <button class="btn btn-sm btn-outline-primary py-0" onclick="abrirModalNew('ANTICIPO', '<?= $per ?>', 0)">Cargar Ant.</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-link py-0" onclick="abrirModalEdit(<?= $certsAnticipo[0]['id'] ?>)"><i class="bi bi-pencil"></i></button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if(empty($certsBasicos)): ?>
                                        <button class="btn btn-sm btn-success py-0 shadow-sm" onclick="abrirModalNew('ORDINARIO', '<?= $per ?>', <?= $planPct ?>)"><i class="bi bi-plus-lg"></i> Cert.</button>
                                    <?php else: ?>
                                        <?php foreach($certsBasicos as $cb): ?>
                                            <button class="btn btn-sm btn-outline-success py-0 mb-1 d-block mx-auto" onclick="abrirModalEdit(<?= $cb['id'] ?>)"><i class="bi bi-pencil-square"></i></button>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Si necesitas que te reenvíe el bloque del Modal y Script dimelo, pero el problema de Excel se soluciona con el encabezado de arriba.
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
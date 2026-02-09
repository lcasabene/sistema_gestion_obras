<?php
// curva_ver.php
session_start();
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;

if (!$versionId) die("Versión no especificada.");

// 1. Obtener Cabecera (Versión + Obra)
$sqlHead = "SELECT v.*, o.denominacion 
            FROM curva_version v 
            JOIN obras o ON o.id = v.obra_id 
            WHERE v.id = ?";
$stmt = $pdo->prepare($sqlHead);
$stmt->execute([$versionId]);
$cabecera = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cabecera) die("Versión no encontrada.");

// 2. Obtener Ítems
$sqlItems = "SELECT * FROM curva_items WHERE version_id = ? ORDER BY periodo ASC";
$stmtI = $pdo->prepare($sqlItems);
$stmtI->execute([$versionId]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

// Helper para moneda
function fmt($val) {
    return number_format((float)$val, 2, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ver Curva V.<?= $cabecera['id'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .table-view th { background-color: #f8f9fa; font-size: 0.85rem; text-transform: uppercase; }
        .table-view td { font-size: 0.9rem; vertical-align: middle; }
        .num { font-family: 'Consolas', monospace; font-weight: 600; text-align: right; }
        .text-negativo { color: #dc3545; }
    </style>
</head>
<body class="bg-light">

<div class="container my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="text-primary mb-0">
                <i class="bi bi-file-earmark-spreadsheet"></i> <?= htmlspecialchars($cabecera['denominacion']) ?>
            </h4>
            <span class="badge bg-secondary">Versión #<?= $cabecera['id'] ?></span>
            <span class="text-muted small ms-2">Creada el: <?= date('d/m/Y H:i', strtotime($cabecera['fecha_creacion'])) ?></span>
        </div>
        <a href="curva_listado.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver al Listado
        </a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body bg-white">
            <div class="row text-center">
                <div class="col-md-3 border-end">
                    <small class="text-muted text-uppercase fw-bold">Monto Contrato</small>
                    <div class="fs-5 fw-bold">$ <?= fmt($cabecera['monto_presupuesto']) ?></div>
                </div>
                <div class="col-md-3 border-end">
                    <small class="text-muted text-uppercase fw-bold">Plazo</small>
                    <div class="fs-5"><?= $cabecera['plazo_meses'] ?> Meses</div>
                </div>
                <div class="col-md-3 border-end">
                    <small class="text-muted text-uppercase fw-bold">Estado</small>
                    <div>
                        <?php if($cabecera['es_vigente']): ?>
                            <span class="badge bg-success">VIGENTE</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Histórico</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted text-uppercase fw-bold">Observación</small>
                    <div class="small text-truncate"><?= htmlspecialchars($cabecera['observacion'] ?? '-') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 table-view">
                    <thead>
                        <tr class="text-center align-middle">
                            <th>Periodo</th>
                            <th>Concepto</th>
                            <th>% Físico</th>
                            <th>Monto Base</th>
                            <th>% Infl.</th>
                            <th>FRI</th>
                            <th>Redeterminación</th>
                            <th>Recupero</th>
                            <th class="bg-primary text-white">Neto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        <?php 
                        // Inicializar acumuladores
                        $tBase = 0; $tRedet = 0; $tRecup = 0; $tNeto = 0; $tPct = 0;
                        
                        foreach($items as $i): 
                            // Detectar si es anticipo
                            $esAnticipo = (stripos($i['concepto'], 'anticipo') !== false);

                            // Sumar a totales GLOBALES (Flujo de fondos)
                            $tRedet += $i['redeterminacion'];
                            $tRecup += $i['recupero'];
                            $tNeto += $i['neto'];
                            
                            // Sumar a totales de OBRA (Solo si NO es anticipo)
                            if (!$esAnticipo) {
                                $tPct += $i['porcentaje_fisico'];
                                $tBase += $i['monto_base']; // <--- ESTA ES LA CLAVE
                            }

                            $claseFila = $esAnticipo ? 'bg-anticipo' : '';
                            $claseConcepto = $esAnticipo ? 'text-anticipo' : '';
                        ?>
                        
                        <tr class="<?= $classRow ?>">
                            <td class="text-center fw-bold text-muted"><?= date('Y-m', strtotime($i['periodo'])) ?></td>
                            <td class="<?= $esAnticipo ? 'fw-bold text-primary' : '' ?>"><?= htmlspecialchars($i['concepto']) ?></td>
                            <td class="text-center"><?= number_format($i['porcentaje_fisico'], 2) ?>%</td>
                            <td class="num">$ <?= fmt($i['monto_base']) ?></td>
                            <td class="text-center text-muted"><?= number_format($i['indice_inflacion'], 2) ?></td>
                            <td class="text-center fw-bold"><?= number_format($i['fri'], 4) ?></td>
                            <td class="num text-success">$ <?= fmt($i['redeterminacion']) ?></td>
                            <td class="num text-danger">
                                <?= $i['recupero'] > 0 ? '- $ '.fmt($i['recupero']) : '-' ?>
                            </td>
                            <td class="num fw-bold <?= $i['neto'] < 0 ? 'text-negativo' : '' ?>">
                                $ <?= fmt($i['neto']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3" class="text-end pe-3">TOTALES:</td>
                            <td class="num">$ <?= fmt($tBase) ?></td>
                            <td></td>
                            <td></td>
                            <td class="num text-success">$ <?= fmt($tRedet) ?></td>
                            <td class="num text-danger">- $ <?= fmt($tRecup) ?></td>
                            <td class="num bg-primary text-white">$ <?= fmt($tNeto) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</div>

</body>
</html>
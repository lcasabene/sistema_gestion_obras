<?php
// curva_ver.php
session_start();
require_once __DIR__ . '/../../config/database.php';

$versionId = $_GET['version_id'] ?? 0;

if (!$versionId) die("Versión no especificada.");

// 1. Obtener Cabecera (Versión + Obra)
$sqlHead = "SELECT v.*, o.denominacion, o.monto_actualizado 
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

// --- HELPERS DE FORMATO (ARGENTINA) ---
function fmtMoneda($val) {
    return number_format((float)$val, 2, ',', '.');
}
function fmtPct($val) {
    return number_format((float)$val, 2, ',', '.');
}
function fmtFri($val) {
    return number_format((float)$val, 4, ',', '.');
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
        .table-view th { background-color: #f8f9fa; font-size: 0.85rem; text-transform: uppercase; text-align: center; }
        .table-view td { font-size: 0.9rem; vertical-align: middle; }
        .num { font-family: 'Consolas', monospace; font-weight: 600; text-align: right; }
        
        /* Colores específicos */
        .text-negativo { color: #dc3545; }
        .bg-anticipo { background-color: #fff3cd !important; } /* Amarillo suave */
        .text-anticipo { color: #856404; font-weight: bold; }
    </style>
</head>
<body class="bg-light">

<div class="container my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="text-primary mb-0">
                <i class="bi bi-file-earmark-spreadsheet-fill"></i> <?= htmlspecialchars($cabecera['denominacion']) ?>
            </h4>
            <div class="mt-1">
                <span class="badge bg-secondary">Versión #<?= $cabecera['id'] ?></span>
                <span class="text-muted small ms-2">
                    <i class="bi bi-clock"></i> Creada el <?= date('d/m/Y H:i', strtotime($cabecera['fecha_creacion'])) ?>
                </span>
            </div>
        </div>
        <a href="curva_listado.php" class="btn btn-outline-secondary shadow-sm">
            <i class="bi bi-arrow-left"></i> Volver al Listado
        </a>
    </div>

    <div class="card shadow-sm mb-4 border-top border-primary border-3">
        <div class="card-body bg-white">
            <div class="row text-center">
                <div class="col-md-3 border-end">
                    <small class="text-muted text-uppercase fw-bold">Monto Presupuesto</small>
                    <div class="fs-5 fw-bold text-dark">$ <?= fmtMoneda($cabecera['monto_presupuesto']) ?></div>
                </div>
                <div class="col-md-3 border-end">
                    <small class="text-muted text-uppercase fw-bold">Plazo Obra</small>
                    <div class="fs-5"><?= $cabecera['plazo_meses'] ?> Meses</div>
                </div>
                <div class="col-md-3 border-end">
                    <small class="text-muted text-uppercase fw-bold">Estado</small>
                    <div class="mt-1">
                        <?php if($cabecera['es_vigente']): ?>
                            <span class="badge bg-success px-3 py-2">VIGENTE</span>
                        <?php else: ?>
                            <span class="badge bg-secondary px-3 py-2">HISTÓRICO</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted text-uppercase fw-bold">Observación</small>
                    <div class="small text-truncate fst-italic mt-1"><?= htmlspecialchars($cabecera['observacion'] ?? 'Sin observaciones') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold text-uppercase text-secondary"><i class="bi bi-table"></i> Detalle Financiero Mensual</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 table-view">
                    <thead>
                        <tr>
                            <th style="width: 100px;">Periodo</th>
                            <th>Concepto</th>
                            <th>% Físico</th>
                            <th>Monto Base</th>
                            <th>% Infl.</th>
                            <th>FRI Acum.</th>
                            <th>Redeterminación</th>
                            <th>Recupero</th>
                            <th class="bg-primary text-white" style="width: 140px;">Neto a Cobrar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Inicializar acumuladores
                        $tBase = 0; $tRedet = 0; $tRecup = 0; $tNeto = 0; $tPct = 0;
                        
                        foreach($items as $i): 
                            // Sumar a totales
                            $tBase += $i['monto_base'];
                            $tRedet += $i['redeterminacion'];
                            $tRecup += $i['recupero'];
                            $tNeto += $i['neto'];
                            
                            // Detectar si es anticipo para estilos
                            $esAnticipo = (stripos($i['concepto'], 'anticipo') !== false);
                            
                            // Sumar porcentaje solo si NO es anticipo (para mostrar 100% real de obra)
                            if (!$esAnticipo) {
                                $tPct += $i['porcentaje_fisico'];
                            }

                            $claseFila = $esAnticipo ? 'bg-anticipo' : '';
                            $claseConcepto = $esAnticipo ? 'text-anticipo' : '';
                        ?>
                        <tr class="<?= $claseFila ?>">
                            <td class="text-center fw-bold text-muted bg-transparent">
                                <?= date('Y-m', strtotime($i['periodo'])) ?>
                            </td>
                            
                            <td class="<?= $claseConcepto ?> bg-transparent text-start">
                                <?= htmlspecialchars($i['concepto']) ?>
                            </td>
                            
                            <td class="text-center bg-transparent">
                                <?= fmtPct($i['porcentaje_fisico']) ?> %
                            </td>
                            
                            <td class="num bg-transparent">
                                $ <?= fmtMoneda($i['monto_base']) ?>
                            </td>
                            
                            <td class="text-center text-muted small bg-transparent">
                                <?= fmtPct($i['indice_inflacion']) ?>
                            </td>
                            
                            <td class="text-center fw-bold bg-transparent">
                                <?= fmtFri($i['fri']) ?>
                            </td>
                            
                            <td class="num text-success bg-transparent">
                                $ <?= fmtMoneda($i['redeterminacion']) ?>
                            </td>
                            
                            <td class="num text-danger bg-transparent">
                                <?= $i['recupero'] > 0 ? '- $ '.fmtMoneda($i['recupero']) : '-' ?>
                            </td>
                            
                            <td class="num fw-bold bg-transparent <?= $i['neto'] < 0 ? 'text-negativo' : 'text-primary' ?>">
                                $ <?= fmtMoneda($i['neto']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold border-top-3" style="font-size: 1rem;">
                        <tr>
                            <td colspan="2" class="text-end pe-3">TOTALES:</td>
                            <td class="text-center text-primary"><?= fmtPct($tPct) ?> %</td>
                            <td class="num">$ <?= fmtMoneda($tBase) ?></td>
                            <td></td>
                            <td></td>
                            <td class="num text-success">$ <?= fmtMoneda($tRedet) ?></td>
                            <td class="num text-danger">- $ <?= fmtMoneda($tRecup) ?></td>
                            <td class="num bg-primary text-white">$ <?= fmtMoneda($tNeto) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>
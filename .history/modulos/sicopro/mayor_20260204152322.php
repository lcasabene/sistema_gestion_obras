<?php
/**
 * Sistema de Gestión de Obras - Módulo SICOPRO
 * Archivo: mayor.php
 * Versión Final: Incluye movtrti, movtrnu, MOVEXPE, MOVALEX, fechas, unión con empresas y botón limpiar.
 */

require_once __DIR__ . '/../../config/database.php';

$resultados = [];
$totales = ['debe' => 0, 'haber' => 0];
$saldo_acumulado = 0;
$mostrar_tabla = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mostrar_tabla = true;
    $anio   = $_POST['anio'] ?? '';
    $cta    = $_POST['cuenta'] ?? '';
    $scta   = $_POST['subcuenta'] ?? '';
    $exp    = $_POST['expediente'] ?? '';
    $alc    = $_POST['alcance'] ?? '';
    $desde  = $_POST['f_desde'] ?? '';
    $hasta  = $_POST['f_hasta'] ?? '';

    // Parámetros base obligatorios
    $params = [
        ':cta_1' => $cta, ':scta_1' => $scta,
        ':cta_2' => $cta, ':scta_2' => $scta
    ];

    $f_debe = " AND MOVNCDE = :cta_1 AND MOVSCDE = :scta_1";
    $f_haber = " AND MOVNCCR = :cta_2 AND MOVSCCR = :scta_2";

    // Filtros dinámicos
    if (!empty($anio)) {
        $f_debe .= " AND MOVEJER = :anio_1";
        $f_haber .= " AND MOVEJER = :anio_2";
        $params[':anio_1'] = $anio; $params[':anio_2'] = $anio;
    }
    if (!empty($exp)) {
        $f_debe .= " AND MOVEXPE = :exp_1";
        $f_haber .= " AND MOVEXPE = :exp_2";
        $params[':exp_1'] = $exp; $params[':exp_2'] = $exp;
    }
    if (!empty($alc)) {
        $f_debe .= " AND MOVALEX = :alc_1";
        $f_haber .= " AND MOVALEX = :alc_2";
        $params[':alc_1'] = $alc; $params[':alc_2'] = $alc;
    }
    if (!empty($desde)) {
        $f_debe .= " AND MOVFEOP >= :desde_1";
        $f_haber .= " AND MOVFEOP >= :desde_2";
        $params[':desde_1'] = $desde; $params[':desde_2'] = $desde;
    }
    if (!empty($hasta)) {
        $f_debe .= " AND MOVFEOP <= :hasta_1";
        $f_haber .= " AND MOVFEOP <= :hasta_2";
        $params[':hasta_1'] = $hasta; $params[':hasta_2'] = $hasta;
    }

    $sql = "SELECT 
                sub.movfeop, sub.movtrti, sub.movtrnu, sub.MOVEXPE, sub.MOVALEX, 
                IFNULL(e.razon_social, sub.MOVPROV) as nombre_proveedor,
                e.cuit as cuit_proveedor,
                SUM(sub.DEBE) as DEBE, SUM(sub.HABER) as HABER
            FROM (
                SELECT movfeop, movtrti, movtrnu, MOVEXPE, MOVALEX, MOVPROV, MOVIMPO AS DEBE, 0 AS HABER
                FROM sicopro_principal
                WHERE 1=1 $f_debe
                UNION ALL
                SELECT movfeop, movtrti, movtrnu, MOVEXPE, MOVALEX, MOVPROV, 0 AS DEBE, MOVIMPO AS HABER
                FROM sicopro_principal
                WHERE 1=1 $f_haber
            ) AS sub
            LEFT JOIN empresas e ON sub.MOVPROV = e.codigo_proveedor
            GROUP BY sub.movfeop, sub.movtrti, sub.movtrnu, sub.MOVEXPE, sub.MOVALEX, sub.MOVPROV
            ORDER BY sub.movfeop ASC, sub.movtrnu ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resultados = $stmt->fetchAll();
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mayor Contable | SICOPRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --dark-primary: #2c3e50; }
        body { background-color: #f8f9fa; font-size: 0.82rem; }
        .nav-top { background: var(--dark-primary); color: white; padding: 12px 20px; }
        .saldo-pos { color: #157347; font-weight: bold; }
        .saldo-neg { color: #bb2d3b; font-weight: bold; }
    </style>
</head>
<body>

<div class="nav-top d-flex justify-content-between align-items-center mb-3 shadow-sm">
    <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>SICOPRO - Mayor Contable</h5>
    <a href="../../public/menu.php" class="btn btn-outline-light btn-sm"><i class="fas fa-undo me-1"></i> Volver</a>
</div>

<div class="container-fluid">
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="POST" id="formFiltros" class="row g-2">
                <div class="col-md-1">
                    <label class="small">Año</label>
                    <input type="number" name="anio" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['anio'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="small fw-bold text-primary">Cuenta *</label>
                    <input type="text" name="cuenta" class="form-control form-control-sm border-primary" required value="<?= htmlspecialchars($_POST['cuenta'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="small fw-bold text-primary">Subcta *</label>
                    <input type="text" name="subcuenta" class="form-control form-control-sm border-primary" required value="<?= htmlspecialchars($_POST['subcuenta'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="small">Expediente</label>
                    <input type="text" name="expediente" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['expediente'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="small">Alcance</label>
                    <input type="text" name="alcance" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['alcance'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="small">Fecha Desde</label>
                    <input type="date" name="f_desde" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['f_desde'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="small">Fecha Hasta</label>
                    <input type="date" name="f_hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['f_hasta'] ?? '') ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1 shadow-sm"><i class="fas fa-search"></i></button>
                    <a href="mayor.php" class="btn btn-secondary btn-sm shadow-sm" title="Limpiar Filtros"><i class="fas fa-eraser"></i></a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($mostrar_tabla): ?>
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <table id="tablaMayor" class="table table-striped table-hover table-sm w-100">
                <thead class="table-light text-center">
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>N° Trans.</th>
                        <th>Exp./Alc.</th>
                        <th>CUIT</th>
                        <th>Proveedor / Razón Social</th>
                        <th>Debe</th>
                        <th>Haber</th>
                        <th>Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $row): 
                        $saldo_acumulado += ($row['DEBE'] - $row['HABER']);
                        $totales['debe'] += $row['DEBE'];
                        $totales['haber'] += $row['HABER'];
                    ?>
                    <tr>
                        <td class="text-center"><?= date('d/m/Y', strtotime($row['movfeop'])) ?></td>
                        <td class="text-center small"><?= htmlspecialchars($row['movtrti']) ?></td>
                        <td class="text-center fw-bold"><?= htmlspecialchars($row['movtrnu']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($row['MOVEXPE']) ?>/<?= htmlspecialchars($row['MOVALEX']) ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($row['cuit_proveedor'] ?? 'S/D') ?></td>
                        <td><?= htmlspecialchars($row['nombre_proveedor']) ?></td>
                        <td class="text-end"><?= number_format($row['DEBE'], 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($row['HABER'], 2, ',', '.') ?></td>
                        <td class="text-end <?= ($saldo_acumulado >= 0) ? 'saldo-pos' : 'saldo-neg' ?>">
                            <?= number_format($saldo_acumulado, 2, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="6" class="text-end">TOTALES:</td>
                        <td class="text-end"><?= number_format($totales['debe'], 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($totales['haber'], 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($saldo_acumulado, 2, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaMayor').DataTable({
        dom: '<"d-flex justify-content-between align-items-center mb-2"Bf>rtip',
        buttons: [{
            extend: 'excelHtml5',
            text: '<i class="fas fa-file-excel me-1"></i> Excel',
            className: 'btn btn-success btn-sm',
            footer: true,
            title: 'Mayor_Contable_Cta_<?= htmlspecialchars($cta ?? "") ?>'
        }],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        pageLength: 50,
        order: [] 
    });
});
</script>
</body>
</html>
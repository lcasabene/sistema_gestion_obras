<?php
/**
 * Sistema de Gestión de Obras - Módulo SICOPRO
 * Archivo: mayor.php con vinculación a tabla Empresa
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

    $params = [
        ':cta_1' => $cta, ':scta_1' => $scta,
        ':cta_2' => $cta, ':scta_2' => $scta
    ];

    $filtro_debe = " AND MOVNCDE = :cta_1 AND MOVSCDE = :scta_1";
    $filtro_haber = " AND MOVNCCR = :cta_2 AND MOVSCCR = :scta_2";

    if (!empty($anio)) {
        $filtro_debe .= " AND MOVEJER = :anio_1";
        $filtro_haber .= " AND MOVEJER = :anio_2";
        $params[':anio_1'] = $anio;
        $params[':anio_2'] = $anio;
    }
    if (!empty($exp)) {
        $filtro_debe .= " AND MOVEXPE = :exp_1";
        $filtro_haber .= " AND MOVEXPE = :exp_2";
        $params[':exp_1'] = $exp;
        $params[':exp_2'] = $exp;
    }
    if (!empty($alc)) {
        $filtro_debe .= " AND MOVALEX = :alc_1";
        $filtro_haber .= " AND MOVALEX = :alc_2";
        $params[':alc_1'] = $alc;
        $params[':alc_2'] = $alc;
    }

    // Consulta con JOIN a la tabla empresa
    // Usamos IFNULL para que si no hay coincidencia, muestre el código original
    $sql = "SELECT 
                sub.movfeop, 
                sub.MOVEXPE, 
                sub.MOVALEX, 
                IFNULL(e.razon_social, sub.MOVPROV) as nombre_proveedor,
                e.cuit as cuit_proveedor,
                SUM(sub.DEBE) as DEBE, 
                SUM(sub.HABER) as HABER
            FROM (
                SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, MOVIMPO AS DEBE, 0 AS HABER, movtrnu
                FROM sicopro_principal
                WHERE 1=1 $filtro_debe
                
                UNION ALL
                
                SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, 0 AS DEBE, MOVIMPO AS HABER, movtrnu
                FROM sicopro_principal
                WHERE 1=1 $filtro_haber
            ) AS sub
            LEFT JOIN empresas e ON sub.MOVPROV = e.codigo_proveedor
            GROUP BY sub.movfeop, sub.MOVEXPE, sub.MOVALEX, sub.MOVPROV, sub.movtrnu
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
        body { background-color: #f8f9fa; font-size: 0.9rem; }
        .nav-top { background: var(--dark-primary); color: white; padding: 15px; }
        .table thead { background: #f1f4f7; }
        .saldo-pos { color: #157347; font-weight: bold; }
        .saldo-neg { color: #bb2d3b; font-weight: bold; }
    </style>
</head>
<body>

<div class="nav-top d-flex justify-content-between align-items-center mb-4 shadow-sm">
    <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Mayor Contable con Razón Social</h5>
    <a href="../../index.php" class="btn btn-outline-light btn-sm"><i class="fas fa-undo me-1"></i> Volver</a>
</div>

<div class="container-fluid">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" class="row g-2">
                <div class="col-md-2">
                    <label class="small">Año</label>
                    <input type="number" name="anio" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['anio'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold">Cuenta *</label>
                    <input type="text" name="cuenta" class="form-control form-control-sm" required value="<?= htmlspecialchars($_POST['cuenta'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold">Subcuenta *</label>
                    <input type="text" name="subcuenta" class="form-control form-control-sm" required value="<?= htmlspecialchars($_POST['subcuenta'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="small">Expediente</label>
                    <input type="text" name="expediente" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['expediente'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="small">Alcance</label>
                    <input type="text" name="alcance" class="form-control form-control-sm" value="<?= htmlspecialchars($_POST['alcance'] ?? '') ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i> Consultar</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($mostrar_tabla): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <table id="tablaMayor" class="table table-striped table-hover table-sm w-100">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Expediente/Alc</th>
                        <th>CUIT</th>
                        <th>Proveedor / Razón Social</th>
                        <th class="text-end">Debe</th>
                        <th class="text-end">Haber</th>
                        <th class="text-end">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $row): 
                        $saldo_acumulado += ($row['DEBE'] - $row['HABER']);
                        $totales['debe'] += $row['DEBE'];
                        $totales['haber'] += $row['HABER'];
                    ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($row['movfeop'])) ?></td>
                        <td><?= htmlspecialchars($row['MOVEXPE']) ?> / <?= htmlspecialchars($row['MOVALEX']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($row['cuit_proveedor'] ?? 'S/D') ?></td>
                        <td><?= htmlspecialchars($row['nombre_proveedor']) ?></td>
                        <td class="text-end"><?= number_format($row['DEBE'], 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($row['HABER'], 2, ',', '.') ?></td>
                        <td class="text-end <?= ($saldo_acumulado >= 0) ? 'saldo-pos' : 'saldo-neg' ?>">
                            <?= number_format($saldo_acumulado, 2, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-group-divider">
                    <tr class="fw-bold bg-light">
                        <td colspan="4" class="text-end">TOTALES:</td>
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
        dom: '<"d-flex justify-content-between mb-3"Bf>rtip',
        buttons: [{
            extend: 'excelHtml5',
            text: '<i class="fas fa-file-excel"></i> Exportar',
            className: 'btn btn-success btn-sm',
            footer: true
        }],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        pageLength: 25
    });
});
</script>
</body>
</html>
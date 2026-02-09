<?php
/**
 * Sistema de Gestión de Obras - Módulo SICOPRO
 * Archivo: mayor.php
 * Versión: Letra grande + Tooltip de Obra + Trazabilidad completa.
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

    $params = [
        ':cta_1' => $cta, ':scta_1' => $scta,
        ':cta_2' => $cta, ':scta_2' => $scta
    ];

    $f_debe = " AND MOVNCDE = :cta_1 AND MOVSCDE = :scta_1";
    $f_haber = " AND MOVNCCR = :cta_2 AND MOVSCCR = :scta_2";

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

    // Consulta con JOIN a Empresas y Obras (para el Tooltip)
    $sql = "SELECT 
                sub.movfeop, sub.movtrti, sub.movtrnu, sub.MOVEXPE, sub.MOVALEX, 
                IFNULL(e.razon_social, sub.MOVPROV) as nombre_proveedor,
                e.cuit as cuit_proveedor,
                o.denominacion as nombre_obra,
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
            LEFT JOIN obras o ON sub.MOVEXPE = o.expediente
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --dark-primary: #2c3e50; }
        body { background-color: #f8f9fa; font-size: 1rem; }
        .nav-top { background: var(--dark-primary); color: white; padding: 15px 25px; }
        .nav-top h4 { margin: 0; font-size: 1.5rem; }
        
        .table { font-size: 1.05rem; }
        .table thead th { font-size: 1.1rem; background-color: #f1f4f7; padding: 12px; }
        .saldo-pos { color: #157347; font-weight: bold; }
        .saldo-neg { color: #bb2d3b; font-weight: bold; }
        
        /* Estilo para el link del expediente con tooltip */
        .exp-link { 
            text-decoration: underline dotted; 
            color: #0d6efd; 
            cursor: help; 
            font-weight: bold;
        }

        .form-label { font-size: 1rem; font-weight: 600; color: #444; }
        .form-control { font-size: 1rem; padding: 8px 12px; }
    </style>
</head>
<body>

<div class="nav-top d-flex justify-content-between align-items-center mb-4 shadow border-bottom">
    <h4><i class="fas fa-file-invoice-dollar me-2"></i>SICOPRO - Mayor Contable</h4>
    <a href="../../public/menu.php" class="btn btn-outline-light"><i class="fas fa-undo me-1"></i> Volver al Menú</a>
</div>

<div class="container-fluid px-4">
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-1">
                    <label class="form-label">Año</label>
                    <input type="number" name="anio" class="form-control" value="<?= htmlspecialchars($_POST['anio'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label text-primary">Cuenta *</label>
                    <input type="text" name="cuenta" class="form-control border-primary shadow-sm" required value="<?= htmlspecialchars($_POST['cuenta'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label text-primary">Subcta *</label>
                    <input type="text" name="subcuenta" class="form-control border-primary shadow-sm" required value="<?= htmlspecialchars($_POST['subcuenta'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">Expediente</label>
                    <input type="text" name="expediente" class="form-control" value="<?= htmlspecialchars($_POST['expediente'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">Alcance</label>
                    <input type="text" name="alcance" class="form-control" value="<?= htmlspecialchars($_POST['alcance'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Desde</label>
                    <input type="date" name="f_desde" class="form-control" value="<?= htmlspecialchars($_POST['f_desde'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="f_hasta" class="form-control" value="<?= htmlspecialchars($_POST['f_hasta'] ?? '') ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-search me-1"></i> Consultar</button>
                    <a href="mayor.php" class="btn btn-secondary" title="Limpiar"><i class="fas fa-eraser"></i></a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($mostrar_tabla): ?>
    <div class="card shadow border-0 mb-5">
        <div class="card-body p-4">
            <table id="tablaMayor" class="table table-striped table-hover w-100 border">
                <thead class="table-light">
                    <tr>
                        <th class="text-center">Fecha</th>
                        <th class="text-center">Tipo</th>
                        <th class="text-center">N° Trans.</th>
                        <th class="text-center">Exp./Alc.</th>
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
                        
                        // Preparar texto del tooltip
                        $info_obra = !empty($row['nombre_obra']) ? "Obra: " . htmlspecialchars($row['nombre_obra']) : "Obra no identificada";
                    ?>
                    <tr>
                        <td class="text-center"><?= date('d/m/Y', strtotime($row['movfeop'])) ?></td>
                        <td class="text-center"><strong><?= htmlspecialchars($row['movtrti']) ?></strong></td>
                        <td class="text-center fw-bold text-primary"><?= htmlspecialchars($row['movtrnu']) ?></td>
                        <td class="text-center">
                            <span class="exp-link" 
                                  data-bs-toggle="tooltip" 
                                  data-bs-placement="top" 
                                  title="<?= $info_obra ?>">
                                <?= htmlspecialchars($row['MOVEXPE']) ?>/<?= htmlspecialchars($row['MOVALEX']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($row['nombre_proveedor']) ?></div>
                            <div class="text-muted small">CUIT: <?= htmlspecialchars($row['cuit_proveedor'] ?? 'S/D') ?></div>
                        </td>
                        <td class="text-end fw-bold"><?= number_format($row['DEBE'], 2, ',', '.') ?></td>
                        <td class="text-end fw-bold"><?= number_format($row['HABER'], 2, ',', '.') ?></td>
                        <td class="text-end <?= ($saldo_acumulado >= 0) ? 'saldo-pos' : 'saldo-neg' ?>">
                            <?= number_format($saldo_acumulado, 2, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr style="font-size: 1.2rem;">
                        <td colspan="5" class="text-end text-uppercase">Totales:</td>
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

<script>
$(document).ready(function() {
    // Inicializar Tooltips de Bootstrap
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle=\"tooltip\"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    $('#tablaMayor').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        pageLength: 50,
        order: [] 
    });
});
</script>
</body>
</html>
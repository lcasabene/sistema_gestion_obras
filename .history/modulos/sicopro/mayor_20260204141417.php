<?php
require_once __DIR__ . '/../../config/database.php';

// Inicializar variables
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

    // Parámetros para PDO (Duplicados para el UNION ALL debido a ATTR_EMULATE_PREPARES => false)
    $params = [
        ':cta_1' => $cta, ':scta_1' => $scta,
        ':cta_2' => $cta, ':scta_2' => $scta
    ];

    // Construcción de filtros dinámicos
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

    $sql = "SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, SUM(DEBE) as DEBE, SUM(HABER) as HABER, movtrnu
            FROM (
                SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, MOVIMPO AS DEBE, 0 AS HABER, movtrnu
                FROM sicopro_principal
                WHERE 1=1 $filtro_debe
                
                UNION ALL
                
                SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, 0 AS DEBE, MOVIMPO AS HABER, movtrnu
                FROM sicopro_principal
                WHERE 1=1 $filtro_haber
            ) AS subconsulta
            GROUP BY movfeop, MOVEXPE, MOVALEX, MOVPROV, movtrnu
            ORDER BY movfeop ASC, movtrnu ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resultados = $stmt->fetchAll();
    } catch (PDOException $e) {
        die("Error en la consulta: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mayor Contable | SICOPRO</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --primary-dark: #1a252f; --accent-blue: #3498db; }
        body { background-color: #f4f7f6; font-size: 0.95rem; }
        .navbar-custom { background-color: var(--primary-dark); color: white; padding: 1rem; margin-bottom: 2rem; }
        .card { border: none; border-radius: 10px; }
        .card-header { background-color: #fff; border-bottom: 2px solid #f0f0f0; font-weight: bold; color: var(--primary-dark); }
        .table thead th { background-color: #f8f9fa; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; color: #666; }
        .saldo-pos { color: #27ae60; font-weight: bold; }
        .saldo-neg { color: #e74c3c; font-weight: bold; }
        .total-row { background-color: #eee !important; font-weight: bold; }
    </style>
</head>
<body>

<nav class="navbar-custom d-flex justify-content-between align-items-center shadow-sm">
    <span class="h4 mb-0"><i class="fas fa-calculator me-2"></i> SICOPRO - Mayor Contable</span>
    <a href="../../index.php" class="btn btn-outline-light btn-sm">
        <i class="fas fa-home me-1"></i> Menú Principal
    </a>
</nav>

<div class="container-fluid">
    <div class="card shadow-sm mb-4">
        <div class="card-header"><i class="fas fa-filter me-1"></i> Parámetros de Consulta</div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small">Ejercicio (MOVEJER)</label>
                    <input type="number" name="anio" class="form-control" placeholder="Ej: 2026" value="<?= htmlspecialchars($_POST['anio'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Cuenta *</label>
                    <input type="text" name="cuenta" class="form-control border-primary" required value="<?= htmlspecialchars($_POST['cuenta'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Subcuenta *</label>
                    <input type="text" name="subcuenta" class="form-control border-primary" required value="<?= htmlspecialchars($_POST['subcuenta'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Expediente</label>
                    <input type="text" name="expediente" class="form-control" value="<?= htmlspecialchars($_POST['expediente'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Alcance</label>
                    <input type="text" name="alcance" class="form-control" value="<?= htmlspecialchars($_POST['alcance'] ?? '') ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 shadow-sm">
                        <i class="fas fa-sync-alt me-1"></i> Procesar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($mostrar_tabla): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <table id="tablaMayor" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Expediente</th>
                        <th>Alcance</th>
                        <th>Proveedor / Detalle</th>
                        <th class="text-end">Debe</th>
                        <th class="text-end">Haber</th>
                        <th class="text-end">Saldo Acum.</th>
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
                        <td><?= htmlspecialchars($row['MOVEXPE']) ?></td>
                        <td><?= htmlspecialchars($row['MOVALEX']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($row['MOVPROV']) ?></td>
                        <td class="text-end"><?= number_format($row['DEBE'], 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($row['HABER'], 2, ',', '.') ?></td>
                        <td class="text-end <?= ($saldo_acumulado >= 0) ? 'saldo-pos' : 'saldo-neg' ?>">
                            <?= number_format($saldo_acumulado, 2, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="4" class="text-end text-uppercase">Totales del Período:</td>
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
    var table = $('#tablaMayor').DataTable({
        dom: '<"d-flex justify-content-between align-items-center mb-3"Bf>rtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel me-1"></i> Excel',
                className: 'btn btn-success btn-sm px-3',
                title: 'Mayor_Contable_<?= htmlspecialchars($cta ?? "") ?>',
                footer: true // Exporta también los totales
            }
        ],
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        pageLength: 50,
        order: [] // Mantiene el orden cronológico que trae SQL
    });
});
</script>

</body>
</html>
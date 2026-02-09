<?php
require_once 'database.php'; // Usamos tu conexión PDO centralizada

// Inicializar variables
$resultados = [];
$saldo_acumulado = 0;
$mostrar_tabla = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mostrar_tabla = true;
    $anio   = $_POST['anio'] ?? '';
    $cta    = $_POST['cuenta'] ?? '';
    $scta   = $_POST['subcuenta'] ?? '';
    $exp    = $_POST['expediente'] ?? '';
    $alc    = $_POST['alcance'] ?? '';

    // Construcción dinámica de la consulta
    $params = [':cta' => $cta, ':scta' => $scta];
    $filtro_sql = " AND MOVNCDE = :cta AND MOVSCDE = :scta";
    $filtro_sql_cr = " AND MOVNCCR = :cta AND MOVSCCR = :scta";

    if (!empty($anio)) {
        $filtro_sql .= " AND MOVEJER = :anio";
        $filtro_sql_cr .= " AND MOVEJER = :anio";
        $params[':anio'] = $anio;
    }
    if (!empty($exp)) {
        $filtro_sql .= " AND MOVEXPE = :exp";
        $filtro_sql_cr .= " AND MOVEXPE = :exp";
        $params[':exp'] = $exp;
    }
    if (!empty($alc)) {
        $filtro_sql .= " AND MOVALEX = :alc";
        $filtro_sql_cr .= " AND MOVALEX = :alc";
        $params[':alc'] = $alc;
    }

    $sql = "SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, SUM(DEBE) as DEBE, SUM(HABER) as HABER, movtrnu
            FROM (
                SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, MOVIMPO AS DEBE, 0 AS HABER, movtrnu
                FROM sicopro_principal
                WHERE 1=1 $filtro_sql
                
                UNION ALL
                
                SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, 0 AS DEBE, MOVIMPO AS HABER, movtrnu
                FROM sicopro_principal
                WHERE 1=1 $filtro_sql_cr
            ) AS subconsulta
            GROUP BY movfeop, MOVEXPE, MOVALEX, MOVPROV, movtrnu
            ORDER BY movfeop ASC, movtrnu ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll();
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
        :root { --primary-color: #2c3e50; --secondary-color: #34495e; }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card-header { background-color: var(--primary-color); color: white; }
        .btn-export { margin-bottom: 15px; }
        .table-hover tbody tr:hover { background-color: #f1f4f9; }
        .saldo-positivo { color: #198754; font-weight: bold; }
        .saldo-negativo { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-secondary"><i class="fas fa-book me-2"></i>Mayor Contable</h2>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Regresar al Menú
        </a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header">Filtros de Búsqueda</div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label text-muted small">Ejercicio (Opcional)</label>
                    <input type="number" name="anio" class="form-control" value="<?= htmlspecialchars($_POST['anio'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Cuenta *</label>
                    <input type="text" name="cuenta" class="form-control" required value="<?= htmlspecialchars($_POST['cuenta'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Subcuenta *</label>
                    <input type="text" name="subcuenta" class="form-control" required value="<?= htmlspecialchars($_POST['subcuenta'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label text-muted small">Expediente</label>
                    <input type="text" name="expediente" class="form-control" value="<?= htmlspecialchars($_POST['expediente'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label text-muted small">Alcance</label>
                    <input type="text" name="alcance" class="form-control" value="<?= htmlspecialchars($_POST['alcance'] ?? '') ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i> Consultar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($mostrar_tabla): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <table id="tablaMayor" class="table table-striped table-hover table-bordered w-100">
                <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Expediente</th>
                        <th>Alcance</th>
                        <th>Proveedor / Detalle</th>
                        <th>Debe</th>
                        <th>Haber</th>
                        <th>Saldo Acum.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $row): 
                        $saldo_acumulado += ($row['DEBE'] - $row['HABER']);
                    ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($row['movfeop'])) ?></td>
                        <td><?= htmlspecialchars($row['MOVEXPE']) ?></td>
                        <td><?= htmlspecialchars($row['MOVALEX']) ?></td>
                        <td class="small"><?= htmlspecialchars($row['MOVPROV']) ?></td>
                        <td class="text-end"><?= number_format($row['DEBE'], 2, ',', '.') ?></td>
                        <td class="text-end"><?= number_format($row['HABER'], 2, ',', '.') ?></td>
                        <td class="text-end <?= ($saldo_acumulado >= 0) ? 'saldo-positivo' : 'saldo-negativo' ?>">
                            <?= number_format($saldo_acumulado, 2, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
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
        dom: '<"d-flex justify-content-between"fB>rtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel"></i> Exportar a Excel',
                className: 'btn btn-success btn-sm',
                title: 'Mayor Contable - Cuenta <?= htmlspecialchars($cta ?? "") ?>'
            }
        ],
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        pageLength: 25,
        order: [[0, 'asc']] // Ordenar por fecha por defecto
    });
});
</script>

</body>
</html>
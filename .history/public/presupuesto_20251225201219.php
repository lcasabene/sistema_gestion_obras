<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

// 1. Obtener todas las versiones disponibles
$stmtVersiones = $pdo->query("SELECT DISTINCT fecha_carga FROM presupuesto_ejecucion ORDER BY fecha_carga DESC");
$versiones = $stmtVersiones->fetchAll(PDO::FETCH_COLUMN);

// 2. Determinar Versión Actual y Versión de Referencia
$version_actual = $_GET['version'] ?? ($versiones[0] ?? null);
$version_ref = $_GET['version_ref'] ?? null;

// 3. Consultar registros de la versión actual
$registros = [];
if ($version_actual) {
    // Usamos el ID de imputación como clave para poder comparar filas idénticas entre versiones
    $stmt = $pdo->prepare("SELECT * FROM presupuesto_ejecucion WHERE fecha_carga = ? ORDER BY id ASC");
    $stmt->execute([$version_actual]);
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($res as $row) {
        // Creamos una clave única basada en los clasificadores para la comparativa
        $clave = $row['inci'].'-'.$row['ppal'].'-'.$row['ppar'].'-'.$row['fufi'].'-'.$row['denominacion1'];
        $registros[$clave] = $row;
    }
}

// 4. Consultar registros de la versión de referencia
$registros_ref = [];
if ($version_ref) {
    $stmtRef = $pdo->prepare("SELECT * FROM presupuesto_ejecucion WHERE fecha_carga = ? ORDER BY id ASC");
    $stmtRef->execute([$version_ref]);
    $resRef = $stmtRef->fetchAll(PDO::FETCH_ASSOC);
    foreach($resRef as $row) {
        $clave = $row['inci'].'-'.$row['ppal'].'-'.$row['ppar'].'-'.$row['fufi'].'-'.$row['denominacion1'];
        $registros_ref[$clave] = $row;
    }
}

include __DIR__ . '/_header.php';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

<style>
    .text-xsmall { font-size: 0.72rem; line-height: 1.1; }
    .badge-presupuesto { font-family: monospace; font-size: 0.82rem; }
    .col-denominacion { min-width: 300px; max-width: 450px; }
    .deno-3 { font-size: 0.7rem; color: #6c757d; border-top: 1px dashed #dee2e6; margin-top: 4px; padding-top: 4px; }
    .diff-pos { color: #dc3545; font-size: 0.75rem; font-weight: bold; } 
    .diff-neg { color: #198754; font-size: 0.75rem; font-weight: bold; }
    .fila-alerta { background-color: rgba(220, 53, 69, 0.08) !important; }
</style>

<div class="container-fluid mt-4">
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body bg-light border-bottom">
            <form id="formFiltros" method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Versión Principal:</label>
                    <select name="version" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($versiones as $v): ?>
                            <option value="<?= $v ?>" <?= ($v == $version_actual) ? 'selected' : '' ?>>
                                <?= date('d/m/Y', strtotime($v)) ?> <?= ($v == $versiones[0]) ? '(Última)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-primary">Comparar con Fecha:</label>
                    <select name="version_ref" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">-- Sin comparativa --</option>
                        <?php foreach ($versiones as $v): ?>
                            <?php if($v != $version_actual): ?>
                            <option value="<?= $v ?>" <?= ($v == $version_ref) ? 'selected' : '' ?>>
                                <?= date('d/m/Y', strtotime($v)) ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 text-end">
                    <div class="btn-group shadow-sm">
                        <button type="button" id="btnFiltroCritico" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-filter"></i> Ver Críticos (≥ 80%)
                        </button>
                        <a href="importar.php" class="btn btn-success btn-sm">Importar</a>
                        <a href="menu.php" class="btn btn-primary btn-sm">Menú</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaPresupuesto" class="table table-hover table-sm mb-0" style="width:100%">
                    <thead class="table-dark">
                        <tr>
                            <th>Inc-Ppal-Ppar</th>
                            <th>FuFi</th>
                            <th class="col-denominacion">Denominación</th>
                            <th class="text-end">Crédito Total</th>
                            <th class="text-end">Ejecutado</th>
                            <th class="text-end">Diferencia</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center no-export">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $clave => $r): 
                            $cred_total = (float)$r['monto_def'] + (float)$r['monto_reep'];
                            $ejec = (float)$r['monto_ejec'];
                            $pct = ($cred_total > 0) ? ($ejec / $cred_total) * 100 : 0;
                            $es_critico = ($pct >= 80);
                            
                            $diff_html = "";
                            if ($version_ref && isset($registros_ref[$clave])) {
                                $ejec_ref = (float)$registros_ref[$clave]['monto_ejec'];
                                $diff = $ejec - $ejec_ref;
                                if ($diff > 0) $diff_html = "<div class='diff-pos'>▲ $".number_format($diff,0,',','.')."</div>";
                                if ($diff < 0) $diff_html = "<div class='diff-neg'>▼ $".number_format(abs($diff),0,',','.')."</div>";
                            }
                        ?>
                        <tr class="<?= $es_critico ? 'fila-alerta' : '' ?>">
                            <td class="text-center">
                                <span class="badge bg-secondary badge-presupuesto"><?= "{$r['inci']}-{$r['ppal']}-{$r['ppar']}" ?></span>
                            </td>
                            <td class="text-center fw-bold text-primary"><?= $r['fufi'] ?></td>
                            <td class="col-denominacion">
                                <div class="fw-bold small"><?= $r['denominacion1'] ?></div>
                                <div class="deno-3 small"><?= $r['denominacion3'] ?></div>
                            </td>
                            <td class="text-end fw-bold"><?= number_format($cred_total, 2, ',', '.') ?></td>
                            <td class="text-end <?= $es_critico ? 'text-danger fw-bold' : '' ?>">
                                <?= number_format($ejec, 2, ',', '.') ?>
                            </td>
                            <td class="text-end bg-light"><?= $diff_html ?></td>
                            <td class="text-center">
                                <span class="badge <?= $es_critico ? 'bg-danger' : 'bg-info text-dark' ?>">
                                    <?= number_format($pct, 0) ?>% <?= $es_critico ? 'CRITICO' : '' ?>
                                </span>
                            </td>
                            <td class="text-center no-export">
                                <button type="button" class="btn btn-outline-info btn-sm btn-detalle" data-datos='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>'>
                                    <i class="bi bi-search"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">Ficha Técnica</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light"><div id="listaDetalles" class="row g-2"></div></div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<script>
$(document).ready(function() {
    // Inicializamos DataTables
    var table = $('#tablaPresupuesto').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        pageLength: 50,
        dom: '<"d-flex justify-content-between mb-2"Bf>rtip',
        buttons: [
            { extend: 'excelHtml5', text: 'Excel', className: 'btn btn-success btn-sm', exportOptions: { columns: ':not(.no-export)' } },
            { extend: 'pdfHtml5', text: 'PDF', className: 'btn btn-danger btn-sm', orientation: 'landscape', exportOptions: { columns: ':not(.no-export)' } }
        ]
    });

    // Lógica del botón de filtro Crítico
    $('#btnFiltroCritico').on('click', function() {
        if ($(this).hasClass('active')) {
            $(this).removeClass('active').addClass('btn-outline-danger').text('Ver Críticos (≥ 80%)');
            table.column(6).search('').draw(); // Limpia el filtro en la columna 6 (Estado)
        } else {
            $(this).addClass('active').removeClass('btn-outline-danger').addClass('btn-danger').text('Ver Todos');
            table.column(6).search('CRITICO').draw(); // Busca el texto 'CRITICO' en la columna 6
        }
    });

    $('.btn-detalle').on('click', function() {
        const datos = $(this).data('datos');
        let html = '';
        for (const [key, value] of Object.entries(datos)) {
            if(key === 'id') continue;
            html += `<div class="col-md-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body p-2"><label class="text-muted fw-bold small text-uppercase" style="font-size:0.6rem;">${key.replace(/_/g,' ')}</label><div class="small text-dark text-break">${value || '-'}</div></div></div></div>`;
        }
        $('#listaDetalles').html(html);
        new bootstrap.Modal(document.getElementById('modalDetalle')).show();
    });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
<?php
// obras_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// --- FILTROS (PHP - Server Side) ---

// 1. Capturamos los inputs. Ahora esperamos Arrays (lista de IDs)
$tipos_sel = isset($_GET['tipo']) ? $_GET['tipo'] : []; 
$estados_sel = isset($_GET['estado']) ? $_GET['estado'] : [];

// Asegurarnos que sean arrays (por si viene vacío o mal formado)
if (!is_array($tipos_sel)) $tipos_sel = [];
if (!is_array($estados_sel)) $estados_sel = [];

// Limpiamos arrays (solo enteros válidos)
$tipos_sel = array_map('intval', $tipos_sel);
$estados_sel = array_map('intval', $estados_sel);

// Construcción del WHERE dinámico
$where = " WHERE o.activo = 1 ";
$params = [];

// Filtro Tipo (Múltiple)
if (!empty($tipos_sel)) {
    $in  = str_repeat('?,', count($tipos_sel) - 1) . '?';
    $where .= " AND o.tipo_obra_id IN ($in) ";
    $params = array_merge($params, $tipos_sel);
}

// Filtro Estado (Múltiple)
if (!empty($estados_sel)) {
    $in  = str_repeat('?,', count($estados_sel) - 1) . '?';
    $where .= " AND o.estado_obra_id IN ($in) ";
    $params = array_merge($params, $estados_sel);
}

// --- CONSULTA MAESTRA ---
// Se agregan: o.monto_original, o.fecha_fin_prevista (Plazo) y subconsulta para ultimo_periodo
$sql = "
  SELECT 
    o.id, o.denominacion, o.monto_actualizado, o.monto_original, o.expediente, o.fecha_fin_prevista,
    e.nombre AS estado,
    
    -- Datos de Curva (Teórico)
    cv.id AS version_id,
    
    -- Datos Reales (Certificados)
    (SELECT COALESCE(SUM(monto_neto_pagar),0) FROM certificados WHERE obra_id=o.id AND estado='APROBADO') as total_certificado,
    (SELECT avance_fisico_acumulado FROM certificados WHERE obra_id=o.id AND estado='APROBADO' ORDER BY nro_certificado DESC LIMIT 1) as avance_real_pct,
    (SELECT periodo FROM certificados WHERE obra_id=o.id AND estado='APROBADO' ORDER BY nro_certificado DESC LIMIT 1) as ultimo_periodo,
    
    -- Datos Teóricos (Curva S)
    (SELECT porcentaje_fisico FROM curva_items ci 
     WHERE ci.version_id = cv.id AND ci.periodo <= DATE_FORMAT(NOW(), '%Y-%m-01') 
     ORDER BY ci.periodo DESC LIMIT 1) as avance_teorico_pct

  FROM obras o
  JOIN estados_obra e ON o.estado_obra_id = e.id
  LEFT JOIN curva_version cv ON (cv.obra_id = o.id AND cv.es_vigente = 1)
  $where
  ORDER BY o.id DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $obras = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='container my-4'><div class='alert alert-danger'><strong>Error Base de Datos:</strong> " . $e->getMessage() . "</div></div>";
    $obras = [];
}

// Listas para filtros select
$tipos = $pdo->query("SELECT id, nombre FROM tipos_obra WHERE activo=1")->fetchAll();
$estados = $pdo->query("SELECT id, nombre FROM estados_obra WHERE activo=1")->fetchAll();
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

<style>
    .btn-action { width: 38px; height: 34px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.15); }
    .table-vcenter td { vertical-align: middle; }
    .select2-container--bootstrap-5 .select2-selection { min-height: 31px; padding-top: 2px; }
    .text-xs { font-size: 0.75rem; }
</style>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-0"><i class="bi bi-speedometer2"></i> Tablero de Control</h3>
            <span class="text-muted small">Listado general de obras y estado de avance</span>
        </div>
        <div>
            <a class="btn btn-secondary shadow-sm" href="../../public/menu.php"><i class="bi bi-arrow-left"></i> Volver</a>
            <a class="btn btn-primary fw-bold shadow-sm" href="obras_form.php"><i class="bi bi-plus-lg"></i> Nueva Obra</a>
        </div>
    </div>

    <div class="card mb-3 border-0 bg-light shadow-sm">
        <div class="card-body py-3">
            <form class="row g-3 align-items-end" method="get">
                <div class="col-auto d-flex align-items-center">
                   <span class="fw-bold text-secondary"><i class="bi bi-funnel"></i> Filtros:</span>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1">Tipos de Obra</label>
                    <select name="tipo[]" class="form-select select2-multiple" multiple>
                        <?php foreach($tipos as $t): 
                            $selected = in_array($t['id'], $tipos_sel) ? 'selected' : '';
                        ?>
                            <option value="<?= $t['id'] ?>" <?= $selected ?>><?= $t['nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1">Estados</label>
                    <select name="estado[]" class="form-select select2-multiple" multiple>
                        <?php foreach($estados as $est): 
                            $selected = in_array($est['id'], $estados_sel) ? 'selected' : '';
                        ?>
                            <option value="<?= $est['id'] ?>" <?= $selected ?>><?= $est['nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-search"></i> Aplicar
                    </button>
                    <?php if(!empty($tipos_sel) || !empty($estados_sel)): ?>
                        <a href="obras_listado.php" class="btn btn-outline-secondary" title="Limpiar Filtros"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive p-3">
                <table id="tablaObras" class="table table-hover align-middle mb-0 table-vcenter" style="font-size: 0.95rem; width:100%">
                    <thead class="table-light text-secondary small text-uppercase">
                        <tr>
                            <th>Obra / Plazo</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center" style="width: 20%;">Avance Físico</th>
                            <th class="text-end">Finanzas (Orig. / Act.)</th>
                            <th class="text-center" style="width: 160px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($obras as $o): 
                            // Cálculos
                            $real = (float)$o['avance_real_pct'];
                            $teorico = (float)$o['avance_teorico_pct'];
                            
                            // Semáforo Físico
                            $desvio = ($o['version_id']) ? ($real - $teorico) : 0;
                            $colorDesvio = 'success'; $txtDesvio = 'En Curva';
                            
                            if ($o['version_id']) {
                                if($desvio < -15) { $colorDesvio = 'danger'; $txtDesvio = 'Atraso Grave'; }
                                elseif($desvio < -5) { $colorDesvio = 'warning'; $txtDesvio = 'Atraso Leve'; }
                                elseif($desvio > 5) { $colorDesvio = 'primary'; $txtDesvio = 'Adelantada'; }
                            } else {
                                $colorDesvio = 'secondary'; $txtDesvio = 'S/ Curva';
                            }
                            
                            // Formato de Fecha Fin (usando el campo correcto)
                            $fechaFin = $o['fecha_fin_prevista'] ? date('d/m/Y', strtotime($o['fecha_fin_prevista'])) : '-';
                            // Formato Ultimo Periodo
                            $ultimoPer = $o['ultimo_periodo'] ? date('m/Y', strtotime($o['ultimo_periodo'] . '-01')) : '-';
                        ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark mb-1"><?= htmlspecialchars($o['denominacion']) ?></div>
                                <div class="d-flex gap-2">
                                    <span class="badge bg-light text-secondary border text-xs">Exp: <?= htmlspecialchars($o['expediente']) ?></span>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info text-xs" title="Plazo Finalización">
                                        <i class="bi bi-calendar-event"></i> Fin: <?= $fechaFin ?>
                                    </span>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge rounded-pill bg-white text-dark border shadow-sm"><?= $o['estado'] ?></span>
                            </td>
                            
                            <td>
                                <div class="d-flex justify-content-between small mb-1 fw-bold">
                                    <span class="text-success" title="Avance Acumulado">R: <?= number_format($real,2) ?>%</span>
                                    <span class="text-muted" title="Avance Teórico">P: <?= number_format($teorico,2) ?>%</span>
                                </div>
                                
                                <div class="progress shadow-sm mb-1" style="height: 6px; background-color: #e9ecef;">
                                    <div class="progress-bar bg-<?= $colorDesvio ?>" style="width: <?= min($real, 100) ?>%"></div>
                                    <div class="position-absolute border-start border-dark border-2" style="left: <?= min($teorico, 100) ?>%; height: 6px; opacity: 0.6;" title="Planificado"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-<?= $colorDesvio ?> fw-bold text-xs">
                                        <?= $txtDesvio ?> (<?= ($desvio>0?'+':'').number_format($desvio,1) ?>%)
                                    </small>
                                    <small class="text-muted text-xs bg-light border px-1 rounded" title="Último Periodo Medido">
                                        <i class="bi bi-clock-history"></i> <?= $ultimoPer ?>
                                    </small>
                                </div>
                            </td>

                            <td class="text-end small">
                                <div class="text-muted text-xs mb-1" title="Monto Original">Orig: $ <?= number_format($o['monto_original'], 0, ',', '.') ?></div>
                                <div class="fw-bold text-dark mb-1" title="Monto Actualizado">Act: $ <?= number_format($o['monto_actualizado'], 0, ',', '.') ?></div>
                                <div class="text-success text-xs bg-success bg-opacity-10 px-1 rounded d-inline-block">
                                    Pagado: $ <?= number_format($o['total_certificado'], 0, ',', '.') ?>
                                </div>
                            </td>

                            <td class="text-center">
                                <div class="btn-group" role="group">
                                    <a href="../certificados/certificados_form.php?obra_id=<?= $o['id'] ?>" 
                                       class="btn btn-success btn-action shadow-sm text-white" 
                                       title="Nuevo Certificado" data-bs-toggle="tooltip">
                                       <i class="bi bi-plus-lg fs-6"></i>
                                    </a>

                                    <?php if($o['version_id']): ?>
                                        <a href="../curva/curva_ver.php?version_id=<?= $o['version_id'] ?>" 
                                           class="btn btn-primary btn-action shadow-sm" 
                                           title="Ver Curva y Avance" data-bs-toggle="tooltip">
                                           <i class="bi bi-graph-up fs-6"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="../curva/curva_form_generate.php?obra_id=<?= $o['id'] ?>" 
                                           class="btn btn-warning btn-action shadow-sm text-dark" 
                                           title="Generar Curva Base" data-bs-toggle="tooltip">
                                           <i class="bi bi-magic fs-6"></i>
                                        </a>
                                    <?php endif; ?>

                                    <a href="obras_form.php?id=<?= $o['id'] ?>" 
                                       class="btn btn-dark btn-action shadow-sm" 
                                       title="Editar Datos Obra" data-bs-toggle="tooltip">
                                       <i class="bi bi-pencil-fill fs-6"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // 1. Inicializar Select2
    $('.select2-multiple').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Seleccione opciones...',
        closeOnSelect: false,
        allowClear: true
    });

    // 2. Inicializar DataTables
    $('#tablaObras').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
        },
        order: [[0, 'desc']], 
        columnDefs: [
            { orderable: false, targets: 4 }
        ],
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        stateSave: true 
    });

    // 3. Inicializar Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
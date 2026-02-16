<?php
// modulos/liquidaciones/config/rg830_config.php
require_once __DIR__ . '/../../../auth/middleware.php';
require_login();
require_role(['Admin']);
require_once __DIR__ . '/../../../config/database.php';
include __DIR__ . '/../../../public/_header.php';

$mensaje = '';
$tipo_alerta = '';
$tab = $_GET['tab'] ?? 'conceptos';

// =============================================
// PROCESAR ACCIONES POST
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        switch ($accion) {
            // --- CONCEPTOS ---
            case 'guardar_concepto':
                $id = (int)($_POST['concepto_id'] ?? 0);
                $codigo = trim($_POST['codigo']);
                $inciso = trim($_POST['inciso']);
                $descripcion = trim($_POST['descripcion']);
                $activo = (int)($_POST['activo'] ?? 1);

                if ($id > 0) {
                    $pdo->prepare("UPDATE rg830_conceptos SET codigo=?, inciso=?, descripcion=?, activo=? WHERE id=?")
                        ->execute([$codigo, $inciso, $descripcion, $activo, $id]);
                    $mensaje = "Concepto actualizado.";
                } else {
                    $pdo->prepare("INSERT INTO rg830_conceptos (codigo, inciso, descripcion, activo) VALUES (?,?,?,?)")
                        ->execute([$codigo, $inciso, $descripcion, $activo]);
                    $mensaje = "Concepto creado.";
                }
                $tipo_alerta = 'success';
                $tab = 'conceptos';
                break;

            // --- VIGENCIAS ---
            case 'guardar_vigencia':
                $id = (int)($_POST['vigencia_id'] ?? 0);
                $concepto_id = (int)$_POST['concepto_id'];
                $desde = $_POST['vigencia_desde'];
                $hasta = !empty($_POST['vigencia_hasta']) ? $_POST['vigencia_hasta'] : null;
                $porc_insc = (float)str_replace(',', '.', $_POST['porc_inscripto']);
                $porc_no_insc = (float)str_replace(',', '.', $_POST['porc_no_inscripto']);
                $minimo = (float)str_replace(',', '.', str_replace('.', '', $_POST['minimo_no_sujeto']));
                $modo = $_POST['modo_calculo'];
                $activo = (int)($_POST['activo'] ?? 1);

                if ($id > 0) {
                    $pdo->prepare("UPDATE rg830_vigencias SET concepto_id=?, vigencia_desde=?, vigencia_hasta=?, porc_inscripto=?, porc_no_inscripto=?, minimo_no_sujeto=?, modo_calculo=?, activo=? WHERE id=?")
                        ->execute([$concepto_id, $desde, $hasta, $porc_insc, $porc_no_insc, $minimo, $modo, $activo, $id]);
                    $mensaje = "Vigencia actualizada.";
                } else {
                    $pdo->prepare("INSERT INTO rg830_vigencias (concepto_id, vigencia_desde, vigencia_hasta, porc_inscripto, porc_no_inscripto, minimo_no_sujeto, modo_calculo, activo) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$concepto_id, $desde, $hasta, $porc_insc, $porc_no_insc, $minimo, $modo, $activo]);
                    $mensaje = "Vigencia creada.";
                }
                $tipo_alerta = 'success';
                $tab = 'vigencias';
                break;

            // --- ESCALAS / TRAMOS ---
            case 'guardar_tramos':
                $vigencia_id = (int)$_POST['vigencia_id'];
                $pdo->prepare("DELETE FROM rg830_escalas_tramos WHERE vigencia_id = ?")->execute([$vigencia_id]);

                if (isset($_POST['tramo_desde']) && is_array($_POST['tramo_desde'])) {
                    $stmtT = $pdo->prepare("INSERT INTO rg830_escalas_tramos (vigencia_id, orden, desde, hasta, importe_fijo, porcentaje_sobre_excedente, excedente_desde) VALUES (?,?,?,?,?,?,?)");
                    for ($i = 0; $i < count($_POST['tramo_desde']); $i++) {
                        $hastaT = !empty($_POST['tramo_hasta'][$i]) ? (float)str_replace(',', '.', str_replace('.', '', $_POST['tramo_hasta'][$i])) : null;
                        $stmtT->execute([
                            $vigencia_id,
                            $i + 1,
                            (float)str_replace(',', '.', str_replace('.', '', $_POST['tramo_desde'][$i])),
                            $hastaT,
                            (float)str_replace(',', '.', str_replace('.', '', $_POST['tramo_fijo'][$i])),
                            (float)str_replace(',', '.', $_POST['tramo_porcentaje'][$i]),
                            (float)str_replace(',', '.', str_replace('.', '', $_POST['tramo_excedente'][$i])),
                        ]);
                    }
                }
                $mensaje = "Tramos guardados correctamente.";
                $tipo_alerta = 'success';
                $tab = 'escalas';
                break;
        }
    } catch (Exception $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================
// CONSULTAS
// =============================================
$conceptos = $pdo->query("SELECT * FROM rg830_conceptos ORDER BY codigo, inciso")->fetchAll();
$vigencias = $pdo->query("
    SELECT v.*, c.codigo, c.inciso, c.descripcion AS concepto_desc
    FROM rg830_vigencias v
    JOIN rg830_conceptos c ON c.id = v.concepto_id
    ORDER BY c.codigo, v.vigencia_desde DESC
")->fetchAll();

// Tramos para vigencias con escala
$tramosMap = [];
$stmtTr = $pdo->query("SELECT * FROM rg830_escalas_tramos ORDER BY vigencia_id, orden");
foreach ($stmtTr->fetchAll() as $tr) {
    $tramosMap[$tr['vigencia_id']][] = $tr;
}

function fmt($v) { return number_format((float)$v, 2, ',', '.'); }
?>

<div class="container-fluid px-4 my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="bi bi-sliders"></i> Configuración RG 830</h3>
            <p class="text-muted small mb-0">Conceptos, vigencias, alícuotas y escalas de retención</p>
        </div>
        <a href="../liquidaciones_listado.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show py-2">
            <?= $mensaje ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- TABS -->
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $tab === 'conceptos' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabConceptos">
                <i class="bi bi-list-check"></i> Conceptos
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $tab === 'vigencias' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabVigencias">
                <i class="bi bi-calendar-range"></i> Vigencias / Alícuotas
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $tab === 'escalas' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabEscalas">
                <i class="bi bi-bar-chart-steps"></i> Escalas por Tramos
            </button>
        </li>
    </ul>

    <div class="tab-content pt-3">

        <!-- ========== TAB CONCEPTOS ========== -->
        <div class="tab-pane fade <?= $tab === 'conceptos' ? 'show active' : '' ?>" id="tabConceptos">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Conceptos RG 830</span>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalConcepto" onclick="limpiarModalConcepto()">
                        <i class="bi bi-plus-lg"></i> Nuevo Concepto
                    </button>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Código</th><th>Inciso</th><th>Descripción</th><th>Estado</th><th class="text-end">Acción</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conceptos as $c): ?>
                            <tr>
                                <td><span class="badge bg-primary"><?= htmlspecialchars($c['codigo']) ?></span></td>
                                <td class="fw-bold"><?= htmlspecialchars($c['inciso']) ?></td>
                                <td><?= htmlspecialchars($c['descripcion']) ?></td>
                                <td><?= $c['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
                                <td class="text-end">
                                    <button class="btn btn-outline-primary btn-sm" onclick="editarConcepto(<?= htmlspecialchars(json_encode($c)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========== TAB VIGENCIAS ========== -->
        <div class="tab-pane fade <?= $tab === 'vigencias' ? 'show active' : '' ?>" id="tabVigencias">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Vigencias y Alícuotas</span>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalVigencia" onclick="limpiarModalVigencia()">
                        <i class="bi bi-plus-lg"></i> Nueva Vigencia
                    </button>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Concepto</th>
                                <th>Vigencia Desde</th>
                                <th>Vigencia Hasta</th>
                                <th class="text-end">% Inscripto</th>
                                <th class="text-end">% No Inscripto</th>
                                <th class="text-end">Mínimo No Sujeto</th>
                                <th>Modo</th>
                                <th>Estado</th>
                                <th class="text-end">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vigencias as $v): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary"><?= $v['codigo'] ?></span>
                                    <small class="text-muted"><?= $v['inciso'] ?></small>
                                </td>
                                <td><?= date('d/m/Y', strtotime($v['vigencia_desde'])) ?></td>
                                <td><?= $v['vigencia_hasta'] ? date('d/m/Y', strtotime($v['vigencia_hasta'])) : '<span class="text-success fw-bold">Vigente</span>' ?></td>
                                <td class="text-end fw-bold text-primary"><?= number_format($v['porc_inscripto'], 2, ',', '.') ?>%</td>
                                <td class="text-end fw-bold text-danger"><?= number_format($v['porc_no_inscripto'], 2, ',', '.') ?>%</td>
                                <td class="text-end">$ <?= fmt($v['minimo_no_sujeto']) ?></td>
                                <td><span class="badge <?= $v['modo_calculo'] === 'ESCALA_TRAMOS' ? 'bg-warning text-dark' : 'bg-info' ?>"><?= $v['modo_calculo'] ?></span></td>
                                <td><?= $v['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
                                <td class="text-end">
                                    <button class="btn btn-outline-primary btn-sm" onclick="editarVigencia(<?= htmlspecialchars(json_encode($v)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========== TAB ESCALAS ========== -->
        <div class="tab-pane fade <?= $tab === 'escalas' ? 'show active' : '' ?>" id="tabEscalas">
            <?php
            $vigenciasEscala = array_filter($vigencias, fn($v) => $v['modo_calculo'] === 'ESCALA_TRAMOS');
            ?>
            <?php if (empty($vigenciasEscala)): ?>
                <div class="alert alert-info">No hay vigencias configuradas con modo "ESCALA_TRAMOS". Cambie el modo de cálculo en la pestaña Vigencias.</div>
            <?php endif; ?>

            <?php foreach ($vigenciasEscala as $v): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center">
                    <span class="fw-bold">
                        <span class="badge bg-primary"><?= $v['codigo'] ?></span> <?= $v['inciso'] ?>
                        — Desde: <?= date('d/m/Y', strtotime($v['vigencia_desde'])) ?>
                    </span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="accion" value="guardar_tramos">
                        <input type="hidden" name="vigencia_id" value="<?= $v['id'] ?>">
                        <table class="table table-sm table-bordered" id="tablaTramos_<?= $v['id'] ?>">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Desde ($)</th>
                                    <th>Hasta ($)</th>
                                    <th>Importe Fijo ($)</th>
                                    <th>% Sobre Excedente</th>
                                    <th>Excedente Desde ($)</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $tramos = $tramosMap[$v['id']] ?? [];
                                if (empty($tramos)) $tramos = [['desde' => 0, 'hasta' => '', 'importe_fijo' => 0, 'porcentaje_sobre_excedente' => 0, 'excedente_desde' => 0]];
                                foreach ($tramos as $idx => $t):
                                ?>
                                <tr>
                                    <td class="text-center"><?= $idx + 1 ?></td>
                                    <td><input type="text" name="tramo_desde[]" class="form-control form-control-sm" value="<?= fmt($t['desde']) ?>"></td>
                                    <td><input type="text" name="tramo_hasta[]" class="form-control form-control-sm" value="<?= $t['hasta'] ? fmt($t['hasta']) : '' ?>"></td>
                                    <td><input type="text" name="tramo_fijo[]" class="form-control form-control-sm" value="<?= fmt($t['importe_fijo']) ?>"></td>
                                    <td><input type="text" name="tramo_porcentaje[]" class="form-control form-control-sm" value="<?= number_format($t['porcentaje_sobre_excedente'], 2, ',', '.') ?>"></td>
                                    <td><input type="text" name="tramo_excedente[]" class="form-control form-control-sm" value="<?= fmt($t['excedente_desde']) ?>"></td>
                                    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="agregarTramo(<?= $v['id'] ?>)">
                                <i class="bi bi-plus-lg"></i> Agregar Tramo
                            </button>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Guardar Tramos</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- MODAL CONCEPTO -->
<div class="modal fade" id="modalConcepto" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="accion" value="guardar_concepto">
            <input type="hidden" name="concepto_id" id="concepto_id" value="0">
            <div class="modal-header"><h5 class="modal-title">Concepto RG 830</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Código</label>
                    <select name="codigo" id="concepto_codigo" class="form-select" required>
                        <option value="GANANCIAS">GANANCIAS</option>
                        <option value="IVA">IVA</option>
                        <option value="SUSS">SUSS</option>
                        <option value="IIBB">IIBB</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Inciso</label>
                    <input type="text" name="inciso" id="concepto_inciso" class="form-control" required placeholder="Ej: j, a, PAGO">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Descripción</label>
                    <textarea name="descripcion" id="concepto_descripcion" class="form-control" rows="2" required></textarea>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="activo" id="concepto_activo" class="form-check-input" value="1" checked>
                    <label class="form-check-label">Activo</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL VIGENCIA -->
<div class="modal fade" id="modalVigencia" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="accion" value="guardar_vigencia">
            <input type="hidden" name="vigencia_id" id="vigencia_id" value="0">
            <div class="modal-header"><h5 class="modal-title">Vigencia / Alícuota</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label fw-bold">Concepto</label>
                        <select name="concepto_id" id="vig_concepto_id" class="form-select" required>
                            <?php foreach ($conceptos as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= $c['codigo'] ?> - <?= $c['inciso'] ?> - <?= htmlspecialchars($c['descripcion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Vigencia Desde</label>
                        <input type="date" name="vigencia_desde" id="vig_desde" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Vigencia Hasta <small class="text-muted">(vacío=vigente)</small></label>
                        <input type="date" name="vigencia_hasta" id="vig_hasta" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Modo Cálculo</label>
                        <select name="modo_calculo" id="vig_modo" class="form-select">
                            <option value="PORCENTAJE_DIRECTO">Porcentaje Directo</option>
                            <option value="ESCALA_TRAMOS">Escala por Tramos</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-primary">% Inscripto</label>
                        <input type="text" name="porc_inscripto" id="vig_porc_insc" class="form-control" placeholder="0,00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-danger">% No Inscripto</label>
                        <input type="text" name="porc_no_inscripto" id="vig_porc_no_insc" class="form-control" placeholder="0,00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Mínimo No Sujeto ($)</label>
                        <input type="text" name="minimo_no_sujeto" id="vig_minimo" class="form-control" placeholder="0,00">
                    </div>
                </div>
                <div class="form-check mt-3">
                    <input type="checkbox" name="activo" id="vig_activo" class="form-check-input" value="1" checked>
                    <label class="form-check-label">Activo</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
function limpiarModalConcepto() {
    document.getElementById('concepto_id').value = 0;
    document.getElementById('concepto_codigo').value = 'GANANCIAS';
    document.getElementById('concepto_inciso').value = '';
    document.getElementById('concepto_descripcion').value = '';
    document.getElementById('concepto_activo').checked = true;
}
function editarConcepto(c) {
    document.getElementById('concepto_id').value = c.id;
    document.getElementById('concepto_codigo').value = c.codigo;
    document.getElementById('concepto_inciso').value = c.inciso;
    document.getElementById('concepto_descripcion').value = c.descripcion;
    document.getElementById('concepto_activo').checked = c.activo == 1;
    new bootstrap.Modal(document.getElementById('modalConcepto')).show();
}
function limpiarModalVigencia() {
    document.getElementById('vigencia_id').value = 0;
    document.getElementById('vig_desde').value = '';
    document.getElementById('vig_hasta').value = '';
    document.getElementById('vig_porc_insc').value = '';
    document.getElementById('vig_porc_no_insc').value = '';
    document.getElementById('vig_minimo').value = '';
    document.getElementById('vig_modo').value = 'PORCENTAJE_DIRECTO';
    document.getElementById('vig_activo').checked = true;
}
function editarVigencia(v) {
    document.getElementById('vigencia_id').value = v.id;
    document.getElementById('vig_concepto_id').value = v.concepto_id;
    document.getElementById('vig_desde').value = v.vigencia_desde;
    document.getElementById('vig_hasta').value = v.vigencia_hasta || '';
    document.getElementById('vig_porc_insc').value = v.porc_inscripto;
    document.getElementById('vig_porc_no_insc').value = v.porc_no_inscripto;
    document.getElementById('vig_minimo').value = v.minimo_no_sujeto;
    document.getElementById('vig_modo').value = v.modo_calculo;
    document.getElementById('vig_activo').checked = v.activo == 1;
    new bootstrap.Modal(document.getElementById('modalVigencia')).show();
}
function agregarTramo(vigId) {
    const tbody = document.querySelector('#tablaTramos_' + vigId + ' tbody');
    const nro = tbody.rows.length + 1;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="text-center">${nro}</td>
        <td><input type="text" name="tramo_desde[]" class="form-control form-control-sm" value="0,00"></td>
        <td><input type="text" name="tramo_hasta[]" class="form-control form-control-sm" value=""></td>
        <td><input type="text" name="tramo_fijo[]" class="form-control form-control-sm" value="0,00"></td>
        <td><input type="text" name="tramo_porcentaje[]" class="form-control form-control-sm" value="0,00"></td>
        <td><input type="text" name="tramo_excedente[]" class="form-control form-control-sm" value="0,00"></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}
</script>

<?php include __DIR__ . '/../../../public/_footer.php'; ?>

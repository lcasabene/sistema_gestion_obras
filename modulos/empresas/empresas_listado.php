<?php
// modulos/empresas/empresas_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$mensaje = '';
$tipo_alerta = '';

// --- LÓGICA PHP ---

// 1. SINCRONIZAR DESDE ARCA (IMPORTAR AUTOMÁTICO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'sincronizar_arca') {
    try {
        // Esta consulta busca CUITs en ARCA que no estén en Empresas e inserta
        $sql = "INSERT INTO empresas (razon_social, cuit, activo)
                SELECT DISTINCT nombre_emisor, cuit_emisor, 1
                FROM comprobantes_arca
                WHERE cuit_emisor NOT IN (SELECT cuit FROM empresas WHERE cuit IS NOT NULL)
                AND cuit_emisor IS NOT NULL AND cuit_emisor != ''";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $nuevos = $stmt->rowCount();
        
        if ($nuevos > 0) {
            $mensaje = "<strong>¡Éxito!</strong> Se registraron <strong>$nuevos</strong> nuevas empresas desde ARCA.";
            $tipo_alerta = "success";
        } else {
            $mensaje = "No hay empresas nuevas en ARCA para importar. Todo está al día.";
            $tipo_alerta = "info";
        }
    } catch (Exception $e) {
        $mensaje = "Error al sincronizar: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// 2. PROCESAR ALTA / EDICIÓN MANUAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar') {
    $id = (int)$_POST['id_empresa'];
    $razon = $_POST['razon_social'];
    $cuit = preg_replace('/[^0-9]/', '', $_POST['cuit']); // Limpiar CUIT
    $prov = $_POST['codigo_proveedor'];
    $prov2 = $_POST['codigo_proveedor_2'] ?? '';
    $es_ute = isset($_POST['es_ute']) ? 1 : 0;

    // Detectar columnas disponibles
    $cols_empresas = [];
    $colsStmt = $pdo->query("SHOW COLUMNS FROM empresas");
    while ($c = $colsStmt->fetch(PDO::FETCH_ASSOC)) { $cols_empresas[] = $c['Field']; }
    $tiene_es_ute = in_array('es_ute', $cols_empresas);
    $tiene_prov2 = in_array('codigo_proveedor_2', $cols_empresas);

    try {
        if ($id > 0) {
            // UPDATE
            $sql = "UPDATE empresas SET razon_social=?, cuit=?, codigo_proveedor=?";
            $params = [$razon, $cuit, $prov];
            if ($tiene_prov2) { $sql .= ", codigo_proveedor_2=?"; $params[] = $prov2; }
            if ($tiene_es_ute) { $sql .= ", es_ute=?"; $params[] = $es_ute; }
            $sql .= " WHERE id=?";
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            $mensaje = "Datos de la empresa actualizados.";
        } else {
            // INSERT
            $campos = "razon_social, cuit, codigo_proveedor";
            $holders = "?, ?, ?";
            $params = [$razon, $cuit, $prov];
            if ($tiene_prov2) { $campos .= ", codigo_proveedor_2"; $holders .= ", ?"; $params[] = $prov2; }
            if ($tiene_es_ute) { $campos .= ", es_ute"; $holders .= ", ?"; $params[] = $es_ute; }
            $campos .= ", activo"; $holders .= ", 1";
            $sql = "INSERT INTO empresas ($campos) VALUES ($holders)";
            $pdo->prepare($sql)->execute($params);
            $id = (int)$pdo->lastInsertId();
            $mensaje = "Empresa creada correctamente.";
        }
        $tipo_alerta = "success";

        // Si marcó como UTE, redirigir a composición
        if ($es_ute && $tiene_es_ute) {
            header("Location: empresa_ute.php?id=$id");
            exit;
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $mensaje = "Error: El CUIT <strong>$cuit</strong> ya existe.";
            $tipo_alerta = "warning";
        } else {
            $mensaje = "Error: " . $e->getMessage();
            $tipo_alerta = "danger";
        }
    }
}

// 3. CONSULTA LISTADO - detectar columnas disponibles
$has_ute_col = false;
$has_prov2_col = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM empresas");
    while ($cc = $colCheck->fetch(PDO::FETCH_ASSOC)) {
        if ($cc['Field'] === 'es_ute') $has_ute_col = true;
        if ($cc['Field'] === 'codigo_proveedor_2') $has_prov2_col = true;
    }
} catch (Exception $e) {}
$empresas = $pdo->query("SELECT * FROM empresas WHERE activo=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$totalEmpresas = count($empresas);

// 4. Cargar integrantes de todas las UTEs en una sola consulta
$ute_integrantes = [];
try {
    $rows = $pdo->query("
        SELECT u.empresa_id, u.denominacion, u.cuit, u.porcentaje
        FROM empresa_ute_integrantes u
        ORDER BY u.empresa_id, u.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $ute_integrantes[(int)$r['empresa_id']][] = $r;
    }
} catch (Exception $e) { /* tabla aún no existe */ }

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4">
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-5">
            <h3 class="mb-0 text-primary fw-bold"><i class="bi bi-buildings"></i> Empresas Contratistas</h3>
            <p class="text-muted small mb-0">Base de datos de proveedores unificada.</p>
        </div>
        <div class="col-md-7 text-end">
             <div class="d-inline-flex gap-2">
                <a href="../../public/menu.php" class="btn btn-secondary shadow-sm">
                    <i class="bi bi-arrow-left"></i> Volver
                </a>

                <form method="POST" onsubmit="return confirm('Se buscarán empresas nuevas en los comprobantes de ARCA. ¿Continuar?');">
                    <input type="hidden" name="accion" value="sincronizar_arca">
                    <button type="submit" class="btn btn-warning text-dark fw-bold shadow-sm" title="Importar desde facturas">
                        <i class="bi bi-arrow-repeat"></i> Sincronizar ARCA
                    </button>
                </form>
                
                <button class="btn btn-primary fw-bold shadow-sm" onclick="abrirModal(0)">
                    <i class="bi bi-plus-lg"></i> Nueva
                </button>
            </div>
        </div>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-info-circle-fill me-2"></i> <?= $mensaje ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaEmpresas" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead class="bg-light text-secondary">
                        <tr>
                            <th class="ps-3 py-3">Razón Social</th>
                            <th>CUIT</th>
                            <th>Cód. Proveedor</th>
                            <th>Cód. Alternativo</th>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($empresas as $e): ?>
                        <tr class="<?= !empty($e['es_ute']) ? 'table-warning' : '' ?>">
                            <td class="ps-3">
                                <div class="fw-bold text-dark fs-6">
                                    <?= htmlspecialchars($e['razon_social']) ?>
                                    <?php if(!empty($e['es_ute'])): ?>
                                        <span class="badge bg-warning text-dark ms-1"><i class="bi bi-people-fill me-1"></i>UTE</span>
                                    <?php endif; ?>
                                </div>
                                <?php if(!empty($e['es_ute']) && !empty($ute_integrantes[$e['id']])): ?>
                                <div class="mt-1">
                                    <a class="text-muted small text-decoration-none" data-bs-toggle="collapse"
                                       href="#ute-<?= $e['id'] ?>" role="button">
                                        <i class="bi bi-chevron-down me-1" style="font-size:.7rem"></i><?= count($ute_integrantes[$e['id']]) ?> integrante<?= count($ute_integrantes[$e['id']]) > 1 ? 's' : '' ?>
                                    </a>
                                    <div class="collapse mt-1" id="ute-<?= $e['id'] ?>">
                                        <ul class="list-unstyled mb-0 small">
                                        <?php foreach($ute_integrantes[$e['id']] as $int): ?>
                                            <li class="text-muted">
                                                <i class="bi bi-dot"></i>
                                                <span class="fw-semibold"><?= htmlspecialchars($int['denominacion']) ?></span>
                                                <?php if($int['cuit']): ?>
                                                    <span class="font-monospace text-secondary">(<?= htmlspecialchars($int['cuit']) ?>)</span>
                                                <?php endif; ?>
                                                <?php if($int['porcentaje'] > 0): ?>
                                                    <span class="badge bg-warning text-dark" style="font-size:.65rem"><?= number_format($int['porcentaje'], 1) ?>%</span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                                <?php elseif(!empty($e['es_ute'])): ?>
                                <div class="text-muted small mt-1"><i class="bi bi-info-circle me-1"></i>Sin integrantes cargados</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-dark border border-secondary border-opacity-25 px-2 py-1 font-monospace">
                                    <?= htmlspecialchars($e['cuit']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if(!empty($e['codigo_proveedor'])): ?>
                                    <span class="text-muted small"><i class="bi bi-tag-fill me-1"></i> <?= $e['codigo_proveedor'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(!empty($e['codigo_proveedor_2'])): ?>
                                    <span class="text-muted small"><i class="bi bi-tag me-1"></i> <?= htmlspecialchars($e['codigo_proveedor_2']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex gap-1">
                                    <button class="btn btn-sm btn-light text-primary border shadow-sm" 
                                            onclick="abrirModal(<?= $e['id'] ?>, '<?= htmlspecialchars($e['razon_social'], ENT_QUOTES) ?>', '<?= $e['cuit'] ?>', '<?= htmlspecialchars($e['codigo_proveedor'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($e['codigo_proveedor_2'] ?? '', ENT_QUOTES) ?>', <?= (int)($e['es_ute'] ?? 0) ?>)"
                                            title="Editar datos">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <?php if(!empty($e['es_ute'])): ?>
                                    <a href="empresa_ute.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-warning border shadow-sm" title="Composición UTE">
                                        <i class="bi bi-people-fill"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white text-muted small">
            Total registrado: <strong><?= $totalEmpresas ?></strong> empresas.
        </div>
    </div>
</div>

<div class="modal fade" id="modalEmpresa" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id_empresa" id="modalId">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="modalTitulo">Datos de Empresa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary">Razón Social</label>
                        <input type="text" name="razon_social" id="modalRazon" class="form-control fw-bold" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-secondary">CUIT</label>
                            <input type="text" name="cuit" id="modalCuit" class="form-control font-monospace" placeholder="Sólo números" required>
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="es_ute" id="modalEsUte" value="1">
                                <label class="form-check-label fw-bold" for="modalEsUte">Es UTE</label>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-secondary">Cód. Proveedor</label>
                            <input type="text" name="codigo_proveedor" id="modalProv" class="form-control" placeholder="Opcional">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-secondary">Cód. Alternativo</label>
                            <input type="text" name="codigo_proveedor_2" id="modalProv2" class="form-control" placeholder="Opcional">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0">
                    <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<script>
    $(document).ready(function() {
        $('#tablaEmpresas').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
            pageLength: 10,
            order: [[0, 'asc']],
            dom: "<'row'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
            buttons: [
                {
                    extend: 'excelHtml5',
                    text: '<i class="bi bi-file-excel"></i> Excel',
                    className: 'btn btn-sm btn-outline-success border-0',
                    title: 'Listado de Empresas'
                }
            ]
        });
    });

    function abrirModal(id, razon = '', cuit = '', prov = '', prov2 = '', esUte = 0) {
        document.getElementById('modalId').value = id;
        document.getElementById('modalRazon').value = razon;
        document.getElementById('modalCuit').value = cuit;
        document.getElementById('modalProv').value = prov;
        document.getElementById('modalProv2').value = prov2;
        document.getElementById('modalEsUte').checked = (esUte == 1);
        document.getElementById('modalTitulo').innerText = (id === 0) ? 'Nueva Empresa Manual' : 'Editar Empresa';
        var myModal = new bootstrap.Modal(document.getElementById('modalEmpresa'));
        myModal.show();
    }
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
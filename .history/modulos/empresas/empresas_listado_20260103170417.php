<?php
// modulos/empresas/empresas_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$mensaje = '';
$tipo_alerta = '';

// --- LÓGICA PHP ---

// 1. SINCRONIZAR DESDE ARCA (IMPORTAR AUTOMÁTICO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'sincronizar_arca') {
    try {
        // Esta consulta mágica hace todo:
        // 1. Selecciona CUIT y Nombre distintos de ARCA
        // 2. Filtra los que NO están ya en la tabla empresas
        // 3. Los inserta masivamente
        $sql = "INSERT INTO empresas (razon_social, cuit, activo)
                SELECT DISTINCT nombre_emisor, cuit_emisor, 1
                FROM comprobantes_arca
                WHERE cuit_emisor NOT IN (SELECT cuit FROM empresas WHERE cuit IS NOT NULL)
                AND cuit_emisor IS NOT NULL AND cuit_emisor != ''";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $nuevos = $stmt->rowCount();
        
        if ($nuevos > 0) {
            $mensaje = "<strong>¡Éxito!</strong> Se detectaron y registraron <strong>$nuevos</strong> nuevas empresas desde los comprobantes de ARCA.";
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

    try {
        if ($id > 0) {
            // UPDATE
            $sql = "UPDATE empresas SET razon_social=?, cuit=?, codigo_proveedor=? WHERE id=?";
            $pdo->prepare($sql)->execute([$razon, $cuit, $prov, $id]);
            $mensaje = "Datos de la empresa actualizados.";
        } else {
            // INSERT
            $sql = "INSERT INTO empresas (razon_social, cuit, codigo_proveedor, activo) VALUES (?, ?, ?, 1)";
            $pdo->prepare($sql)->execute([$razon, $cuit, $prov]);
            $mensaje = "Empresa creada correctamente.";
        }
        $tipo_alerta = "success";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Código de error por duplicado
            $mensaje = "Error: El CUIT <strong>$cuit</strong> ya existe en la base de datos.";
            $tipo_alerta = "warning";
        } else {
            $mensaje = "Error: " . $e->getMessage();
            $tipo_alerta = "danger";
        }
    }
}

// 3. CONSULTA LISTADO
// Traemos también si tiene comprobantes vinculados para mostrar info extra (opcional)
$empresas = $pdo->query("SELECT * FROM empresas WHERE activo=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas rápidas
$totalEmpresas = count($empresas);
?>

<div class="container my-4">
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h3 class="mb-0 text-primary fw-bold"><i class="bi bi-buildings"></i> Empresas Contratistas</h3>
            <p class="text-muted small mb-0">Base de datos de proveedores unificada.</p>
        </div>
        <div class="col-md-6 text-end">
             <div class="d-inline-flex gap-2">
                <form method="POST" onsubmit="return confirm('Esto buscará CUITs nuevos en los comprobantes importados de ARCA y los agregará aquí. ¿Continuar?');">
                    <input type="hidden" name="accion" value="sincronizar_arca">
                    <button type="submit" class="btn btn-warning text-dark fw-bold shadow-sm">
                        <i class="bi bi-arrow-repeat"></i> Sincronizar desde ARCA
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
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($empresas as $e): ?>
                        <tr>
                            <td class="ps-3">
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($e['razon_social']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-dark border border-secondary border-opacity-25 px-2 py-1" style="font-family: monospace; font-size: 0.9rem;">
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
                            <td class="text-end pe-3">
                                <button class="btn btn-sm btn-light text-primary border shadow-sm" 
                                        onclick="abrirModal(<?= $e['id'] ?>, '<?= htmlspecialchars($e['razon_social']) ?>', '<?= $e['cuit'] ?>', '<?= htmlspecialchars($e['codigo_proveedor'] ?? '') ?>')"
                                        title="Editar datos">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
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
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-secondary">Cód. Proveedor</label>
                            <input type="text" name="codigo_proveedor" id="modalProv" class="form-control" placeholder="Opcional">
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
    // Iniciar DataTables con opciones visuales
    $(document).ready(function() {
        $('#tablaEmpresas').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
            pageLength: 10,
            order: [[0, 'asc']], // Ordenar por nombre
            dom: "<'row'<'col-sm-12 col-md-6'f><'col-sm-12 col-md-6 text-end'B>>" +
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

    // Función Modal
    function abrirModal(id, razon = '', cuit = '', prov = '') {
        document.getElementById('modalId').value = id;
        document.getElementById('modalRazon').value = razon;
        document.getElementById('modalCuit').value = cuit;
        document.getElementById('modalProv').value = prov;
        
        document.getElementById('modalTitulo').innerText = (id === 0) ? 'Nueva Empresa Manual' : 'Editar Empresa';
        
        var myModal = new bootstrap.Modal(document.getElementById('modalEmpresa'));
        myModal.show();
    }
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
<?php
// modulos/empresas/empresas_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$mensaje = '';
$tipo_alerta = '';

// --- LÓGICA PHP ---

// 1. PROCESAR IMPORTACIÓN CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'importar') {
    if (isset($_FILES['archivo_csv']) && $_FILES['archivo_csv']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['archivo_csv']['tmp_name'];
        // Detectar separador
        $linea = fgets(fopen($tmp, 'r'));
        $sep = (strpos($linea, ';') !== false) ? ';' : ',';
        
        $handle = fopen($tmp, "r");
        $c = 0;
        while (($data = fgetcsv($handle, 1000, $sep)) !== FALSE) {
            // Ignorar cabecera si tiene texto "Razon"
            if (stripos($data[0], 'Razon') !== false) continue;
            
            // CSV Esperado: Razón Social ; CUIT ; Proveedor (Opcional)
            $razon = utf8_encode(trim($data[0]));
            $cuit  = preg_replace('/[^0-9]/', '', $data[1]); // Solo números
            $prov  = isset($data[2]) ? trim($data[2]) : null;
            
            if ($razon && $cuit) {
                // Insertar o ignorar si ya existe el CUIT
                $sql = "INSERT IGNORE INTO empresas (razon_social, cuit, codigo_proveedor, activo) VALUES (?, ?, ?, 1)";
                $pdo->prepare($sql)->execute([$razon, $cuit, $prov]);
                $c++;
            }
        }
        fclose($handle);
        $mensaje = "Se importaron $c empresas correctamente.";
        $tipo_alerta = "success";
    }
}

// 2. PROCESAR ALTA / EDICIÓN MANUAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar') {
    $id = (int)$_POST['id_empresa'];
    $razon = $_POST['razon_social'];
    $cuit = $_POST['cuit'];
    $prov = $_POST['codigo_proveedor'];

    if ($id > 0) {
        // UPDATE
        $sql = "UPDATE empresas SET razon_social=?, cuit=?, codigo_proveedor=? WHERE id=?";
        $pdo->prepare($sql)->execute([$razon, $cuit, $prov, $id]);
        $mensaje = "Empresa actualizada.";
    } else {
        // INSERT
        $sql = "INSERT INTO empresas (razon_social, cuit, codigo_proveedor, activo) VALUES (?, ?, ?, 1)";
        $pdo->prepare($sql)->execute([$razon, $cuit, $prov]);
        $mensaje = "Empresa creada.";
    }
    $tipo_alerta = "success";
}

// 3. CONSULTA LISTADO
$empresas = $pdo->query("SELECT * FROM empresas WHERE activo=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 text-primary"><i class="bi bi-buildings"></i> Empresas Contratistas</h3>
            <p class="text-muted small mb-0">Gestión de proveedores y contratistas de obra.</p>
        </div>
        <div class="btn-group">
            <button class="btn btn-outline-success fw-bold" data-bs-toggle="modal" data-bs-target="#modalImportar">
                <i class="bi bi-file-earmark-spreadsheet"></i> Importar Masivo
            </button>
            <button class="btn btn-primary fw-bold" onclick="abrirModal(0)">
                <i class="bi bi-plus-lg"></i> Nueva Empresa
            </button>
        </div>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show" role="alert">
            <?= $mensaje ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaEmpresas" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Razón Social</th>
                            <th>CUIT</th>
                            <th>Cód. Proveedor</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($empresas as $e): ?>
                        <tr>
                            <td><?= $e['id'] ?></td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($e['razon_social']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($e['cuit']) ?></span></td>
                            <td><?= htmlspecialchars($e['codigo_proveedor'] ?? '-') ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary border-0" 
                                        onclick="abrirModal(<?= $e['id'] ?>, '<?= htmlspecialchars($e['razon_social']) ?>', '<?= $e['cuit'] ?>', '<?= $e['codigo_proveedor'] ?>')">
                                    <i class="bi bi-pencil-square fs-5"></i>
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

<div class="modal fade" id="modalEmpresa" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id_empresa" id="modalId">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitulo">Gestión Empresa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Razón Social</label>
                        <input type="text" name="razon_social" id="modalRazon" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">CUIT</label>
                            <input type="text" name="cuit" id="modalCuit" class="form-control" placeholder="Solo números" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cód. Proveedor</label>
                            <input type="text" name="codigo_proveedor" id="modalProv" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalImportar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="importar">
                
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet"></i> Importar Masivo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Seleccione archivo CSV</label>
                        <input type="file" name="archivo_csv" class="form-control" accept=".csv" required>
                    </div>
                    <div class="alert alert-info small">
                        <strong>Formato esperado (Excel CSV):</strong><br>
                        Columna A: Razón Social<br>
                        Columna B: CUIT<br>
                        Columna C: Código Proveedor (Opcional)
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-success fw-bold">Subir e Importar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

<script>
    // Iniciar DataTables
    $(document).ready(function() {
        $('#tablaEmpresas').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
            pageLength: 10,
            order: [[0, 'desc']]
        });
    });

    // Función para abrir modal de edición/alta
    function abrirModal(id, razon = '', cuit = '', prov = '') {
        document.getElementById('modalId').value = id;
        document.getElementById('modalRazon').value = razon;
        document.getElementById('modalCuit').value = cuit;
        document.getElementById('modalProv').value = prov;
        
        // Cambiar título
        document.getElementById('modalTitulo').innerText = (id === 0) ? 'Nueva Empresa' : 'Editar Empresa #' + id;
        
        var myModal = new bootstrap.Modal(document.getElementById('modalEmpresa'));
        myModal.show();
    }
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
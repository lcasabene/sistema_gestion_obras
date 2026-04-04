<?php
// organismos_form.php - Con Líneas de Crédito
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$organismo = [
    'id' => 0,
    'nombre_organismo' => '',
    'descripcion_programa' => ''
];

$lineas = [];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM organismos_financiadores WHERE id = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) { $organismo = $row; }

    try {
        $stmtL = $pdo->prepare("SELECT * FROM lineas_credito WHERE organismo_id = ? AND activo = 1 ORDER BY id ASC");
        $stmtL->execute([$id]);
        $lineas = $stmtL->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* table may not exist yet */ }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-9">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold text-primary mb-0">
                    <i class="bi bi-bank"></i> <?= $id > 0 ? 'Editar Organismo' : 'Nuevo Organismo/Programa' ?>
                </h3>
                <a href="organismos_listado.php" class="btn btn-secondary shadow-sm">
                    <i class="bi bi-arrow-left-circle me-1"></i> Volver
                </a>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form action="organismos_guardar.php" method="POST">
                        <input type="hidden" name="id" value="<?= $organismo['id'] ?>">

                        <div class="row g-3 mb-4">
                            <div class="col-md-5">
                                <label class="form-label fw-bold small">Nombre del Organismo (Sigla) *</label>
                                <input type="text" name="nombre_organismo" class="form-control" 
                                       placeholder="Ej: BID, CAF, BANCO MUNDIAL..." 
                                       value="<?= htmlspecialchars($organismo['nombre_organismo']) ?>" required>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label fw-bold small">Descripción General</label>
                                <input type="text" name="descripcion_programa" class="form-control" 
                                       placeholder="Ej: Banco Interamericano de Desarrollo" 
                                       value="<?= htmlspecialchars($organismo['descripcion_programa'] ?? '') ?>">
                            </div>
                        </div>

                        <hr>
                        <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-credit-card-2-front me-1"></i> Líneas de Crédito</h6>
                        <table class="table table-bordered table-sm" id="tablaLineas">
                            <thead class="table-light">
                                <tr>
                                    <th width="200">Código</th>
                                    <th>Descripción del Programa</th>
                                    <th width="50"></th>
                                </tr>
                            </thead>
                            <tbody id="tbodyLineas">
                                <?php if (!empty($lineas)): ?>
                                    <?php foreach($lineas as $lc): ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="linea_id[]" value="<?= $lc['id'] ?>">
                                            <input type="text" name="linea_codigo[]" class="form-control form-control-sm font-monospace" 
                                                   value="<?= htmlspecialchars($lc['codigo']) ?>" placeholder="5597-OC-AR" required>
                                        </td>
                                        <td>
                                            <input type="text" name="linea_descripcion[]" class="form-control form-control-sm" 
                                                   value="<?= htmlspecialchars($lc['descripcion'] ?? '') ?>" placeholder="Descripción del programa">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-borrar-linea"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="linea_id[]" value="0">
                                            <input type="text" name="linea_codigo[]" class="form-control form-control-sm font-monospace" placeholder="5597-OC-AR">
                                        </td>
                                        <td>
                                            <input type="text" name="linea_descripcion[]" class="form-control form-control-sm" placeholder="Descripción del programa">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-borrar-linea"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-sm btn-success mb-4" id="btnAgregarLinea">
                            <i class="bi bi-plus-lg"></i> Agregar Línea
                        </button>

                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php if($id > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="confirmarBaja(<?= $id ?>)">
                                        <i class="bi bi-trash"></i> Dar de baja
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="gap-2 d-flex">
                                <a href="organismos_listado.php" class="btn btn-outline-secondary">Cancelar</a>
                                <button type="submit" class="btn btn-primary fw-bold px-4">
                                    <i class="bi bi-save me-1"></i> Guardar
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarBaja(id) {
    if(confirm('¿Está seguro de desactivar este organismo y sus líneas de crédito?')) {
        window.location.href = 'organismos_eliminar.php?id=' + id;
    }
}

document.getElementById('btnAgregarLinea').addEventListener('click', function() {
    var row = document.createElement('tr');
    row.innerHTML = '<td><input type="hidden" name="linea_id[]" value="0"><input type="text" name="linea_codigo[]" class="form-control form-control-sm font-monospace" placeholder="5597-OC-AR"></td>' +
        '<td><input type="text" name="linea_descripcion[]" class="form-control form-control-sm" placeholder="Descripción del programa"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-borrar-linea"><i class="bi bi-trash"></i></button></td>';
    document.getElementById('tbodyLineas').appendChild(row);
});

document.addEventListener('click', function(e) {
    if (e.target.closest('.btn-borrar-linea')) {
        var tbody = document.getElementById('tbodyLineas');
        if (tbody.rows.length > 1) {
            e.target.closest('tr').remove();
        }
    }
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
<?php
// organismos_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$organismo = [
    'id' => 0,
    'nombre_organismo' => '',
    'descripcion_programa' => ''
];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM organismos_financiadores WHERE id = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) { $organismo = $row; }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold text-primary mb-0">
                    <i class="bi bi-bank"></i> <?= $id > 0 ? 'Editar Organismo' : 'Nuevo Organismo/Programa' ?>
                </h3>
                <a href="../../public/menu.php" class="btn btn-secondary shadow-sm">
                    <i class="bi bi-house-door"></i> Volver al Menú
                </a>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form action="organismos_guardar.php" method="POST">
                        <input type="hidden" name="id" value="<?= $organismo['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Nombre del Organismo (Sigla)</label>
                            <input type="text" name="nombre_organismo" class="form-control" 
                                   placeholder="Ej: BID, CAF, Fondos Propios..." 
                                   value="<?= htmlspecialchars($organismo['nombre_organismo']) ?>" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold small">Descripción del Programa / N° Identificación</label>
                            <textarea name="descripcion_programa" class="form-control" rows="3" 
                                      placeholder="Ej: Préstamo N° 5678 - Infraestructura Urbana" required><?= htmlspecialchars($organismo['descripcion_programa']) ?></textarea>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php if($id > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="confirmarBaja(<?= $id ?>)">
                                        <i class="bi bi-trash"></i> Dar de baja
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="gap-2 d-flex">
                                <a href="organismos_lista.php" class="btn btn-outline-secondary">Cancelar</a>
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
    if(confirm('¿Está seguro de desactivar este organismo?')) {
        window.location.href = 'organismos_eliminar.php?id=' + id;
    }
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
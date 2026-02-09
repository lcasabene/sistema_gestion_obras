<?php
// organismos_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Inicialización de valores por defecto
$organismo = [
    'id' => 0,
    'nombre_organismo' => '',
    'descripcion_programa' => ''
];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM organismos_financiadores WHERE id = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $organismo = $row;
    }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold text-primary mb-0">
                    <i class="bi bi-bank"></i> <?= $id > 0 ? 'Editar Organismo' : 'Nuevo Organismo/Programa' ?>
                </h3>
                <a href="organismos_lista.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Volver
                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-body p-4">
                    <form action="organismos_guardar.php" method="POST">
                        <input type="hidden" name="id" value="<?= $organismo['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre del Organismo (Sigla)</label>
                            <input type="text" name="nombre_organismo" class="form-control" 
                                   placeholder="Ej: BID, CAF, Fondos Propios..." 
                                   value="<?= htmlspecialchars($organismo['nombre_organismo']) ?>" required>
                            <div class="form-text">Identificación corta del ente financiador.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Descripción del Programa / Línea de Crédito</label>
                            <textarea name="descripcion_programa" class="form-control" rows="3" 
                                      placeholder="Ej: Préstamo N° 5678 - Infraestructura Urbana" required><?= htmlspecialchars($organismo['descripcion_programa']) ?></textarea>
                            <div class="form-text">Detalle específico del programa de financiamiento.</div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary fw-bold py-2">
                                <i class="bi bi-save me-1"></i> Guardar Configuración
                            </button>
                            <?php if($id > 0): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm border-0 mt-2" onclick="confirmarBaja(<?= $id ?>)">
                                    <i class="bi bi-trash"></i> Dar de baja este organismo
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarBaja(id) {
    if(confirm('¿Está seguro de desactivar este organismo? No aparecerá más en las nuevas obras.')) {
        window.location.href = 'organismos_eliminar.php?id=' + id;
    }
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
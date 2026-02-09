<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$fuente = ['id' => 0, 'codigo' => '', 'nombre' => ''];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM fuentes_financiamiento WHERE id = ? AND activo=1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $fuente = $row;
    }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3><?php echo $id > 0 ? 'Editar Fuente' : 'Nueva Fuente'; ?></h3>
    <a href="fuentes_listado.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Volver al Listado</a>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <form action="fuentes_guardar.php" method="POST" class="card shadow-sm">
            <div class="card-body p-4">
                <input type="hidden" name="id" value="<?php echo $fuente['id']; ?>">

                <div class="mb-3">
                    <label for="codigo" class="form-label">Código *</label>
                    <input type="text" class="form-control" id="codigo" name="codigo" 
                           value="<?php echo htmlspecialchars($fuente['codigo']); ?>" 
                           placeholder="Ej: 1.11, TESORO, BID" required autofocus>
                    <div class="form-text">Identificador corto para uso en tablas y reportes.</div>
                </div>

                <div class="mb-3">
                    <label for="nombre" class="form-label">Nombre del Organismo / Fuente *</label>
                    <input type="text" class="form-control" id="nombre" name="nombre" 
                           value="<?php echo htmlspecialchars($fuente['nombre']); ?>" 
                           placeholder="Ej: Tesoro Provincial, Préstamo BID 1234..." required>
                </div>
            </div>
            
            <div class="card-footer d-flex justify-content-end gap-2">
                <a href="fuentes_listado.php" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i> Guardar Fuente
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
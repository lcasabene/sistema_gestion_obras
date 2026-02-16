<?php
// modulos/liquidaciones/exportar_sire.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/includes/SireExporter.php';
include __DIR__ . '/../../public/_header.php';

$mensaje = '';
$tipo_alerta = '';
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'preview';
    $desde = $_POST['desde'];
    $hasta = $_POST['hasta'];

    try {
        $exporter = new SireExporter($pdo);
        $result = $exporter->exportar($desde, $hasta);

        if ($accion === 'descargar' && $result['cantidad'] > 0) {
            $pdo->beginTransaction();
            $stmtExp = $pdo->prepare("INSERT INTO exportaciones (tipo, periodo_desde, periodo_hasta, cantidad_registros, importe_total, nombre_archivo, contenido_archivo, usuario_id) VALUES ('SIRE_F2004',?,?,?,?,?,?,?)");
            $filename = 'SIRE_F2004_' . date('Ymd_His') . '.txt';
            $stmtExp->execute([$desde, $hasta, $result['cantidad'], $result['importe_total'], $filename, $result['contenido'], $_SESSION['user_id']]);
            $expId = $pdo->lastInsertId();

            $stmtLink = $pdo->prepare("INSERT INTO exportacion_liquidaciones (exportacion_id, liquidacion_id, liquidacion_item_id) VALUES (?,?,?)");
            foreach ($result['item_ids'] as $item) {
                $stmtLink->execute([$expId, $item['liquidacion_id'], $item['liquidacion_item_id']]);
            }
            $pdo->commit();

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($result['contenido']));
            echo $result['contenido'];
            exit;
        }

        $preview = $result;
        $mensaje = "Vista previa generada: {$result['cantidad']} registro(s), Total: $ " . number_format($result['importe_total'], 2, ',', '.');
        $tipo_alerta = $result['cantidad'] > 0 ? 'success' : 'warning';

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "Error: " . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="bi bi-file-earmark-arrow-down"></i> Exportar SIRE – F2004</h3>
            <p class="text-muted small mb-0">Genera archivo TXT de 191 caracteres por línea según especificación AFIP/SIRE</p>
        </div>
        <a href="liquidaciones_listado.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show py-2">
        <?= $mensaje ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <form method="POST">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">Período Desde</label>
                        <input type="date" name="desde" class="form-control" value="<?= $_POST['desde'] ?? date('Y-m-01') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">Período Hasta</label>
                        <input type="date" name="hasta" class="form-control" value="<?= $_POST['hasta'] ?? date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="accion" value="preview" class="btn btn-info text-white w-100">
                            <i class="bi bi-eye"></i> Vista Previa
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="accion" value="descargar" class="btn btn-success w-100" <?= (!$preview || $preview['cantidad'] == 0) ? 'disabled' : '' ?>>
                            <i class="bi bi-download"></i> Descargar TXT
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($preview && $preview['cantidad'] > 0): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-light d-flex justify-content-between">
            <span class="fw-bold">Vista Previa — <?= $preview['cantidad'] ?> línea(s)</span>
            <span class="badge bg-info">191 caracteres/línea</span>
        </div>
        <div class="card-body p-0">
            <pre class="p-3 mb-0 bg-dark text-success" style="font-size:0.72rem; overflow-x:auto; max-height:400px; font-family:'Courier New',monospace;"><?= htmlspecialchars($preview['contenido']) ?></pre>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

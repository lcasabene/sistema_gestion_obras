<?php
// modulos/liquidaciones/exportar_sicore.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/includes/SicoreExporter.php';
include __DIR__ . '/../../public/_header.php';

$mensaje = '';
$tipo_alerta = '';
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'preview';
    $desde = $_POST['desde'];
    $hasta = $_POST['hasta'];
    $cod_impuesto = !empty($_POST['cod_impuesto']) ? $_POST['cod_impuesto'] : null;
    $cod_regimen = !empty($_POST['cod_regimen']) ? $_POST['cod_regimen'] : null;

    try {
        $exporter = new SicoreExporter($pdo);
        $result = $exporter->exportar($desde, $hasta, $cod_impuesto, $cod_regimen);

        if ($accion === 'descargar' && $result['cantidad'] > 0) {
            // Guardar en tabla exportaciones
            $pdo->beginTransaction();
            $stmtExp = $pdo->prepare("INSERT INTO exportaciones (tipo, periodo_desde, periodo_hasta, cantidad_registros, importe_total, nombre_archivo, contenido_archivo, usuario_id) VALUES ('SICORE',?,?,?,?,?,?,?)");
            $filename = 'SICORE_' . date('Ymd_His') . '.txt';
            $stmtExp->execute([$desde, $hasta, $result['cantidad'], $result['importe_total'], $filename, $result['contenido'], $_SESSION['user_id']]);
            $expId = $pdo->lastInsertId();

            // Registrar qué items se exportaron
            $stmtLink = $pdo->prepare("INSERT INTO exportacion_liquidaciones (exportacion_id, liquidacion_id, liquidacion_item_id) VALUES (?,?,?)");
            foreach ($result['item_ids'] as $item) {
                $stmtLink->execute([$expId, $item['liquidacion_id'], $item['liquidacion_item_id']]);
            }
            $pdo->commit();

            // Descargar archivo
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
            <h3 class="text-primary fw-bold mb-0"><i class="bi bi-file-earmark-arrow-down"></i> Exportar SICORE</h3>
            <p class="text-muted small mb-0">Genera archivo TXT con layout posicional AFIP</p>
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
                    <div class="col-md-2">
                        <label class="form-label fw-bold small">Período Desde</label>
                        <input type="date" name="desde" class="form-control" value="<?= $_POST['desde'] ?? date('Y-m-01') ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold small">Período Hasta</label>
                        <input type="date" name="hasta" class="form-control" value="<?= $_POST['hasta'] ?? date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Cód. Impuesto</label>
                        <input type="text" name="cod_impuesto" class="form-control" value="<?= $_POST['cod_impuesto'] ?? '217' ?>" placeholder="217">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Cód. Régimen</label>
                        <input type="text" name="cod_regimen" class="form-control" value="<?= $_POST['cod_regimen'] ?? '830' ?>" placeholder="830">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="accion" value="preview" class="btn btn-info text-white w-100">
                            <i class="bi bi-eye"></i> Vista Previa
                        </button>
                    </div>
                    <div class="col-md-2">
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
        <div class="card-header bg-light fw-bold">
            Vista Previa — <?= $preview['cantidad'] ?> línea(s)
        </div>
        <div class="card-body p-0">
            <pre class="p-3 mb-0 bg-dark text-success" style="font-size:0.75rem; overflow-x:auto; max-height:400px;"><?= htmlspecialchars($preview['contenido']) ?></pre>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../public/index.php"); exit; }

$basePath = __DIR__ . '/../../';
require_once $basePath . 'config/database.php';

if (file_exists($basePath . 'public/_header.php')) require_once $basePath . 'public/_header.php';

$obra_id = (int)($_GET['obra_id'] ?? 0);
if ($obra_id <= 0) {
    echo '<div class="container my-4"><div class="alert alert-warning">Obra inválida.</div></div>';
    if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';
    exit;
}

try {
    $st = $pdo->prepare("SELECT id, denominacion, monto_actualizado FROM obras WHERE id=? LIMIT 1");
    $st->execute([$obra_id]);
    $obra = $st->fetch(PDO::FETCH_ASSOC);
    if (!$obra) throw new Exception("No se encontró la obra.");

    // Fuentes disponibles
    $fuentes = $pdo->query("SELECT id, codigo, nombre FROM fuentes_financiamiento WHERE activo=1 ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    echo '<div class="container my-4"><div class="alert alert-danger">Error: '.htmlspecialchars($e->getMessage()).'</div></div>';
    if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';
    exit;
}

$monto_actualizado = (float)($obra['monto_actualizado'] ?? 0);
?>
<div class="container my-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-1">Generar Curva</h3>
            <div class="text-muted">
                Obra #<?= (int)$obra['id'] ?> — <?= htmlspecialchars($obra['denominacion'] ?? '') ?>
            </div>
        </div>
        <a href="curva_listado.php" class="btn btn-secondary">Volver</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="curva_generate.php" class="row g-3">
                <input type="hidden" name="obra_id" value="<?= (int)$obra['id'] ?>">

                <div class="col-md-6">
                    <label class="form-label">Monto actualizado (referencia)</label>
                    <input type="text" class="form-control" value="$ <?= number_format($monto_actualizado, 2, ',', '.') ?>" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Anticipo financiero (monto)</label>
                    <input type="number" step="0.01" min="0" name="anticipo_monto" class="form-control" value="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Fecha desde</label>
                    <input type="date" name="fecha_desde" class="form-control" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Fecha hasta</label>
                    <input type="date" name="fecha_hasta" class="form-control" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Modo de curva</label>
                    <select name="modo" class="form-select" required>
                        <option value="S" selected>Tipo S (automática)</option>
                        <option value="MANUAL">Manual (pareja, editable luego)</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Pendiente (k) curva S</label>
                    <input type="number" step="0.1" min="1" max="30" name="k" class="form-control" value="10">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Mes de pago anticipo</label>
                    <select name="anticipo_mes" class="form-select">
                        <option value="PRIMERO" selected>Primer mes del plan</option>
                        <option value="SEGUNDO">Segundo mes del plan</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">FUFI del anticipo</label>
                    <select name="anticipo_fuente_id" class="form-select" required>
                        <?php if (count($fuentes) === 0): ?>
                            <option value="">(No hay FUFI cargadas)</option>
                        <?php else: ?>
                            <?php foreach ($fuentes as $f): ?>
                                <option value="<?= (int)$f['id'] ?>">
                                    <?= htmlspecialchars($f['codigo'].' - '.$f['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">El anticipo se imputará 100% a esta FUFI.</div>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        Generar y dejar vigente
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="alert alert-info mt-3 mb-0">
        Al generar una nueva curva, se marca como “no vigente” la curva anterior de la obra.
        La distribución por FUFI se inicializa desde <code>obra_fuentes</code> y luego puede editarse por período.
    </div>
</div>

<?php
if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';

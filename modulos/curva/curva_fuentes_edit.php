<?php
require_once __DIR__ . '/../../config/session_config.php';
secure_session_start();
if (!is_session_valid()) {
    header("Location: ../../auth/login.php?expired=1");
    exit;
}

$basePath = __DIR__ . '/../../';
require_once $basePath . 'config/database.php';
if (file_exists($basePath . 'public/_header.php')) require_once $basePath . 'public/_header.php';

$obra_id = (int)($_GET['obra_id'] ?? 0);
if ($obra_id <= 0) { echo '<div class="container my-4"><div class="alert alert-warning">Obra inválida.</div></div>'; exit; }

try {
    $cv = $pdo->prepare("SELECT id, nro_version FROM curva_version WHERE obra_id=? AND es_vigente=1 LIMIT 1");
    $cv->execute([$obra_id]);
    $curva = $cv->fetch(PDO::FETCH_ASSOC);
    if (!$curva) throw new Exception("No hay curva vigente.");

    $st = $pdo->prepare("SELECT periodo, monto_bruto_plan, anticipo_pago_plan, anticipo_recupero_plan FROM curva_detalle WHERE curva_version_id=? ORDER BY periodo");
    $st->execute([$curva['id']]);
    $periodos = $st->fetchAll(PDO::FETCH_ASSOC);

    $fuentes = $pdo->query("SELECT id, nombre, descripcion FROM fuentes_financiamiento WHERE activo=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    // traer porcentajes actuales por periodo/fuente
    $st = $pdo->prepare("SELECT periodo, fuente_id, porcentaje_fuente FROM curva_detalle_fuente WHERE curva_version_id=?");
    $st->execute([$curva['id']]);
    $pctMap = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pctMap[$r['periodo']][(int)$r['fuente_id']] = (float)$r['porcentaje_fuente'];
    }

} catch (Throwable $e) {
    echo '<div class="container my-4"><div class="alert alert-danger">Error: '.htmlspecialchars($e->getMessage()).'</div></div>';
    if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';
    exit;
}
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-1">Editar FUFI por período</h3>
      <div class="text-muted small">Obra ID <?= $obra_id ?> · Curva vigente V<?= (int)$curva['nro_version'] ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-secondary" href="curva_view.php?obra_id=<?= $obra_id ?>">Volver</a>
    </div>
  </div>

  <div class="alert alert-info">
    Editás el <b>porcentaje por FUFI</b> en cada período. Al guardar, se recalculan los montos por fuente respetando el bruto y los componentes de anticipo del período.
  </div>

  <form method="POST" action="curva_fuentes_guardar.php">
    <input type="hidden" name="obra_id" value="<?= $obra_id ?>">
    <input type="hidden" name="curva_version_id" value="<?= (int)$curva['id'] ?>">

    <div class="table-responsive">
      <table class="table table-bordered table-sm align-middle">
        <thead class="table-light">
          <tr class="text-center">
            <th>Período</th>
            <?php foreach ($fuentes as $f): ?>
              <th><?= htmlspecialchars($f['id']) ?><div class="text-muted small"><?= htmlspecialchars($f['nombre']) ?></div></th>
            <?php endforeach; ?>
            <th>Suma %</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periodos as $p): $per=$p['periodo']; ?>
            <tr>
              <td class="text-center fw-semibold"><?= htmlspecialchars($per) ?></td>
              <?php foreach ($fuentes as $f): 
                $fid = (int)$f['id'];
                $val = $pctMap[$per][$fid] ?? 0;
              ?>
                <td>
                  <input type="number" step="0.001" min="0" class="form-control form-control-sm"
                         name="pct[<?= htmlspecialchars($per) ?>][<?= $fid ?>]"
                         value="<?= htmlspecialchars((string)$val) ?>">
                </td>
              <?php endforeach; ?>
              <td class="text-muted small">100.000 (validación al guardar)</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="row g-2">
      <div class="col-md-4">
        <button type="submit" class="btn btn-primary w-100">Guardar y recalcular</button>
      </div>
    </div>
  </form>
</div>

<?php
if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';

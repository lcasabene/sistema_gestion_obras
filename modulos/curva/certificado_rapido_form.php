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
if ($obra_id <= 0) {
    echo '<div class="container my-4"><div class="alert alert-warning">Falta obra_id.</div></div>';
    if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';
    exit;
}

// Sugerir próximo nro de certificado
$st = $pdo->prepare("SELECT COALESCE(MAX(nro),0)+1 AS prox FROM certificados WHERE obra_id=?");
$st->execute([$obra_id]);
$prox = (int)$st->fetchColumn();

$periodoDefault = date('Y-m');
$fechaDefault = date('Y-m-d');
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-1">Carga rápida de Certificado (pruebas)</h3>
      <div class="text-muted">Obra ID: <?= $obra_id ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-secondary" href="../curva/curva_view.php?obra_id=<?= $obra_id ?>">Volver a Curva</a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <form class="row g-3" method="POST" action="certificado_rapido_guardar.php">
        <input type="hidden" name="obra_id" value="<?= $obra_id ?>">

        <div class="col-md-3">
          <label class="form-label">Nro</label>
          <input type="number" name="nro" class="form-control" value="<?= $prox ?>" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Período (YYYY-MM)</label>
          <input type="text" name="periodo" class="form-control" value="<?= htmlspecialchars($periodoDefault) ?>" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Fecha cert</label>
          <input type="date" name="fecha_cert" class="form-control" value="<?= $fechaDefault ?>" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Estado</label>
          <select name="estado" class="form-select" required>
            <option value="CARGADO">CARGADO</option>
            <option value="REVISADO">REVISADO</option>
            <option value="APROBADO" selected>APROBADO</option>
            <option value="PAGADO">PAGADO</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Monto certificado</label>
          <input type="number" step="0.01" min="0" name="monto_certificado" class="form-control" value="0" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Anticipo desc</label>
          <input type="number" step="0.01" min="0" name="anticipo_desc" class="form-control" value="0">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fondo reparo</label>
          <input type="number" step="0.01" min="0" name="fondo_reparo" class="form-control" value="0">
        </div>

        <div class="col-md-3">
          <label class="form-label">Otros desc</label>
          <input type="number" step="0.01" min="0" name="otros_desc" class="form-control" value="0">
        </div>

        <div class="col-md-3">
          <label class="form-label">Multas</label>
          <input type="number" step="0.01" min="0" name="multas" class="form-control" value="0">
        </div>

        <div class="col-md-3">
          <label class="form-label">Importe a pagar</label>
          <input type="number" step="0.01" min="0" name="importe_a_pagar" class="form-control" value="0" required>
          <div class="form-text">Este es el que usa la curva como “Real cert. (a pagar)”.</div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Avance físico período (%)</label>
          <input type="number" step="0.001" min="0" name="avance_fisico_periodo" class="form-control" value="0">
        </div>

        <div class="col-md-3">
          <label class="form-label">Avance físico acum (%)</label>
          <input type="number" step="0.001" min="0" name="avance_fisico_acum" class="form-control" value="0">
        </div>

        <div class="col-md-12">
          <label class="form-label">Observaciones</label>
          <textarea name="observaciones" class="form-control" rows="2"></textarea>
        </div>

        <div class="col-md-4">
          <button class="btn btn-primary w-100" type="submit">Guardar certificado</button>
        </div>
      </form>
    </div>
  </div>

  <div class="alert alert-info mt-3 mb-0">
    Tip: cargá certificados en distintos períodos (ej. 2026-02, 2026-03, etc.). Luego recargá <b>Curva</b> y vas a ver el Plan Ajustado reprogramado dentro del mismo plazo.
  </div>
</div>

<?php
if (file_exists($basePath . 'public/_footer.php')) require_once $basePath . 'public/_footer.php';

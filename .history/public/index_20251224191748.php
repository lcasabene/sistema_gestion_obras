<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

// KPIs básicos
$kpi = [
  'obras_total' => 0,
  'obras_activas' => 0,
  'obras_paralizadas' => 0,
  'monto_actualizado_total' => 0,
  'certificado_total' => 0,
  'saldo_estimado' => 0,
  'vedas_vigentes' => 0,
];

$kpi['obras_total'] = (int)$pdo->query("SELECT COUNT(*) c FROM obras")->fetch()['c'];
$kpi['obras_activas'] = (int)$pdo->query("SELECT COUNT(*) c FROM obras WHERE activo=1")->fetch()['c'];
$kpi['monto_actualizado_total'] = (float)$pdo->query("SELECT COALESCE(SUM(monto_actualizado),0) s FROM obras WHERE activo=1")->fetch()['s'];
$kpi['certificado_total'] = (float)$pdo->query("SELECT COALESCE(SUM(monto_certificado),0) s FROM certificados WHERE activo=1 AND estado<>'ANULADO'")->fetch()['s'];
$kpi['saldo_estimado'] = $kpi['monto_actualizado_total'] - $kpi['certificado_total'];

// Obras paralizadas (si existe estado 'Paralizada')
$stmt = $pdo->prepare("SELECT id FROM estados_obra WHERE nombre = 'Paralizada' AND activo=1 LIMIT 1");
$stmt->execute();
$estadoParalizada = $stmt->fetchColumn();
if ($estadoParalizada) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM obras WHERE estado_obra_id = ? AND activo=1");
    $stmt->execute([$estadoParalizada]);
    $kpi['obras_paralizadas'] = (int)$stmt->fetch()['c'];
}

// Vedas vigentes hoy
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM vedas_climaticas WHERE activo=1 AND CURDATE() BETWEEN fecha_desde AND fecha_hasta");
$stmt->execute();
$kpi['vedas_vigentes'] = (int)$stmt->fetch()['c'];

include __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Dashboard</h3>
  <a class="btn btn-primary" href="menu.php"><i class="bi bi-grid-3x3-gap me-1"></i> Menú</a>
</div>

<div class="row g-3">
  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Obras (total)</div>
      <div class="fs-3"><?php echo number_format($kpi['obras_total']); ?></div>
    </div></div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Monto actualizado (total)</div>
      <div class="fs-3">$ <?php echo number_format($kpi['monto_actualizado_total'], 2, ',', '.'); ?></div>
    </div></div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Certificado (total)</div>
      <div class="fs-3">$ <?php echo number_format($kpi['certificado_total'], 2, ',', '.'); ?></div>
    </div></div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Saldo estimado</div>
      <div class="fs-3">$ <?php echo number_format($kpi['saldo_estimado'], 2, ',', '.'); ?></div>
    </div></div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Obras paralizadas</div>
      <div class="fs-3"><?php echo number_format($kpi['obras_paralizadas']); ?></div>
    </div></div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Vedas vigentes hoy</div>
      <div class="fs-3"><?php echo number_format($kpi['vedas_vigentes']); ?></div>
    </div></div>
  </div>
</div>

<hr class="my-4">

<div class="card shadow-sm">
  <div class="card-body">
    <h5 class="mb-2">Próximos módulos</h5>
    <ul class="mb-0">
      <li>ABM Obras</li>
      <li>Curva planificada (manual / tipo S)</li>
      <li>Certificados + prorrateo por fuente</li>
      <li>Pagos</li>
      <li>Vedas climáticas</li>
      <li>Reportes (proyección y ejecución)</li>
    </ul>
  </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>

<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

// KPIs básicos y nuevos de Deuda Pública
$kpi = [
  'obras_total' => 0,
  'obras_activas' => 0,
  'monto_actualizado_total' => 0,
  'certificado_total' => 0,
  'saldo_estimado' => 0,
  'vedas_vigentes' => 0,
  // Nuevos campos para Deuda Pública
  'deuda_total' => 0, 
  'deuda_pendiente' => 0 
];

// ... (Tus consultas existentes se mantienen igual)
$kpi['obras_total'] = (int)$pdo->query("SELECT COUNT(*) c FROM obras")->fetch()['c'];
$kpi['obras_activas'] = (int)$pdo->query("SELECT COUNT(*) c FROM obras WHERE activo=1")->fetch()['c'];
$kpi['monto_actualizado_total'] = (float)$pdo->query("SELECT COALESCE(SUM(monto_actualizado),0) s FROM obras WHERE activo=1")->fetch()['s'];
$kpi['certificado_total'] = (float)$pdo->query("SELECT COALESCE(SUM(monto_certificado),0) s FROM certificados WHERE activo=1 AND estado<>'ANULADO'")->fetch()['s'];
$kpi['saldo_estimado'] = $kpi['monto_actualizado_total'] - $kpi['certificado_total'];

// Lógica para Deuda Pública (Simulada hasta que crees las tablas)
// $kpi['deuda_total'] = (float)$pdo->query("SELECT SUM(monto) FROM deuda_publica WHERE activo=1")->fetchColumn();

// Vedas vigentes hoy
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM vedas_climaticas WHERE activo=1 AND CURDATE() BETWEEN fecha_desde AND fecha_hasta");
$stmt->execute();
$kpi['vedas_vigentes'] = (int)$stmt->fetch()['c'];

include __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-0 text-primary">Dashboard de Gestión</h3>
    <p class="text-muted small">Resumen general de obras y compromisos financieros</p>
  </div>
  <div class="btn-group">
    <a class="btn btn-outline-success" href="importar.php">
        <i class="bi bi-file-earmark-excel me-1"></i> Importar Datos
    </a>
    <a class="btn btn-primary" href="menu.php">
        <i class="bi bi-grid-3x3-gap me-1"></i> Menú
    </a>
  </div>
</div>

<h5 class="mb-3"><i class="bi bi-cone-striped me-2"></i>Gestión de Obras</h5>
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card shadow-sm border-start border-primary border-4">
      <div class="card-body">
        <div class="text-muted small fw-bold text-uppercase">Obras Activas</div>
        <div class="fs-3"><?php echo number_format($kpi['obras_activas']); ?> / <?php echo number_format($kpi['obras_total']); ?></div>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small fw-bold text-uppercase">Inversión Actualizada</div>
      <div class="fs-4 text-dark">$ <?php echo number_format($kpi['monto_actualizado_total'], 2, ',', '.'); ?></div>
    </div></div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small fw-bold text-uppercase">Saldo por Ejecutar</div>
      <div class="fs-4 text-danger">$ <?php echo number_format($kpi['saldo_estimado'], 2, ',', '.'); ?></div>
    </div></div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small fw-bold text-uppercase">Vedas Vigentes</div>
      <div class="fs-3 text-warning"><?php echo number_format($kpi['vedas_vigentes']); ?></div>
    </div></div>
  </div>
</div>

<h5 class="mb-3"><i class="bi bi-bank me-2"></i>Deuda Pública</h5>
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card shadow-sm bg-light">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <div class="text-muted small fw-bold text-uppercase">Deuda Pública Consolidada</div>
            <div class="fs-3 text-dark">$ <?php echo number_format($kpi['deuda_total'], 2, ',', '.'); ?></div>
        </div>
        <i class="bi bi-calculator fs-1 text-secondary opacity-50"></i>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card shadow-sm bg-light border-start border-danger border-4">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <div class="text-muted small fw-bold text-uppercase">Pendiente de Pago</div>
            <div class="fs-3 text-danger">$ <?php echo number_format($kpi['deuda_pendiente'], 2, ',', '.'); ?></div>
        </div>
        <i class="bi bi-exclamation-triangle fs-1 text-danger opacity-50"></i>
      </div>
    </div>
  </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h5 class="card-title mb-3">Hoja de Ruta</h5>
            <div class="row">
                <div class="col-md-6">
                    <ul class="list-group list-group-flush">
                      <li class="list-group-item small"><i class="bi bi-check2-circle text-success me-2"></i>ABM Obras</li>
                      <li class="list-group-item small"><i class="bi bi-clock me-2"></i>Curva tipo S</li>
                      <li class="list-group-item small"><i class="bi bi-clock me-2"></i>Certificados / Prorrateo</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <ul class="list-group list-group-flush">
                      <li class="list-group-item small"><i class="bi bi-clock me-2"></i>Gestión de Pagos</li>
                      <li class="list-group-item small"><i class="bi bi-clock me-2"></i>Módulo de Deuda Pública</li>
                      <li class="list-group-item small"><i class="bi bi-clock me-2"></i>Reportes Ejecutivos</li>
                    </ul>
                </div>
            </div>
          </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-success shadow-sm h-100">
            <div class="card-body text-center">
                <i class="bi bi-cloud-arrow-up fs-1 text-success"></i>
                <h5 class="mt-2">Importar Archivos</h5>
                <p class="text-muted small">Carga masiva de obras, certificados o ítems de deuda.</p>
                <a href="importar.php" class="btn btn-success btn-sm w-100">Ir a Importación</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
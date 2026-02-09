<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

// --- CÁLCULO DE KPIS (INDICADORES) ---
$kpi = [
  'obras_total' => 0,
  'obras_activas' => 0,
  'monto_actualizado_total' => 0,
  'certificado_total' => 0,
  'saldo_estimado' => 0,
  'vedas_vigentes' => 0
];

try {
    // 1. Total de Obras
    $kpi['obras_total'] = (int)$pdo->query("SELECT COUNT(*) c FROM obras")->fetch()['c'];
    
    // 2. Obras Activas (En ejecución)
    $kpi['obras_activas'] = (int)$pdo->query("SELECT COUNT(*) c FROM obras WHERE activo=1 AND estado_obra_id IN (SELECT id FROM estados_obra WHERE nombre LIKE '%Ejecuc%')")->fetch()['c'];
    
    // 3. Monto Total Contratos (Actualizado)
    $kpi['monto_actualizado_total'] = (float)$pdo->query("SELECT COALESCE(SUM(monto_actualizado),0) s FROM obras WHERE activo=1")->fetch()['s'];
    
    // 4. Total Certificado (Pagado/Aprobado)
    // CORRECCIÓN: Usamos 'monto_neto_pagar' y filtramos por estado APROBADO o PAGADO
    $kpi['certificado_total'] = (float)$pdo->query("
        SELECT COALESCE(SUM(monto_neto_pagar),0) s 
        FROM certificados 
        WHERE estado IN ('APROBADO', 'PAGADO')
    ")->fetch()['s'];
    
    // 5. Saldo (Forecast simple)
    $kpi['saldo_estimado'] = $kpi['monto_actualizado_total'] - $kpi['certificado_total'];

    // 6. Vedas Activas (Si tienes el módulo de vedas, sino da 0)
    // $kpi['vedas_vigentes'] = (int)$pdo->query("SELECT COUNT(*) c FROM obras_eventos WHERE tipo_evento='VEDA_INVERNAL' AND fecha <= CURDATE() AND (fecha_fin >= CURDATE() OR fecha_fin IS NULL)")->fetch()['c'];

} catch (Exception $e) {
    // Si falla alguna consulta, seguimos con valores en 0 para no romper el dashboard
    error_log("Error KPI: " . $e->getMessage());
}

include __DIR__ . '/_header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0 fw-bold text-primary">Tablero de Control</h2>
            <p class="text-muted small">Resumen ejecutivo del estado de las obras.</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border p-2">
                <i class="bi bi-calendar3"></i> Hoy: <?= date('d/m/Y') ?>
            </span>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-primary border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Obras en Ejecución</div>
                    <div class="d-flex align-items-center mt-2">
                        <i class="bi bi-cone-striped fs-1 text-primary me-3"></i>
                        <div>
                            <h2 class="mb-0 fw-bold"><?= $kpi['obras_activas'] ?></h2>
                            <small class="text-muted">de <?= $kpi['obras_total'] ?> registradas</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-start border-info border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Cartera (Monto Act.)</div>
                    <div class="mt-2">
                        <h4 class="mb-0 fw-bold text-dark">$ <?= number_format($kpi['monto_actualizado_total'] / 1000000, 1, ',', '.') ?> M</h4>
                        <small class="text-info"><i class="bi bi-graph-up"></i> Total Actualizado</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-start border-success border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Certificado (Aprob.)</div>
                    <div class="mt-2">
                        <h4 class="mb-0 fw-bold text-success">$ <?= number_format($kpi['certificado_total'] / 1000000, 1, ',', '.') ?> M</h4>
                        <div class="progress mt-2" style="height: 5px;">
                            <?php 
                                $pct = ($kpi['monto_actualizado_total'] > 0) ? ($kpi['certificado_total'] / $kpi['monto_actualizado_total']) * 100 : 0;
                            ?>
                            <div class="progress-bar bg-success" style="width: <?= $pct ?>%"></div>
                        </div>
                        <small class="text-muted"><?= number_format($pct, 1) ?>% de ejecución global</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-start border-warning border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Saldo por Ejecutar</div>
                    <div class="mt-2">
                        <h4 class="mb-0 fw-bold text-secondary">$ <?= number_format($kpi['saldo_estimado'] / 1000000, 1, ',', '.') ?> M</h4>
                        <small class="text-warning">Pendiente de inversión</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h5 class="text-secondary border-bottom pb-2 mb-3">Accesos Directos</h5>
    <div class="row g-3">
        
        <div class="col-md-4">
            <div class="card h-100 shadow-sm hover-card">
                <div class="card-body text-center">
                    <i class="bi bi-speedometer2 fs-1 text-primary mb-3"></i>
                    <h5>Tablero de Obras</h5>
                    <p class="text-muted small">Ver estado de avance físico y financiero, alertas de presupuesto y curvas.</p>
                    <a href="../modulos/obras/obras_listado.php" class="btn btn-outline-primary w-100 fw-bold">Ir al Tablero</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow-sm hover-card">
                <div class="card-body text-center">
                    <i class="bi bi-file-earmark-text fs-1 text-success mb-3"></i>
                    <h5>Gestión Certificados</h5>
                    <p class="text-muted small">Carga de certificados, redeterminaciones y validación con ARCA.</p>
                    <a href="../modulos/certificados/certificados_listado.php" class="btn btn-outline-success w-100 fw-bold">Ir a Certificados</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow-sm hover-card">
                <div class="card-body text-center">
                    <i class="bi bi-cloud-upload fs-1 text-info mb-3"></i>
                    <h5>Importaciones</h5>
                    <p class="text-muted small">Sincronizar facturas desde ARCA o carga masiva de empresas.</p>
                    <a href="../modulos/arca/arca_import.php" class="btn btn-outline-info w-100 fw-bold">Importar ARCA</a>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
    .hover-card { transition: transform 0.2s; }
    .hover-card:hover { transform: translateY(-5px); }
</style>

<?php include __DIR__ . '/_footer.php'; ?>
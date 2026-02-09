<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

// --- CONFIGURACIÓN DEL FILTRO DE AÑO ---
// Obtenemos el año de la URL, si no existe, usamos el actual
$anio_actual = date('Y');
$anio_filtro = isset($_GET['anio']) ? (int)$_GET['anio'] : $anio_actual;

// Obtener lista de años disponibles para el selector
$anios_disponibles = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT ejer FROM presupuesto ORDER BY ejer DESC");
    $anios_disponibles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($anios_disponibles)) $anios_disponibles = [$anio_actual];
} catch (Exception $e) {
    $anios_disponibles = [$anio_actual];
}

// --- CÁLCULO DE KPIS ---
$kpi = [
  'obras_activas' => 0,
  // Totales Globales Presupuesto
  'presup_global_def' => 0,
  'presup_global_ejec' => 0,
  // Desgloses
  'obras_gen_def' => 0,     // unor = 2
  'obras_gen_ejec' => 0,
  'obras_edu_def' => 0,     // unor = 3
  'obras_edu_ejec' => 0,
  'deuda_def' => 0,         // inc = 7
  'deuda_ejec' => 0
];

try {
    // 1. Obras Activas (Mantenemos tu lógica original de la tabla 'obras')
    // Nota: Esto es independiente del año presupuestario, muestra lo activo hoy.
    $kpi['obras_activas'] = (int)$pdo->query("SELECT COUNT(*) c FROM obras WHERE activo=1 AND estado_obra_id IN (SELECT id FROM estados_obra WHERE nombre LIKE '%Ejecuc%')")->fetch()['c'];
    
    // 2. Consultas a la tabla PRESUPUESTO filtrando por año ($anio_filtro)
    // Hacemos una sola consulta con sumas condicionales para mayor rendimiento
    $sql_presupuesto = "
        SELECT 
            -- Global (Todo lo del año)
            SUM(monto_def) as total_def,
            SUM(monto_ejec) as total_ejec,
            
            -- Obras Generales (unor = 2)
            SUM(CASE WHEN unor = 2 THEN monto_def ELSE 0 END) as gen_def,
            SUM(CASE WHEN unor = 2 THEN monto_ejec ELSE 0 END) as gen_ejec,
            
            -- Obras Educativas (unor = 3)
            SUM(CASE WHEN unor = 3 THEN monto_def ELSE 0 END) as edu_def,
            SUM(CASE WHEN unor = 3 THEN monto_ejec ELSE 0 END) as edu_ejec,
            
            -- Servicio de la Deuda (inc = 7)
            SUM(CASE WHEN inc = 7 THEN monto_def ELSE 0 END) as deuda_def,
            SUM(CASE WHEN inc = 7 THEN monto_ejec ELSE 0 END) as deuda_ejec
            
        FROM presupuesto 
        WHERE ejer = :anio
    ";

    $stmt = $pdo->prepare($sql_presupuesto);
    $stmt->execute([':anio' => $anio_filtro]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($res) {
        $kpi['presup_global_def']  = (float)$res['total_def'];
        $kpi['presup_global_ejec'] = (float)$res['total_ejec'];
        
        $kpi['obras_gen_def']      = (float)$res['gen_def'];
        $kpi['obras_gen_ejec']     = (float)$res['gen_ejec'];
        
        $kpi['obras_edu_def']      = (float)$res['edu_def'];
        $kpi['obras_edu_ejec']     = (float)$res['edu_ejec'];
        
        $kpi['deuda_def']          = (float)$res['deuda_def'];
        $kpi['deuda_ejec']         = (float)$res['deuda_ejec'];
    }

} catch (Exception $e) {
    error_log("Error KPI: " . $e->getMessage());
}

// Función auxiliar para calcular porcentaje seguro (evitar división por cero)
function calc_pct($ejecutado, $definitivo) {
    if ($definitivo <= 0) return 0;
    return ($ejecutado / $definitivo) * 100;
}

include __DIR__ . '/_header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0 fw-bold text-primary">Tablero de Control</h2>
            <p class="text-muted small">Ejecución Presupuestaria y Estado de Obras</p>
        </div>
        
        <form method="GET" action="" class="d-flex align-items-center bg-white p-2 rounded shadow-sm border">
            <label for="anio" class="me-2 fw-bold text-secondary mb-0"><i class="bi bi-calendar-event"></i> Ejercicio:</label>
            <select name="anio" id="anio" class="form-select form-select-sm border-0 bg-light fw-bold text-primary" onchange="this.form.submit()" style="width: auto;">
                <?php foreach($anios_disponibles as $a): ?>
                    <option value="<?= $a ?>" <?= $a == $anio_filtro ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="row g-3 mb-4">
        
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-primary border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Obras en Ejecución</div>
                    <div class="d-flex align-items-center mt-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                            <i class="bi bi-cone-striped fs-3 text-primary"></i>
                        </div>
                        <div>
                            <h2 class="mb-0 fw-bold text-dark"><?= $kpi['obras_activas'] ?></h2>
                            <small class="text-muted">Proyectos activos</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-start border-success border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Ejecución Global (<?= $anio_filtro ?>)</div>
                    <?php $pct_global = calc_pct($kpi['presup_global_ejec'], $kpi['presup_global_def']); ?>
                    
                    <div class="mt-2">
                        <div class="d-flex justify-content-between align-items-end">
                            <h4 class="mb-0 fw-bold text-success"><?= number_format($pct_global, 1) ?>%</h4>
                            <small class="text-muted">$ <?= number_format($kpi['presup_global_ejec']/1000000, 0, ',', '.') ?>M ejec.</small>
                        </div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar bg-success" style="width: <?= $pct_global ?>%"></div>
                        </div>
                        <small class="text-muted" style="font-size: 0.75rem;">
                            De un total de $ <?= number_format($kpi['presup_global_def']/1000000, 0, ',', '.') ?> M
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-start border-info border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Obras Públicas</div>
                    
                    <?php $pct_gen = calc_pct($kpi['obras_gen_ejec'], $kpi['obras_gen_def']); ?>
                    <div class="mt-3 mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Generales</span>
                            <span class="fw-bold"><?= number_format($pct_gen, 1) ?>%</span>
                        </div>
                        <div class="progress" style="height: 4px;">
                            <div class="progress-bar bg-info" style="width: <?= $pct_gen ?>%"></div>
                        </div>
                    </div>

                    <?php $pct_edu = calc_pct($kpi['obras_edu_ejec'], $kpi['obras_edu_def']); ?>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Educativas</span>
                            <span class="fw-bold"><?= number_format($pct_edu, 1) ?>%</span>
                        </div>
                        <div class="progress" style="height: 4px;">
                            <div class="progress-bar bg-primary" style="width: <?= $pct_edu ?>%"></div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-start border-warning border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold">Servicio de Deuda</div>
                    <?php $pct_deuda = calc_pct($kpi['deuda_ejec'], $kpi['deuda_def']); ?>
                    
                    <div class="mt-2 text-center pt-2">
                        <div class="position-relative d-inline-block">
                             <i class="bi bi-bank fs-1 text-warning opacity-50"></i>
                             <h3 class="mb-0 fw-bold text-dark mt-1"><?= number_format($pct_deuda, 1) ?>%</h3>
                        </div>
                        <p class="mb-0 small text-muted mt-2">Pagado / Devengado</p>
                        <div class="progress mt-2" style="height: 4px;">
                             <div class="progress-bar bg-warning" style="width: <?= $pct_deuda ?>%"></div>
                        </div>
                        <small class="text-muted d-block mt-1">
                            $ <?= number_format($kpi['deuda_ejec']/1000000, 1, ',', '.') ?> M pagados
                        </small>
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
                    <p class="text-muted small">Ver estado de avance físico y financiero detallado.</p>
                    <a href="../modulos/obras/obras_listado.php" class="btn btn-outline-primary w-100 fw-bold">Ir al Tablero</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm hover-card">
                <div class="card-body text-center">
                    <i class="bi bi-file-earmark-text fs-1 text-success mb-3"></i>
                    <h5>Gestión Certificados</h5>
                    <p class="text-muted small">Carga de certificados y redeterminaciones.</p>
                    <a href="../modulos/certificados/certificados_listado.php" class="btn btn-outline-success w-100 fw-bold">Ir a Certificados</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm hover-card">
                <div class="card-body text-center">
                    <i class="bi bi-cloud-upload fs-1 text-info mb-3"></i>
                    <h5>Importaciones</h5>
                    <p class="text-muted small">Sincronizar datos externos y presupuestos.</p>
                    <a href="../modulos/arca/arca_import.php" class="btn btn-outline-info w-100 fw-bold">Importar Datos</a>
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
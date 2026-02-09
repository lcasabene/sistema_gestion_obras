<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Obtener info de últimas importaciones (Agregamos 'registros_insertados' al SELECT)
$logQuery = "SELECT tipo_importacion, fecha_subida, ultima_fecha_dato, registros_insertados 
             FROM sicopro_import_log 
             WHERE id IN (SELECT MAX(id) FROM sicopro_import_log GROUP BY tipo_importacion)";
$stmt = $pdo->query($logQuery);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mapear resultados para acceso fácil
$logsMap = [];
foreach ($logs as $l) $logsMap[$l['tipo_importacion']] = $l;
?>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Importación de Datos SICOPRO</h2>
        <a href="../../public/menu.php" class="btn btn-secondary">Volver al Menú</a>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-4">
            
            <?php if (isset($_GET['status'])): ?>
                <?php if ($_GET['status'] == 'success'): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i> Archivo importado y procesado correctamente.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <strong>Error:</strong> <?= htmlspecialchars($_GET['msg'] ?? 'Desconocido') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form action="procesar_importacion.php" method="POST" enctype="multipart/form-data">
                <div class="row align-items-end">
                    <div class="col-md-5 mb-3">
                        <label class="form-label fw-bold">1. Seleccione el tipo de archivo:</label>
                        <select class="form-select" name="tipo_importacion" required>
                            <option value="">-- Seleccionar --</option>
                            <option value="TOTAL_ANTICIPADO">1. Total Anticipado por ejercicio</option>
                            <option value="SOLICITADO">2. Solicitado y no anticipado</option>
                            <option value="SIN_PAGO">3. Anticipado sin pago a proveedor</option>
                            <option value="SICOPRO">4. Importación SICOPRO (Completa)</option>
                        </select>
                    </div>

                    <div class="col-md-5 mb-3">
                        <label class="form-label fw-bold">2. Archivo CSV (.csv):</label>
                        <input type="file" class="form-control" name="archivo_csv" accept=".csv" required>
                    </div>

                    <div class="col-md-2 mb-3">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-cloud-upload"></i> Subir</button>
                    </div>
                </div>
                <div class="form-text text-muted"><i class="bi bi-info-circle"></i> Asegúrese de que el archivo sea un CSV delimitado por comas o punto y coma.</div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <h4 class="mb-3 text-secondary"><i class="bi bi-activity"></i> Estado de Actualizaciones</h4>
        <?php 
        $tipos = [
            'TOTAL_ANTICIPADO' => ['label' => 'Total Anticipado', 'icon' => 'bi-cash-coin'],
            'SOLICITADO' => ['label' => 'Solicitado No Ant.', 'icon' => 'bi-hourglass-split'],
            'SIN_PAGO' => ['label' => 'Sin Pago Prov.', 'icon' => 'bi-wallet2'],
            'SICOPRO' => ['label' => 'Base SICOPRO', 'icon' => 'bi-database']
        ];
        
        foreach($tipos as $key => $info): 
            $fecha = isset($logsMap[$key]) ? date('d/m/Y H:i', strtotime($logsMap[$key]['fecha_subida'])) : '-';
            $dato = $logsMap[$key]['ultima_fecha_dato'] ?? '-';
            // AQUÍ OBTENEMOS EL CONTADOR
            $registros = isset($logsMap[$key]) ? number_format($logsMap[$key]['registros_insertados'], 0, ',', '.') : 0;
            $colorClass = isset($logsMap[$key]) ? 'text-success' : 'text-muted';
        ?>
        <div class="col-md-6 col-lg-3">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
                    <div class="d-flex align-items-center mb-2">
                        <div class="rounded-circle bg-light p-2 me-2 text-primary">
                            <i class="bi <?= $info['icon'] ?>"></i>
                        </div>
                        <h6 class="card-title mb-0 fw-bold text-dark"><?= $info['label'] ?></h6>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted d-block text-uppercase" style="font-size: 0.7rem;">Última Carga</small>
                        <span class="fs-5 fw-bold <?= $colorClass ?>"><?= $fecha ?></span>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center bg-light rounded p-2 mt-3">
                        <div>
                            <small class="text-muted d-block" style="font-size: 0.7rem;">Registros</small>
                            <strong class="text-dark"><?= $registros ?></strong>
                        </div>
                        
                        <?php if($key === 'SICOPRO'): ?>
                        <div class="text-end border-start ps-2">
                            <small class="text-muted d-block" style="font-size: 0.7rem;">Último Mov.</small>
                            <strong class="text-dark"><?= $dato ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
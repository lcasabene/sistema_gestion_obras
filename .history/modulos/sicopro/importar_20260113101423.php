<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Obtener info de últimas importaciones
$logQuery = "SELECT tipo_importacion, fecha_subida, ultima_fecha_dato, registros_insertados 
             FROM sicopro_import_log 
             WHERE id IN (SELECT MAX(id) FROM sicopro_import_log GROUP BY tipo_importacion)";
$stmt = $pdo->query($logQuery);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                        <i class="bi bi-check-circle-fill me-2"></i> Archivo importado correctamente.
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
                            <optgroup label="SICOPRO Principal">
                                <option value="SICOPRO">Base SICOPRO (Completa)</option>
                            </optgroup>
                            <optgroup label="Anticipos TGF">
                                <option value="TOTAL_ANTICIPADO">1. Total Anticipado por ejercicio</option>
                                <option value="SOLICITADO">2. Solicitado y no anticipado</option>
                                <option value="SIN_PAGO">3. Anticipado sin pago a proveedor</option>
                            </optgroup>
                            <optgroup label="Pagos y Liquidaciones">
                                <option value="LIQUIDACIONES">Liquidaciones (Excel/CSV)</option>
                                <option value="SIGUE">SIGUE (Pagos/Transferencias)</option>
                            </optgroup>
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
            </form>
        </div>
    </div>

    <div class="row g-3">
        <h5 class="mb-2 text-secondary"><i class="bi bi-activity"></i> Estado de Actualizaciones</h5>
        <?php 
        $tipos = [
            'TOTAL_ANTICIPADO' => ['label' => 'Total Anticipado', 'icon' => 'bi-cash-coin'],
            'SOLICITADO' => ['label' => 'Solicitado No Ant.', 'icon' => 'bi-hourglass-split'],
            'SIN_PAGO' => ['label' => 'Sin Pago Prov.', 'icon' => 'bi-wallet2'],
            'SICOPRO' => ['label' => 'Base SICOPRO', 'icon' => 'bi-database'],
            'LIQUIDACIONES' => ['label' => 'Liquidaciones', 'icon' => 'bi-file-earmark-text'],
            'SIGUE' => ['label' => 'Sistema SIGUE', 'icon' => 'bi-bank']
        ];
        
        foreach($tipos as $key => $info): 
            $fecha = isset($logsMap[$key]) ? date('d/m/Y H:i', strtotime($logsMap[$key]['fecha_subida'])) : '-';
            $registros = isset($logsMap[$key]) ? number_format($logsMap[$key]['registros_insertados'], 0, ',', '.') : 0;
            $colorClass = isset($logsMap[$key]) ? 'text-success' : 'text-muted';
        ?>
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center p-3">
                    <div class="mb-2 text-primary"><i class="bi <?= $info['icon'] ?> fs-3"></i></div>
                    <h6 class="card-title fw-bold text-dark small mb-2"><?= $info['label'] ?></h6>
                    <small class="d-block text-muted" style="font-size: 0.7rem;">Última Carga</small>
                    <span class="d-block fw-bold mb-1 <?= $colorClass ?>" style="font-size: 0.85rem;"><?= $fecha ?></span>
                    <span class="badge bg-light text-dark border">Reg: <?= $registros ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
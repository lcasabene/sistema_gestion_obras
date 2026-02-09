<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Obtener info de últimas importaciones
$logQuery = "SELECT tipo_importacion, fecha_subida, ultima_fecha_dato FROM sicopro_import_log 
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

    <div class="card shadow-sm">
        <div class="card-body">
            
            <?php if (isset($_GET['status'])): ?>
                <?php if ($_GET['status'] == 'success'): ?>
                    <div class="alert alert-success">Archivo importado correctamente.</div>
                <?php else: ?>
                    <div class="alert alert-danger">Error: <?= htmlspecialchars($_GET['msg'] ?? 'Desconocido') ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <form action="procesar_importacion.php" method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-bold">Seleccione el tipo de archivo:</label>
                    <select class="form-select" name="tipo_importacion" required>
                        <option value="">-- Seleccionar --</option>
                        <option value="TOTAL_ANTICIPADO">1. Total Anticipado por ejercicio</option>
                        <option value="SOLICITADO">2. Solicitado y no anticipado</option>
                        <option value="SIN_PAGO">3. Anticipado sin pago a proveedor</option>
                        <option value="SICOPRO">4. Importación SICOPRO (Completa)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Archivo CSV / Excel (.csv):</label>
                    <input type="file" class="form-control" name="archivo_csv" accept=".csv" required>
                    <div class="form-text">Asegúrese de que el archivo sea un CSV delimitado por comas o punto y coma.</div>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Subir e Importar</button>
            </form>
        </div>
    </div>

    <div class="row mt-4">
        <h4 class="mb-3">Estado de Actualizaciones</h4>
        <?php 
        $tipos = [
            'TOTAL_ANTICIPADO' => 'Total Anticipado',
            'SOLICITADO' => 'Solicitado No Ant.',
            'SIN_PAGO' => 'Sin Pago Prov.',
            'SICOPRO' => 'Base SICOPRO'
        ];
        foreach($tipos as $key => $label): 
            $fecha = $logsMap[$key]['fecha_subida'] ?? '-';
            $dato = $logsMap[$key]['ultima_fecha_dato'] ?? '-';
        ?>
        <div class="col-md-3">
            <div class="card text-center h-100">
                <div class="card-header bg-light"><?= $label ?></div>
                <div class="card-body">
                    <p class="card-text small text-muted">Última Importación:</p>
                    <h5 class="card-title"><?= $fecha ?></h5>
                    <?php if($key === 'SICOPRO' && $dato !== '-'): ?>
                        <hr>
                        <p class="small text-muted mb-0">Último Dato (MovFere):</p>
                        <strong><?= $dato ?></strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
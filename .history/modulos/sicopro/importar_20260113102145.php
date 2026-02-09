<?php
// modulos/sicopro/importar.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Obtener logs de últimas importaciones
$logsMap = [];
try {
    $logQuery = "SELECT tipo_importacion, fecha_subida, ultima_fecha_dato, registros_insertados 
                 FROM sicopro_import_log 
                 WHERE id IN (SELECT MAX(id) FROM sicopro_import_log GROUP BY tipo_importacion)";
    $stmt = $pdo->query($logQuery);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($logs as $l) $logsMap[$l['tipo_importacion']] = $l;
} catch (Exception $e) {
    // Si la tabla no existe aún, ignoramos el error visualmente
}
?>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Importación de Datos SICOPRO</h2>
        <a href="../../public/menu.php" class="btn btn-secondary">Volver al Menú</a>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-4">
            
            <div id="alerta-msg" class="alert d-none" role="alert"></div>

            <form id="formImportar" enctype="multipart/form-data">
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
                        <button type="submit" class="btn btn-primary w-100" id="btnSubir">
                            <span id="btnText"><i class="bi bi-cloud-upload"></i> Subir</span>
                            <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
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
            'SICOPRO' => ['label' => 'Base SICOPRO', 'icon' => 'bi-database'],
            'LIQUIDACIONES' => ['label' => 'Liquidaciones', 'icon' => 'bi-file-earmark-text'],
            'SIGUE' => ['label' => 'Sistema SIGUE', 'icon' => 'bi-bank']
        ];
        
        foreach($tipos as $key => $info): 
            $fecha = isset($logsMap[$key]) ? date('d/m/Y H:i', strtotime($logsMap[$key]['fecha_subida'])) : '-';
            $registros = isset($logsMap[$key]) ? number_format($logsMap[$key]['registros_insertados'], 0, ',', '.') : 0;
            $colorClass = isset($logsMap[$key]) ? 'text-success' : 'text-muted';
        ?>
        <div class="col-md-4 col-lg-3">
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

<script>
document.getElementById('formImportar').addEventListener('submit', function(e) {
    e.preventDefault(); // DETIENE la recarga de la página

    let formData = new FormData(this);
    let alerta = document.getElementById('alerta-msg');
    let btn = document.getElementById('btnSubir');
    let spinner = document.getElementById('btnSpinner');
    let btnText = document.getElementById('btnText');

    // UI Loading
    btn.disabled = true;
    btnText.classList.add('d-none');
    spinner.classList.remove('d-none');
    alerta.classList.add('d-none');
    alerta.className = 'alert d-none'; 

    // IMPORTANTE: Asegúrate de que este archivo exista en la misma carpeta
    fetch('procesar_importacion.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Verificamos si la respuesta es válida antes de procesarla
        if (!response.ok) {
            throw new Error('Error en el servidor: ' + response.status);
        }
        return response.text();
    })
    .then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            // Si falla el JSON, mostramos el error crudo del PHP
            console.error("Respuesta cruda:", text);
            throw new Error("Respuesta inesperada del servidor (ver consola).");
        }
    })
    .then(data => {
        alerta.classList.remove('d-none');
        if (data.success) {
            alerta.classList.add('alert-success');
            alerta.innerHTML = '<i class="bi bi-check-circle-fill"></i> ' + data.mensaje;
            document.getElementById('formImportar').reset();
            // Recargar para actualizar contadores
            setTimeout(() => location.reload(), 2000);
        } else {
            alerta.classList.add('alert-danger');
            alerta.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> ' + (data.error || 'Error desconocido');
        }
    })
    .catch(error => {
        alerta.classList.remove('d-none');
        alerta.classList.add('alert-danger');
        alerta.innerHTML = '<i class="bi bi-bug-fill"></i> ' + error.message;
    })
    .finally(() => {
        btn.disabled = false;
        btnText.classList.remove('d-none');
        spinner.classList.add('d-none');
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
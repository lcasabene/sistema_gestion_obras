<?php
// modulos/sicopro/importar.php
require_once __DIR__ . '/../../auth/middleware.php';
// require_login(); 
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Obtener logs de últimas importaciones
$logsMap = [];
try {
    // Obtenemos la última fecha de CADA tipo de importación
    $logQuery = "SELECT tipo_importacion, fecha_subida, registros_insertados 
                 FROM sicopro_import_log 
                 WHERE id IN (SELECT MAX(id) FROM sicopro_import_log GROUP BY tipo_importacion)";
    $stmt = $pdo->query($logQuery);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($logs as $l) {
        $logsMap[$l['tipo_importacion']] = $l;
    }
} catch (Exception $e) {
    // Si la tabla no existe, no rompemos la vista
}
?>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Importación de Datos SICOPRO</h2>
        <a href="../../public/menu.php" class="btn btn-secondary">Volver al Menú</a>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-cloud-arrow-up"></i> Cargar Nuevos Datos</h5>
        </div>
        <div class="card-body p-4">
            
            <div id="alerta-msg" class="alert d-none" role="alert"></div>

            <form id="formImportar" enctype="multipart/form-data">
                <div class="row align-items-end">
                    
                    <div class="col-md-5 mb-3">
                        <label for="tipo_importacion" class="form-label fw-bold">1. Seleccione el reporte:</label>
                        <select class="form-select" name="tipo_importacion" id="tipo_importacion" required>
                            <option value="" selected disabled>-- Seleccionar --</option>
                            
                            <optgroup label="SICOPRO Principal">
                                <option value="sicopro_original">Base SICOPRO (Completa)</option>
                            </optgroup>
                            
                            <optgroup label="Anticipos TGF">
                                <option value="tgf">1. Total Anticipado (TGF)</option>
                                <option value="solicitado">2. Solicitado y no anticipado</option>
                                <option value="anticipos">3. Anticipado sin pago prov.</option>
                            </optgroup>
                            
                            <optgroup label="Pagos y Liquidaciones">
                                <option value="liquidaciones">Liquidaciones</option>
                                <option value="sigue">Sistema SIGUE</option>
                            </optgroup>
                        </select>
                    </div>

                    <div class="col-md-5 mb-3">
                        <label for="archivo_csv" class="form-label fw-bold">2. Archivo CSV:</label>
                        <input type="file" class="form-control" name="archivo_csv" id="archivo_csv" accept=".csv, .txt, .xlsx" required>
                    </div>

                    <div class="col-md-2 mb-3">
                        <button type="submit" class="btn btn-success w-100" id="btnSubir">
                            <span id="btnText"><i class="bi bi-upload"></i> Subir</span>
                            <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12">
            <h5 class="mb-3 text-secondary border-bottom pb-2"><i class="bi bi-activity"></i> Estado de Actualizaciones</h5>
        </div>
        
        <?php 
        // Definimos las 6 tarjetas para que se dibujen siempre
        $tipos = [
            'sicopro_original' => ['label' => 'Base SICOPRO', 'icon' => 'bi-database-fill', 'color' => 'primary'],
            'tgf'              => ['label' => 'Total Anticipado', 'icon' => 'bi-cash-stack', 'color' => 'success'],
            'solicitado'       => ['label' => 'Solicitado (No Ant.)', 'icon' => 'bi-hourglass-split', 'color' => 'warning'],
            'anticipos'        => ['label' => 'Antic. Sin Pago', 'icon' => 'bi-exclamation-circle', 'color' => 'danger'],
            'liquidaciones'    => ['label' => 'Liquidaciones', 'icon' => 'bi-file-earmark-spreadsheet', 'color' => 'info'],
            'sigue'            => ['label' => 'Sistema SIGUE', 'icon' => 'bi-bank2', 'color' => 'secondary']
        ];
        
        foreach($tipos as $key => $info): 
            $existe = isset($logsMap[$key]);
            $fecha = $existe ? date('d/m/Y H:i', strtotime($logsMap[$key]['fecha_subida'])) : '-';
            $registros = $existe ? number_format($logsMap[$key]['registros_insertados'], 0, ',', '.') : 0;
            $claseBorde = $existe ? "border-{$info['color']}" : '';
            $claseTexto = $existe ? "text-{$info['color']}" : 'text-muted';
        ?>
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 shadow-sm <?= $claseBorde ?>">
                <div class="card-body text-center p-2">
                    <div class="mb-2 <?= $claseTexto ?>"><i class="bi <?= $info['icon'] ?> fs-3"></i></div>
                    <h6 class="card-title fw-bold text-dark" style="font-size: 0.8rem;"><?= $info['label'] ?></h6>
                    
                    <div class="bg-light rounded p-1 mt-2">
                        <small class="d-block text-muted" style="font-size: 0.65rem;">Última Carga</small>
                        <span class="d-block fw-bold text-dark" style="font-size: 0.8rem;"><?= $fecha ?></span>
                    </div>
                    
                    <div class="mt-1">
                        <span class="badge bg-white text-secondary border">Reg: <?= $registros ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.getElementById('formImportar').addEventListener('submit', function(e) {
    e.preventDefault(); 

    let form = this;
    let formData = new FormData(form);
    let alerta = document.getElementById('alerta-msg');
    let btn = document.getElementById('btnSubir');
    let spinner = document.getElementById('btnSpinner');
    let btnText = document.getElementById('btnText');
    
    // Validar selección
    if (!document.getElementById('tipo_importacion').value) {
        alert("Por favor seleccione un tipo de reporte.");
        return;
    }

    // UI Loading
    btn.disabled = true;
    btnText.classList.add('d-none');
    spinner.classList.remove('d-none');
    alerta.classList.add('d-none');
    alerta.className = 'alert d-none'; 

    fetch('procesar_importacion.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.ok ? r.text() : Promise.reject(r.status))
    .then(text => {
        try { return JSON.parse(text); } 
        catch (e) { throw new Error("Error leyendo respuesta del servidor."); }
    })
    .then(data => {
        alerta.classList.remove('d-none');
        if (data.success) {
            alerta.classList.add('alert-success');
            alerta.innerHTML = '<i class="bi bi-check-lg"></i> ' + data.mensaje;
            form.reset();
            setTimeout(() => location.reload(), 1500); // Recarga rápida para ver cambios
        } else {
            alerta.classList.add('alert-danger');
            alerta.innerHTML = '<i class="bi bi-x-circle"></i> ' + (data.error || 'Error desconocido');
        }
    })
    .catch(err => {
        alerta.classList.remove('d-none');
        alerta.classList.add('alert-danger');
        alerta.innerHTML = 'Error: ' + err;
    })
    .finally(() => {
        btn.disabled = false;
        btnText.classList.remove('d-none');
        spinner.classList.add('d-none');
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
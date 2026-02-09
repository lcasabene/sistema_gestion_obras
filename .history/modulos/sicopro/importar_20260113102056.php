<?php
// modulos/sicopro/importar.php
require_once __DIR__ . '/../../auth/middleware.php';
// require_login(); // Descomentar si usas login
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Obtener logs de últimas importaciones
// La consulta agrupa por tipo para obtener la última fecha de cada uno
$logsMap = [];
try {
    $logQuery = "SELECT tipo_importacion, fecha_subida, registros_insertados 
                 FROM sicopro_import_log 
                 WHERE id IN (SELECT MAX(id) FROM sicopro_import_log GROUP BY tipo_importacion)";
    $stmt = $pdo->query($logQuery);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($logs as $l) {
        $logsMap[$l['tipo_importacion']] = $l;
    }
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
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-cloud-arrow-up"></i> Cargar Nuevos Datos</h5>
        </div>
        <div class="card-body p-4">
            
            <div id="alerta-msg" class="alert d-none" role="alert"></div>

            <form id="formImportar" enctype="multipart/form-data">
                <div class="row align-items-end">
                    
                    <div class="col-md-5 mb-3">
                        <label for="tipo_importacion" class="form-label fw-bold">1. Seleccione el tipo de archivo:</label>
                        <select class="form-select" name="tipo_importacion" id="tipo_importacion" required>
                            <option value="" selected disabled>-- Seleccionar --</option>
                            
                            <optgroup label="SICOPRO Principal">
                                <option value="sicopro_original">Base SICOPRO (Completa)</option>
                            </optgroup>
                            
                            <optgroup label="Anticipos TGF">
                                <option value="tgf">1. Total Anticipado / TGF</option>
                                <option value="anticipos">2. Anticipado sin pago a proveedor</option>
                            </optgroup>
                            
                            <optgroup label="Pagos y Liquidaciones">
                                <option value="liquidaciones">Liquidaciones (Excel/CSV)</option>
                                <option value="sigue">SIGUE (Pagos/Transferencias)</option>
                            </optgroup>
                        </select>
                    </div>

                    <div class="col-md-5 mb-3">
                        <label for="archivo_csv" class="form-label fw-bold">2. Archivo CSV (.csv):</label>
                        <input type="file" class="form-control" name="archivo_csv" id="archivo_csv" accept=".csv, .txt, .xlsx" required>
                    </div>

                    <div class="col-md-2 mb-3">
                        <button type="submit" class="btn btn-success w-100" id="btnSubir">
                            <span id="btnText"><i class="bi bi-cloud-upload"></i> Subir</span>
                            <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
                    </div>
                </div>
                <div class="form-text text-muted">
                    Asegúrese de seleccionar la opción correcta para que el sistema reconozca el formato de fechas y montos.
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12">
            <h5 class="mb-3 text-secondary border-bottom pb-2"><i class="bi bi-activity"></i> Estado de Actualizaciones</h5>
        </div>
        
        <?php 
        // Array de configuración para las tarjetas.
        // Las claves ('tgf', 'sicopro_original', etc.) deben coincidir con los VALUE del select de arriba.
        $tipos = [
            'tgf' => ['label' => 'Total Anticipado (TGF)', 'icon' => 'bi-cash-coin'],
            'sicopro_original' => ['label' => 'Base SICOPRO', 'icon' => 'bi-database'],
            'liquidaciones' => ['label' => 'Liquidaciones', 'icon' => 'bi-file-earmark-text'],
            'sigue' => ['label' => 'Sistema SIGUE', 'icon' => 'bi-bank']
        ];
        
        foreach($tipos as $key => $info): 
            // Buscamos si existe log para este tipo
            $existe = isset($logsMap[$key]);
            $fecha = $existe ? date('d/m/Y H:i', strtotime($logsMap[$key]['fecha_subida'])) : '-';
            $registros = $existe ? number_format($logsMap[$key]['registros_insertados'], 0, ',', '.') : 0;
            $claseColor = $existe ? 'text-success' : 'text-muted';
            $bordeColor = $existe ? 'border-success' : '';
        ?>
        <div class="col-md-6 col-lg-3">
            <div class="card h-100 shadow-sm <?= $bordeColor ?>">
                <div class="card-body text-center p-3">
                    <div class="mb-2 text-primary"><i class="bi <?= $info['icon'] ?> fs-2"></i></div>
                    <h6 class="card-title fw-bold text-dark mb-2"><?= $info['label'] ?></h6>
                    
                    <div class="bg-light rounded p-2 mt-3">
                        <small class="d-block text-muted text-uppercase" style="font-size: 0.7rem;">Última Carga</small>
                        <span class="d-block fw-bold <?= $claseColor ?>" style="font-size: 1rem;"><?= $fecha ?></span>
                    </div>
                    
                    <div class="mt-2">
                        <span class="badge bg-secondary">Reg: <?= $registros ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.getElementById('formImportar').addEventListener('submit', function(e) {
    e.preventDefault(); // Evita recarga

    let form = this;
    let formData = new FormData(form);
    let alerta = document.getElementById('alerta-msg');
    let btn = document.getElementById('btnSubir');
    let spinner = document.getElementById('btnSpinner');
    let btnText = document.getElementById('btnText');
    let select = document.getElementById('tipo_importacion');

    // Validación simple
    if (!select.value) {
        alert("Por favor seleccione un tipo de archivo.");
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
    .then(response => {
        if (!response.ok) {
            throw new Error('Error de servidor: ' + response.status);
        }
        return response.text(); // Recibimos texto para evitar error de parseo inmediato
    })
    .then(text => {
        try {
            return JSON.parse(text); // Intentamos parsear
        } catch (e) {
            console.error("Respuesta no JSON:", text);
            throw new Error("El servidor no devolvió una respuesta válida. Revise la consola.");
        }
    })
    .then(data => {
        alerta.classList.remove('d-none');
        if (data.success) {
            alerta.classList.add('alert-success');
            alerta.innerHTML = '<i class="bi bi-check-circle-fill"></i> <strong>Éxito:</strong> ' + data.mensaje;
            form.reset();
            // Recargar página a los 2 seg para ver actualización en las tarjetas
            setTimeout(() => location.reload(), 2000);
        } else {
            alerta.classList.add('alert-danger');
            alerta.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> <strong>Error:</strong> ' + (data.error || 'Desconocido');
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
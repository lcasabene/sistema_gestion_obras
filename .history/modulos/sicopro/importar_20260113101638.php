<?php
// modulos/sicopro/importar.php
require_once __DIR__ . '/../../auth/middleware.php';
// require_login(); 
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Importación de Datos SICOPRO</h2>
        <a href="../../public/menu.php" class="btn btn-secondary">Volver al Menú</a>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Subir Archivo CSV</h5>
        </div>
        <div class="card-body p-4">
            
            <div id="alerta-msg" class="alert d-none" role="alert"></div>

            <form id="formImportar" enctype="multipart/form-data">
                
                <div class="mb-3">
                    <label for="tipo_importacion" class="form-label fw-bold">1. Tipo de Archivo:</label>
                    <select name="tipo_importacion" id="tipo_importacion" class="form-select" required>
                        <option value="" selected disabled>-- Seleccione una opción --</option>
                        <option value="tgf">TGF Anticipada / Anticipos (CSV/Excel)</option>
                        <option value="sigue">Pagos SIGUE (CSV)</option>
                        <option value="liquidaciones">Liquidaciones (CSV)</option>
                        <option value="sicopro_original">SICOPRO Completo (Archivo Grande)</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label for="archivo_csv" class="form-label fw-bold">2. Archivo:</label>
                    <input type="file" name="archivo_csv" id="archivo_csv" class="form-control" accept=".csv, .txt, .xlsx" required>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-success btn-lg" id="btnProcesar">
                        <i class="bi bi-cloud-upload-fill"></i> Importar Datos
                    </button>
                </div>
                
                <div class="progress mt-3 d-none" id="barra-progreso-container">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%">Procesando...</div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('formImportar').addEventListener('submit', function(e) {
    e.preventDefault();

    let form = this;
    let formData = new FormData(form); // Esto captura el archivo Y el select automáticamente
    let alerta = document.getElementById('alerta-msg');
    let btn = document.getElementById('btnProcesar');
    let barra = document.getElementById('barra-progreso-container');

    // Validación extra manual
    let tipo = document.getElementById('tipo_importacion').value;
    if (!tipo) {
        alert("Por favor selecciona el tipo de archivo.");
        return;
    }

    alerta.classList.add('d-none');
    alerta.classList.remove('alert-success', 'alert-danger');
    btn.disabled = true;
    btn.innerText = 'Importando...';
    barra.classList.remove('d-none');

    fetch('procesar_importacion.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error("Respuesta cruda:", text);
            throw new Error("Respuesta del servidor no válida (Ver consola).");
        }
    })
    .then(data => {
        alerta.classList.remove('d-none');
        if (data.success) {
            alerta.classList.add('alert-success');
            alerta.innerHTML = '<strong>¡Éxito!</strong> ' + data.mensaje;
            form.reset();
        } else {
            alerta.classList.add('alert-danger');
            alerta.innerHTML = '<strong>Error:</strong> ' + (data.error || 'Desconocido');
        }
    })
    .catch(error => {
        alerta.classList.remove('d-none');
        alerta.classList.add('alert-danger');
        alerta.innerHTML = '<strong>Error Crítico:</strong> ' + error.message;
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload-fill"></i> Importar Datos';
        barra.classList.add('d-none');
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
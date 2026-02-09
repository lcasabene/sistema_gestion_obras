<?php
// modulos/certificados/certificados_guardar_modal.php
// ==========================================================
// MODO DEPURACIÓN (DEBUG)
// Este archivo solo muestra datos, NO conecta a la BD ni guarda.
// ==========================================================

// Si tienes control de sesión, mantenlo para que no dé error, 
// si no, puedes comentar las siguientes 3 líneas.
require_once __DIR__ . '/../../auth/middleware.php';
// require_login(); 

echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Debug Datos</title>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "</head><body class='bg-light p-5'>";

echo "<div class='container'><div class='card shadow-lg'>";
echo "<div class='card-header bg-warning text-dark fw-bold'>🚧 MODO DEPURACIÓN: NO SE GUARDÓ NADA</div>";
echo "<div class='card-body'>";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<div class='alert alert-danger'>No se recibieron datos POST. Acceda desde el formulario.</div>";
} else {

    // 1. VERIFICACIÓN CRÍTICA DEL ID
    $curvaItemId = $_POST['curva_item_id'] ?? null;
    
    echo "<h4 class='border-bottom pb-2 mb-3'>1. Estado de la Vinculación</h4>";
    if (empty($curvaItemId)) {
        echo "<div class='alert alert-danger fw-bold d-flex align-items-center'>
                <span style='font-size: 1.5rem; margin-right:10px;'>❌</span> 
                ERROR GRAVE: 'curva_item_id' LLEGÓ VACÍO O NULO.
              </div>";
        echo "<p>Esto significa que el formulario HTML no tiene valor en el <code>input hidden</code> llamado 'curva_item_id'.</p>";
    } else {
        echo "<div class='alert alert-success fw-bold d-flex align-items-center'>
                <span style='font-size: 1.5rem; margin-right:10px;'>✅</span> 
                ÉXITO: Se recibió curva_item_id = <span class='badge bg-dark ms-2 fs-5'>$curvaItemId</span>
              </div>";
    }

    // 2. OTROS IDs IMPORTANTES
    echo "<h4 class='border-bottom pb-2 mb-3 mt-4'>2. Identificadores</h4>";
    echo "<ul class='list-group mb-3'>";
    echo "<li class='list-group-item'><strong>Certificado ID (0=Nuevo):</strong> " . ($_POST['cert_id'] ?? 'No definido') . "</li>";
    echo "<li class='list-group-item'><strong>Obra ID:</strong> " . ($_POST['obra_id'] ?? 'No definido') . "</li>";
    echo "<li class='list-group-item'><strong>Versión Prev ID:</strong> " . ($_POST['version_prev_id'] ?? 'No definido') . "</li>";
    echo "</ul>";

    // 3. DATOS DEL FORMULARIO
    echo "<h4 class='border-bottom pb-2 mb-3 mt-4'>3. Datos de Contenido</h4>";
    echo "<table class='table table-bordered table-striped'>";
    echo "<thead class='table-dark'><tr><th>Campo (name)</th><th>Valor Recibido</th></tr></thead><tbody>";
    
    $camposInteres = ['tipo', 'periodo', 'nro_certificado', 'monto_bruto', 'avance_fisico', 'fri', 'monto_neto'];
    
    foreach ($camposInteres as $key) {
        $val = $_POST[$key] ?? '-';
        echo "<tr><td><code>$key</code></td><td class='fw-bold'>$val</td></tr>";
    }
    echo "</tbody></table>";

    // 4. VUELCO COMPLETO (RAW)
    echo "<h4 class='border-bottom pb-2 mb-3 mt-4'>4. Raw Dump (\$_POST completo)</h4>";
    echo "<pre class='bg-dark text-white p-3 rounded'>";
    print_r($_POST);
    echo "</pre>";
}

echo "</div>"; // card-body
echo "<div class='card-footer text-center'>";
echo "<a href='javascript:history.back()' class='btn btn-primary btn-lg px-5'>Volver al Formulario</a>";
echo "</div>";
echo "</div></div>"; // container

echo "</body></html>";

// DETENEMOS EL SCRIPT AQUÍ PARA QUE NO INTENTE GUARDAR NADA
exit; 
?>
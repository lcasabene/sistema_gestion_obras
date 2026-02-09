<?php
// --- INICIO BLOQUE DE DIAGNÓSTICO ---
// Pega esto al principio de certificados_guardar_modal.php
// Una vez solucionado, borra este bloque.

echo "<div style='background:white; padding:20px; font-family:monospace; z-index:9999; position:relative;'>";
echo "<h2>🔍 Diagnóstico de Datos Recibidos</h2>";

echo "<b>1. ID del Certificado (cert_id):</b> ";
var_dump($_POST['cert_id'] ?? 'NO LLEGA');

echo "<br><b>2. ID de Vinculación (curva_item_id):</b> ";
var_dump($_POST['curva_item_id'] ?? 'NO LLEGA');

echo "<br><b>3. Periodo:</b> ";
var_dump($_POST['periodo'] ?? 'NO LLEGA');

echo "<br><b>4. Tipo:</b> ";
var_dump($_POST['tipo'] ?? 'NO LLEGA');

echo "<hr><h3>Todos los datos recibidos (\$_POST):</h3>";
echo "<pre>";
print_r($_POST);
echo "</pre>";
echo "</div>";
exit; // <--- IMPORTANTE: Detiene todo aquí para que no intente guardar
// --- FIN BLOQUE DE DIAGNÓSTICO ---

// ... aquí sigue tu código original (require_once, etc.)
require_once __DIR__ . '/../../auth/middleware.php';
// ...
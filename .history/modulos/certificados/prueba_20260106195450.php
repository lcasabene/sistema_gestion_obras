<?php
// modulos/certificados/test_sql.php

// 1. Conexión (Ajusta la ruta si es necesario)
require_once __DIR__ . '/../../config/database.php';

// 2. Encabezado visual
echo "<div style='font-family:sans-serif; padding:20px;'>";
echo "<h2>🚑 Diagnóstico Rápido de SQL</h2>";

// 3. Verificamos si existe la columna
echo "Checking tabla certificados... ";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM certificados LIKE 'curva_item_id'");
    $col = $stmt->fetch();
    if ($col) {
        echo "<span style='color:green; font-weight:bold;'>✔ OK (Columna 'curva_item_id' existe)</span><br>";
    } else {
        die("<br><br><h3 style='color:red'>❌ FATAL: La columna 'curva_item_id' NO EXISTE en la tabla.</h3> Agregala en PHPMyAdmin.");
    }
} catch (Exception $e) {
    die("Error conectando BD: " . $e->getMessage());
}

// 4. PRUEBA DE INSERT FORZADO
echo "<hr>Intentando insertar un registro de prueba...<br>";

try {
    // Definimos datos FIJOS (Hardcode)
    $obra_id_test = 1;  // Asegúrate de que existe la obra con ID 1
    $item_id_test = 55; // Asegúrate de que existe un item con ID 55 en curva_items
    
    // SQL simple directo
    $sql = "INSERT INTO certificados 
            (obra_id, curva_item_id, nro_certificado, tipo, periodo, monto_bruto, estado) 
            VALUES 
            ($obra_id_test, $item_id_test, 9999, 'TEST_DEBUG', '2025-01', 100.00, 'BORRADOR')";

    echo "Ejecutando SQL: <code>$sql</code><br><br>";
    
    $pdo->exec($sql);
    $nuevoId = $pdo->lastInsertId();
    
    echo "<h3 style='color:green'>✔ ¡ÉXITO! Se guardó el registro.</h3>";
    echo "ID Nuevo Certificado: <strong>$nuevoId</strong><br>";
    echo "Vinculado a Item ID: <strong>$item_id_test</strong><br>";
    
    // Verificar lectura
    $chk = $pdo->query("SELECT id, curva_item_id FROM certificados WHERE id = $nuevoId")->fetch();
    echo "Verificación en BD: El campo guardó -> " . $chk['curva_item_id'];

} catch (Exception $e) {
    echo "<h3 style='color:red'>❌ ERROR AL INSERTAR</h3>";
    echo "Mensaje MySQL: " . $e->getMessage();
    
    if (strpos($e->getMessage(), 'foreign key') !== false) {
        echo "<br><br><strong>PISTA:</strong> El error dice 'foreign key'. Significa que el ID <strong>$item_id_test</strong> no existe en la tabla <code>curva_items</code>.";
    }
}

echo "</div>";
?>
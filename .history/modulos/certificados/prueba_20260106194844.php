<?php
// modulos/certificados/test_sql.php
require_once __DIR__ . '/../../config/database.php';

echo "<h2>🚑 Diagnóstico de SQL Directo</h2>";

// 1. Validar que la columna existe
echo "checking columnas...<br>";
$stmtDesc = $pdo->query("DESCRIBE certificados");
$columnas = $stmtDesc->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('curva_item_id', $columnas)) {
    die("<h3 style='color:red'>FATAL: La columna 'curva_item_id' NO EXISTE en la tabla certificados.</h3>");
} else {
    echo "<span style='color:green'>✔ La columna 'curva_item_id' existe.</span><br>";
}

// 2. Intentar una inserción forzada (Hardcoded)
try {
    $pdo->beginTransaction();

    // DATOS DE PRUEBA (Ajusta el obra_id si no existe la obra 1)
    $obra_id_test = 1; 
    $item_id_test = 55; // El ID que intentabas guardar
    
    echo "Intentando insertar con curva_item_id = $item_id_test ...<br>";

    $sql = "INSERT INTO certificados 
            (obra_id, curva_item_id, nro_certificado, tipo, periodo, fecha_medicion, monto_bruto, estado)
            VALUES 
            (:obra, :item_id, 999, 'PRUEBA_SQL', '2024-01', NOW(), 100, 'BORRADOR')";
    
    $stmt = $pdo->prepare($sql);
    
    // Ejecutamos y capturamos error específico
    if (!$stmt->execute([
        ':obra' => $obra_id_test,
        ':item_id' => $item_id_test
    ])) {
        $err = $stmt->errorInfo();
        throw new Exception("Error SQL: " . $err[2]);
    }

    $id_insertado = $pdo->lastInsertId();
    echo "<h3 style='color:green'>✔ ÉXITO: Se insertó el certificado ID $id_insertado con curva_item_id = $item_id_test</h3>";
    
    // 3. Verificar qué se guardó realmente
    $stmtVer = $pdo->query("SELECT id, curva_item_id FROM certificados WHERE id = $id_insertado");
    $row = $stmtVer->fetch(PDO::FETCH_ASSOC);
    
    echo "Verificación en BD: ID=" . $row['id'] . " | curva_item_id=" . var_export($row['curva_item_id'], true);
    
    // Deshacer cambios para no ensuciar la base
    $pdo->rollBack();
    echo "<br><br><em>(Prueba finalizada, se hizo Rollback para no dejar basura)</em>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<div style='background:red; color:white; padding:20px;'>";
    echo "<h3>❌ ERROR AL GUARDAR</h3>";
    echo "Mensaje: " . $e->getMessage();
    echo "</div>";
    
    // Análisis de causa probable
    if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
        echo "<br><strong>DIAGNÓSTICO:</strong> El ID $item_id_test NO EXISTE en la tabla 'curva_items'. No puedes vincular un certificado a un item que no existe.";
    }
}
?>
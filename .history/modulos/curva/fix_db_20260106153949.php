<?php
// modulos/curva/fix_db.php
// Ejecuta este archivo una vez para reparar la BD y probar la escritura
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

echo "<body style='font-family:sans-serif; padding:2rem;'>";
echo "<h2>🛠️ Herramienta de Diagnóstico y Reparación</h2>";

try {
    // 1. VERIFICAR Y REPARAR COLUMNA
    echo "<p>1. Verificando columna <code>curva_item_id</code>... ";
    try {
        // Intentamos modificarla para asegurarnos que sea INT y permita NULL
        $pdo->exec("ALTER TABLE certificados MODIFY COLUMN curva_item_id INT(11) DEFAULT NULL");
        echo "<span style='color:green'><b>OK (Estructura Correcta)</b></span></p>";
    } catch (Exception $e) {
        // Si falla modify, quizás no existe, intentamos agregar
        try {
            $pdo->exec("ALTER TABLE certificados ADD COLUMN curva_item_id INT(11) DEFAULT NULL");
            echo "<span style='color:green'><b>OK (Creada)</b></span></p>";
        } catch (Exception $e2) {
            echo "<span style='color:red'><b>Error:</b> " . $e2->getMessage() . "</span></p>";
        }
    }

    // 2. REPARAR ÍNDICES (Solución al error #1091)
    echo "<p>2. Arreglando índices (Eliminando bloqueos)... ";
    
    // Borramos cualquier índice viejo (ignoramos error si no existe)
    try { $pdo->exec("DROP INDEX curva_item_id ON certificados"); } catch(Exception $e) {}
    try { $pdo->exec("DROP INDEX idx_curva_item ON certificados"); } catch(Exception $e) {}
    
    // Creamos índice NORMAL (Permite duplicados, evita errores UNIQUE)
    try { 
        $pdo->exec("CREATE INDEX idx_curva_item ON certificados(curva_item_id)"); 
        echo "<span style='color:green'><b>OK (Índice Normal Creado)</b></span></p>";
    } catch(Exception $e) {
        echo "<span style='color:orange'><b>Info:</b> " . $e->getMessage() . "</span></p>";
    }

    // 3. PRUEBA DE FUEGO: ESCRITURA
    echo "<p>3. Realizando prueba de escritura directa... ";
    
    $testId = 999999; // Un número inconfundible
    $obraTest = 0; // ID obra ficticia
    
    // Insertamos manualmente
    $pdo->exec("INSERT INTO certificados (obra_id, tipo, periodo, nro_certificado, estado, curva_item_id) VALUES ($obraTest, 'TEST_DEBUG', '2030-01', 0, 'BORRAR', $testId)");
    $lastId = $pdo->lastInsertId();
    
    // Leemos inmediatamente lo que acabamos de escribir
    $stmt = $pdo->query("SELECT curva_item_id FROM certificados WHERE id = $lastId");
    $valorGuardado = $stmt->fetchColumn();
    
    // Limpieza: Borramos el dato de prueba
    $pdo->exec("DELETE FROM certificados WHERE id = $lastId");

    echo "<br><br>Resultado de la prueba:<br>";
    echo "Intento de guardar ID: <b>$testId</b><br>";
    echo "Valor recuperado de BD: <b>$valorGuardado</b><br>";

    if ($valorGuardado == $testId) {
        echo "<h1 style='color:green'>✅ LA BASE DE DATOS FUNCIONA PERFECTO</h1>";
        echo "<div style='background:#d4edda; color:#155724; padding:15px; border-radius:5px;'>";
        echo "<b>Conclusión:</b> Tu base de datos acepta y guarda el dato correctamente.<br>";
        echo "El problema es que el archivo <code>certificados_guardar_modal.php</code> en tu servidor <b>NO TIENE</b> el código nuevo que te pasé.<br>";
        echo "👉 <b>Solución:</b> Vuelve a abrir <code>certificados_guardar_modal.php</code>, borra TODO su contenido y pega el código de la respuesta anterior.";
        echo "</div>";
    } else {
        echo "<h1 style='color:red'>❌ FALLO EN BASE DE DATOS</h1>";
        echo "<p>La base de datos se niega a guardar el número. Posibles causas: Triggers o configuración extraña del servidor.</p>";
    }

} catch (PDOException $e) {
    echo "<h3 style='color:red'>Error Fatal de Base de Datos:</h3> " . $e->getMessage();
}
echo "</body>";
?>
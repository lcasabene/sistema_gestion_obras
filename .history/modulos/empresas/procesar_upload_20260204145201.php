<?php
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['archivo_csv'])) {
    $file = $_FILES['archivo_csv'];

    if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        die("Error: Solo se permiten archivos CSV.");
    }

    if (($gestor = fopen($file['tmp_name'], "r")) !== FALSE) {
        // Omitir cabecera
        fgetcsv($gestor, 1000, ";");

        try {
            // Usamos "?" para evitar errores de parámetros no definidos con EMULATE_PREPARES => false
            $stmtCheck  = $pdo->prepare("SELECT id FROM empresas WHERE cuit = ? LIMIT 1");
            $stmtUpdate = $pdo->prepare("UPDATE empresas SET codproveedor = ? WHERE cuit = ?");
            $stmtInsert = $pdo->prepare("INSERT INTO empresas (nombre, cuit, codproveedor) VALUES (?, ?, ?)");

            $resumen = ['procesados' => 0, 'insertados' => 0, 'actualizados' => 0, 'errores' => 0];

            echo "<h2>Resultado del proceso:</h2><ul>";

            while (($datos = fgetcsv($gestor, 1000, ";")) !== FALSE) {
                // Mapeo: 1: Razon Social, 2: Titular, 3: CUIT, 4: Codproveedor
                $razonSocial = isset($datos[1]) ? trim($datos[1]) : '';
                $titular     = isset($datos[2]) ? trim($datos[2]) : '';
                $cuit        = isset($datos[3]) ? preg_replace('/[^0-9]/', '', $datos[3]) : '';
                
                // Limpieza de codproveedor y manejo de \N
                $codProvRaw  = isset($datos[4]) ? trim($datos[4]) : '';
                $codProv     = ($codProvRaw === '\N' || $codProvRaw === '') ? null : $codProvRaw;

                // Validación CUIT 11 dígitos
                if (strlen($cuit) !== 11) {
                    $resumen['errores']++;
                    continue;
                }

                // LÓGICA DE NOMBRE: Prioridad Titular -> Razón Social
                $nombreFinal = ($titular !== '\N' && !empty($titular)) ? $titular : $razonSocial;

                try {
                    // 1. Verificar si existe
                    $stmtCheck->execute([$cuit]);
                    $empresa = $stmtCheck->fetch();

                    if ($empresa) {
                        // 2. Actualizar: el orden de los datos en el array debe coincidir con los "?"
                        // SQL: SET codproveedor = ? WHERE cuit = ?
                        $stmtUpdate->execute([$codProv, $cuit]);
                        $resumen['actualizados']++;
                    } else {
                        // 3. Insertar: (nombre, cuit, codproveedor)
                        // SQL: VALUES (?, ?, ?)
                        $stmtInsert->execute([$nombreFinal, $cuit, $codProv]);
                        $resumen['insertados']++;
                    }
                    $resumen['procesados']++;

                } catch (Exception $e) {
                    echo "<li><span style='color:red;'>Error en CUIT $cuit:</span> " . $e->getMessage() . "</li>";
                    $resumen['errores']++;
                }
            }
            
            fclose($gestor);
            echo "</ul>";
            echo "<h3>Proceso terminado</h3>";
            echo "Nuevas empresas: {$resumen['insertados']}<br>";
            echo "Empresas actualizadas: {$resumen['actualizados']}<br>";
            echo "Registros omitidos/errores: {$resumen['errores']}<br>";
            echo "<br><a href='subir_proveedores.php' style='padding:10px; background:#007bff; color:white; text-decoration:none; border-radius:5px;'>Volver a subir</a>";

        } catch (PDOException $e) {
            echo "<div style='color:red; border:1px solid red; padding:10px;'>";
            echo "<strong>Error Crítico de Base de Datos:</strong> " . $e->getMessage();
            echo "</div>";
        }
    }
} else {
    header("Location: subir_proveedores.php");
}
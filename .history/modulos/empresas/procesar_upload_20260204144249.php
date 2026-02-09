<?php
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['archivo_csv'])) {
    $file = $_FILES['archivo_csv'];

    // Validar extensión
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if ($ext !== 'csv') {
        die("Error: Solo se permiten archivos CSV.");
    }

    if (($gestor = fopen($file['tmp_name'], "r")) !== FALSE) {
        // Omitir cabecera
        fgetcsv($gestor, 1000, ";");

        $stmtCheck = $pdo->prepare("SELECT id FROM empresas WHERE cuit = :cuit LIMIT 1");
        $stmtUpdate = $pdo->prepare("UPDATE empresas SET codproveedor = :codproveedor WHERE cuit = :cuit");
        $stmtInsert = $pdo->prepare("INSERT INTO empresas (nombre, cuit, codproveedor) VALUES (:nombre, :cuit, :codproveedor)");

        $resumen = ['procesados' => 0, 'insertados' => 0, 'actualizados' => 0, 'errores' => 0];

        echo "<h2>Resultado del proceso:</h2><ul>";

        while (($datos = fgetcsv($gestor, 1000, ";")) !== FALSE) {
            // Mapeo: [1] RAZON SOCIAL, [2] titular, [3] cuit, [4] codproveedor
            $razonSocial = trim($datos[1] ?? '');
            $titular     = trim($datos[2] ?? '');
            $cuit        = preg_replace('/[^0-9]/', '', $datos[3] ?? '');
            $codProv     = ($datos[4] === '\N' || empty($datos[4])) ? null : trim($datos[4]);

            // Validación CUIT 11 dígitos
            if (strlen($cuit) !== 11) {
                echo "<li><span style='color:orange;'>CUIT Inválido ($cuit) - Saltado.</span></li>";
                $resumen['errores']++;
                continue;
            }

            $nombreFinal = ($titular !== '\N' && !empty($titular)) ? $titular : $razonSocial;

            try {
                $stmtCheck->execute([':cuit' => $cuit]);
                $empresa = $stmtCheck->fetch();

                if ($empresa) {
                    $stmtUpdate->execute([':codproveedor' => $codProv, ':cuit' => $cuit]);
                    $resumen['actualizados']++;
                } else {
                    $stmtInsert->execute([':nombre' => $nombreFinal, ':cuit' => $cuit, ':codproveedor' => $codProv]);
                    $resumen['insertados']++;
                }
                $resumen['procesados']++;

            } catch (Exception $e) {
                echo "<li>Error en CUIT $cuit: " . $e->getMessage() . "</li>";
                $resumen['errores']++;
            }
        }
        fclose($gestor);

        echo "</ul>";
        echo "<strong>Proceso terminado.</strong><br>";
        echo "Insertados: {$resumen['insertados']} | Actualizados: {$resumen['actualizados']} | Errores/Saltados: {$resumen['errores']}";
        echo "<br><br><a href='subir_proveedores.php'>Volver a subir</a>";
    }
} else {
    header("Location: subir_proveedores.php");
}
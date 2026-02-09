<?php
// modulos/sicopro/procesar_importacion.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Configuración para archivos grandes
set_time_limit(300); 
ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. VALIDACIONES
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido.');
    }

    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error en la subida del archivo. Código: ' . ($_FILES['archivo_csv']['error'] ?? 'N/A'));
    }

    $fileTmpPath = $_FILES['archivo_csv']['tmp_name'];
    $tipo_recibido = trim($_POST['tipo_archivo'] ?? '');

    // 2. AUTO-DETECCIÓN INTELIGENTE (Para evitar errores de selección)
    $handle = fopen($fileTmpPath, "r");
    if ($handle === FALSE) throw new Exception('No se pudo leer el archivo temporal.');
    
    $primera_linea = fgets($handle);
    rewind($handle); // Volver al inicio

    // Detectar delimitador (; o ,)
    $delimiter = (strpos($primera_linea, ';') !== false) ? ';' : ',';

    // Determinar tabla destino
    $tabla_destino = '';
    
    // Alias conocidos
    $mapa_alias = [
        'sigue' => 'sicopro_sigue', 'sicopro_sigue' => 'sicopro_sigue',
        'principal' => 'sicopro_principal', 'sicopro_principal' => 'sicopro_principal',
        'liquidaciones' => 'sicopro_liquidaciones', 'sicopro_liquidaciones' => 'sicopro_liquidaciones',
        'tgf' => 'sicopro_anticipos_tgf', 'sicopro_anticipos_tgf' => 'sicopro_anticipos_tgf'
    ];
    
    if (array_key_exists($tipo_recibido, $mapa_alias)) {
        $tabla_destino = $mapa_alias[$tipo_recibido];
    }

    // Si no coincide, intentar adivinar por contenido
    if (empty($tabla_destino)) {
        if (stripos($primera_linea, 'LIQN') !== false) {
            $tabla_destino = 'sicopro_sigue';
        } elseif (stripos($primera_linea, 'movejer') !== false) {
            $tabla_destino = 'sicopro_principal';
        } elseif (stripos($primera_linea, 'imp_a_pagar') !== false || stripos($primera_linea, 'nro_liquidacion') !== false) {
            $tabla_destino = 'sicopro_liquidaciones';
        } elseif (stripos($primera_linea, 'saf') !== false && stripos($primera_linea, 'anticipo') !== false) {
            $tabla_destino = 'sicopro_anticipos_tgf';
        }
    }

    if (empty($tabla_destino)) {
        throw new Exception("No se pudo identificar el tipo de archivo. Encabezado: " . substr($primera_linea, 0, 40));
    }

    // 3. INICIO DE TRANSACCIÓN
    $pdo->beginTransaction();

    // CORRECCIÓN CLAVE: Usamos DELETE FROM en lugar de TRUNCATE
    // TRUNCATE hacía un commit automático rompiendo la transacción.
    $pdo->exec("DELETE FROM $tabla_destino");

    // 4. PREPARAR SQL
    $stmt = null;
    
    if ($tabla_destino === 'sicopro_sigue') {
        $sql = "INSERT INTO sicopro_sigue (liqn, numero, fecha, nro_pago, tipo, importe, obs_lote, ejer) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_principal') {
        $sql = "INSERT INTO sicopro_principal (movejer, movtrju, movexpe, movtrti, movtrnu, movfufi, movfeop, movnupa, movprov, movrefe, movimpo, movfech, movcomp, movfere) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_liquidaciones') {
        $sql = "INSERT INTO sicopro_liquidaciones (ejer, op_sicopro, nro_liquidacion, imp_a_pagar, imp_retenciones, imp_liq, beneficiario) VALUES (?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_anticipos_tgf') {
        $sql = "INSERT INTO sicopro_anticipos_tgf (ejer, saf, o_pago, f_anticipo, tipo_origen, total_anticipado, cta_bancaria) VALUES (?, ?, ?, ?, ?, ?, ?)";
    }
    
    $stmt = $pdo->prepare($sql);

    // 5. PROCESAR FILAS
    $filas = 0;
    
    // Helpers limpieza
    $cleanDate = function($d) {
        if (!$d || trim($d) == '' || strtoupper($d) == 'NULL') return null;
        $d = trim($d);
        if (strpos($d, '/') !== false) {
            $parts = explode('/', $d);
            if(count($parts) == 3) {
                if(strlen($parts[2]) == 2) $parts[2] = '20'.$parts[2];
                return $parts[2].'-'.$parts[1].'-'.$parts[0];
            }
        }
        return $d; 
    };
    
    $cleanMoneyArg = function($v) {
        if (!$v) return 0;
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
        return floatval($v);
    };

    // Saltar encabezado
    fgetcsv($handle, 0, $delimiter);

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        
        // --- SIGUE ---
        if ($tabla_destino === 'sicopro_sigue') {
            if (empty($data[0]) || $data[0] == '0') continue;
            
            // IMPORTANTE: Tomamos el importe directo (ya viene con punto)
            $importe = isset($data[8]) ? floatval($data[8]) : 0;
            $fecha = $cleanDate($data[2]);
            $ejer = $fecha ? date('Y', strtotime($fecha)) : date('Y');

            $stmt->execute([
                trim($data[0]), trim($data[1]), $fecha, trim($data[3]), 
                trim($data[4]), $importe, utf8_encode($data[9] ?? ''), $ejer
            ]);
            $filas++;
        }
        
        // --- PRINCIPAL ---
        elseif ($tabla_destino === 'sicopro_principal') {
            if (in_array(trim($data[3]??''), ['AA', 'RE', 'AC', 'CO', 'AP'])) continue;
            if (count($data) < 14) continue;

            $stmt->execute([
                $data[0], $data[1], $data[2], $data[3], $data[4], $data[5],
                $cleanDate($data[6]), $data[7], utf8_encode($data[8]), utf8_encode($data[9]),
                $cleanMoneyArg($data[10]), // Usa formato Arg
                $cleanDate($data[11]), $data[12], $cleanDate($data[13])
            ]);
            $filas++;
        }
        
        // --- LIQUIDACIONES ---
        elseif ($tabla_destino === 'sicopro_liquidaciones') {
            if (count($data) < 6) continue;
            $stmt->execute([
                $data[0], $data[1], $data[2],
                $cleanMoneyArg($data[3]), $cleanMoneyArg($data[4]), 
                $cleanMoneyArg($data[5]), utf8_encode($data[6]??'')
            ]);
            $filas++;
        }
        
        // --- TGF ---
        elseif ($tabla_destino === 'sicopro_anticipos_tgf') {
             $stmt->execute([
                $data[0], $data[1], $data[2],
                $cleanDate($data[3]), $data[4],
                $cleanMoneyArg($data[5]), $data[6]??''
            ]);
            $filas++;
        }
    }

    $pdo->commit(); // Ahora sí funcionará porque DELETE FROM mantuvo la transacción viva
    fclose($handle);
    
    echo json_encode([
        'success' => true, 
        'mensaje' => "Importación Exitosa. Archivo detectado: '$tabla_destino'. Registros cargados: $filas."
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    echo json_encode(['error' => $e->getMessage()]);
}
?>
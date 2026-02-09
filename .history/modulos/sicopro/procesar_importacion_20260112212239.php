<?php
// modulos/sicopro/procesar_importacion.php

// 1. ACTIVAR REPORTE DE ERRORES (Para diagnóstico de pantalla blanca)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configuración para archivos grandes
set_time_limit(300); 
ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');

try {
    // 2. VERIFICACIÓN DE RUTAS ROBUSTA
    $path_auth = __DIR__ . '/../../auth/middleware.php';
    $path_db   = __DIR__ . '/../../config/database.php';
    
    if (!file_exists($path_auth)) throw new Exception("No se encuentra el archivo: middleware.php");
    if (!file_exists($path_db)) throw new Exception("No se encuentra el archivo: database.php");

    require_once $path_auth;
    require_login();
    require_once $path_db;

    // 3. VALIDACIONES
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido (Use POST).');
    }

    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error en subida. Código PHP: ' . ($_FILES['archivo_csv']['error'] ?? 'N/A'));
    }

    $fileTmpPath = $_FILES['archivo_csv']['tmp_name'];
    
    // CORRECCIÓN: Leemos 'tipo_importacion' (del form) O 'tipo_archivo' (backup)
    $tipo_recibido = trim($_POST['tipo_importacion'] ?? $_POST['tipo_archivo'] ?? '');

    // 4. AUTO-DETECCIÓN INTELIGENTE
    $handle = fopen($fileTmpPath, "r");
    if ($handle === FALSE) throw new Exception('Error al abrir archivo temporal.');
    
    $primera_linea = fgets($handle);
    rewind($handle);

    $delimiter = (strpos($primera_linea, ';') !== false) ? ';' : ',';

    $tabla_destino = '';
    
    // Mapa de Alias (Lo que manda el HTML -> Tabla Real)
    $mapa_alias = [
        'SIGUE' => 'sicopro_sigue', 
        'sigue' => 'sicopro_sigue',
        
        'SICOPRO' => 'sicopro_principal', 
        'sicopro_principal' => 'sicopro_principal',
        
        'LIQUIDACIONES' => 'sicopro_liquidaciones',
        'liquidaciones' => 'sicopro_liquidaciones',
        
        'TOTAL_ANTICIPADO' => 'sicopro_anticipos_tgf',
        'SOLICITADO' => 'sicopro_anticipos_tgf',
        'SIN_PAGO' => 'sicopro_anticipos_tgf',
        'tgf' => 'sicopro_anticipos_tgf'
    ];
    
    if (array_key_exists($tipo_recibido, $mapa_alias)) {
        $tabla_destino = $mapa_alias[$tipo_recibido];
    }

    // Adivinanza por contenido (si el select falló)
    if (empty($tabla_destino)) {
        if (stripos($primera_linea, 'LIQN') !== false) $tabla_destino = 'sicopro_sigue';
        elseif (stripos($primera_linea, 'movejer') !== false) $tabla_destino = 'sicopro_principal';
        elseif (stripos($primera_linea, 'imp_a_pagar') !== false) $tabla_destino = 'sicopro_liquidaciones';
        elseif (stripos($primera_linea, 'saf') !== false) $tabla_destino = 'sicopro_anticipos_tgf';
    }

    if (empty($tabla_destino)) {
        throw new Exception("Tipo de archivo desconocido. Encabezado: " . substr($primera_linea, 0, 30));
    }

    // 5. TRANSACCIÓN
    $pdo->beginTransaction();
    // Usamos DELETE FROM para no romper la transacción
    $pdo->exec("DELETE FROM $tabla_destino");

    // 6. PREPARAR SENTENCIAS
    $stmt = null;
    if ($tabla_destino === 'sicopro_sigue') {
        $sql = "INSERT INTO sicopro_sigue (liqn, numero, fecha, nro_pago, tipo, importe, obs_lote, ejer) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_principal') {
        $sql = "INSERT INTO sicopro_principal (movejer, movtrju, movexpe, movtrti, movtrnu, movfufi, movfeop, movnupa, movprov, movrefe, movimpo, movfech, movcomp, movfere) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_liquidaciones') {
        $sql = "INSERT INTO sicopro_liquidaciones (ejer, op_sicopro, nro_liquidacion, imp_a_pagar, imp_retenciones, imp_liq, beneficiario) VALUES (?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_anticipos_tgf') {
        // NOTA: Asumimos que ya creaste las columnas faltantes (total_anticipado, etc)
        // Si no, este INSERT fallará.
        $sql = "INSERT INTO sicopro_anticipos_tgf (ejer, saf, o_pago, f_anticipo, tipo_origen, total_anticipado, cta_bancaria) VALUES (?, ?, ?, ?, ?, ?, ?)";
    }
    
    $stmt = $pdo->prepare($sql);

    // 7. PROCESAR
    $filas = 0;
    
    $cleanDate = function($d) {
        if (!$d || trim($d) == '' || stripos($d, 'NULL') !== false) return null;
        $d = trim($d);
        if (strpos($d, '/') !== false) {
            $p = explode('/', $d);
            if(count($p) == 3) {
                if(strlen($p[2]) == 2) $p[2] = '20'.$p[2];
                return $p[2].'-'.$p[1].'-'.$p[0];
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

    fgetcsv($handle, 0, $delimiter); // Saltar header

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        
        if ($tabla_destino === 'sicopro_sigue') {
            if (empty($data[0]) || $data[0] == '0') continue;
            // IMPORTANTE: Sigue trae punto decimal en el CSV. Usar floatval.
            $importe = isset($data[8]) ? floatval($data[8]) : 0;
            $fecha = $cleanDate($data[2]);
            $ejer = $fecha ? date('Y', strtotime($fecha)) : date('Y');
            
            $stmt->execute([
                trim($data[0]), trim($data[1]), $fecha, trim($data[3]), 
                trim($data[4]), $importe, utf8_encode($data[9] ?? ''), $ejer
            ]);
            $filas++;
        }
        elseif ($tabla_destino === 'sicopro_principal') {
            if (in_array(trim($data[3]??''), ['AA', 'RE', 'AC', 'CO', 'AP'])) continue;
            if (count($data) < 14) continue;
            
            $stmt->execute([
                $data[0], $data[1], $data[2], $data[3], $data[4], $data[5],
                $cleanDate($data[6]), $data[7], utf8_encode($data[8]), utf8_encode($data[9]),
                $cleanMoneyArg($data[10]), // Formato Arg
                $cleanDate($data[11]), $data[12], $cleanDate($data[13])
            ]);
            $filas++;
        }
        elseif ($tabla_destino === 'sicopro_liquidaciones') {
            if (count($data) < 6) continue;
            $stmt->execute([
                $data[0], $data[1], $data[2],
                $cleanMoneyArg($data[3]), $cleanMoneyArg($data[4]), 
                $cleanMoneyArg($data[5]), utf8_encode($data[6]??'')
            ]);
            $filas++;
        }
        elseif ($tabla_destino === 'sicopro_anticipos_tgf') {
             $stmt->execute([
                $data[0], $data[1], $data[2],
                $cleanDate($data[3]), $data[4],
                $cleanMoneyArg($data[5]), $data[6]??''
            ]);
            $filas++;
        }
    }

    // LOG DE AUDITORÍA (Opcional, si tienes la tabla)
    try {
        $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, fecha_subida, registros_insertados) VALUES (?, NOW(), ?)";
        $pdo->prepare($logSql)->execute([$tipo_recibido ?: $tabla_destino, $filas]);
    } catch(Exception $e) {}

    $pdo->commit();
    fclose($handle);
    
    echo json_encode([
        'success' => true, 
        'mensaje' => "Se importaron $filas registros en '$tabla_destino'."
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($handle) && is_resource($handle)) fclose($handle);
    
    // Devolvemos el error JSON para que el JS lo muestre
    echo json_encode(['error' => $e->getMessage()]);
}
?>
<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Aumentar límites para archivos grandes
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: importar.php');
    exit;
}

$tipo = $_POST['tipo_importacion'];
$archivo = $_FILES['archivo_csv'];

if ($archivo['error'] !== UPLOAD_ERR_OK) {
    header('Location: importar.php?status=error&msg=Error al subir archivo');
    exit;
}

$es_sicopro_base = ($tipo === 'SICOPRO');

// DEFINICIÓN DE COLUMNAS (Array para evitar errores de conteo manual)
$cols_sicopro_arr = [
    'movejer', 'movtrju', 'movtrsa', 'movtrti', 'movtrnu', 'movtrse', 'movexpe', 'movalex', 'movjuri', 'movsa', 
    'movunor', 'movfina', 'movfunc', 'movsecc', 'movsect', 'movppal', 'movppar', 'movspar', 'movfufi', 'movubge', 
    'movatn1', 'movatn2', 'movatn3', 'movcpn1', 'movcpn2', 'movcpn3', 'movncde', 'movsade', 'movscde', 'movande', 
    'movnccr', 'movsacr', 'movsccr', 'movancr', 'movprov', 'movatju', 'movatsa', 'movatti', 'movatnu', 'movatse', 
    'movimpo', 'movdile', 'movrefe', 'movceco', 'movitem', 'movfeop', 'movnufa', 'movfefa', 'movvefa', 'movnuce', 
    'movfece', 'movvece', 'movtice', 'movnuoc', 'movveoc', 'movnupa', 'movfepa', 'movlupa', 'movnure', 'movorfi', 
    'movnuob', 'movtiga', 'movmaej', 'movfede', 'movcomp', 'movnump', 'movtire', 'movlega', 'movtdre', 'movndre', 
    'movorin', 'movtgar', 'movenem', 'movcede', 'movnuca', 'movtdga', 'movnuga', 'movnupr', 'movnuco', 'movtico', 
    'movvano', 'movcocr', 'movimcr', 'movctti', 'movctej', 'movctrud', 'movctctd', 'movctded', 'movctcld', 'movctruc', 
    'movctctc', 'movctdec', 'movctclc', 'movfere', 'movhore', 'movoper'
];

$columnas_esperadas_sicopro = count($cols_sicopro_arr); // Esto calculará 96 automáticamente
$columnas_esperadas_tgf = 15;

try {
    $pdo->beginTransaction();

    if ($es_sicopro_base) {
        // --- LÓGICA SICOPRO ---
        $pdo->exec("TRUNCATE TABLE sicopro_principal");
        
        // Construcción dinámica del SQL para asegurar coincidencia exacta
        $columnas_sql = implode(', ', $cols_sicopro_arr);
        $placeholders = rtrim(str_repeat('?,', $columnas_esperadas_sicopro), ',');
        
        $sql = "INSERT INTO sicopro_principal ($columnas_sql) VALUES ($placeholders)";

    } else {
        // --- LÓGICA TGF (Anticipos) ---
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_anticipos_tgf WHERE tipo_origen = ?");
        $stmtDel->execute([$tipo]);
        
        $sql = "INSERT INTO sicopro_anticipos_tgf (
            tipo_origen, ejer, vto_comp, nro_tgf, actuacion, o_pago, proveedor, n_comp, 
            importe, n_lib, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    }

    $stmt = $pdo->prepare($sql);
    
    // Leer CSV
    $handle = fopen($archivo['tmp_name'], "r");
    $header = fgetcsv($handle, 0, ","); 
    
    // Detección de delimitador (Coma o Punto y Coma)
    if ($header && count($header) < 2) {
        rewind($handle);
        $header = fgetcsv($handle, 0, ";");
        $delimiter = ";";
    } else {
        $delimiter = ",";
    }
    // Volver al principio si leímos el header para detectar
    rewind($handle);
    fgetcsv($handle, 0, $delimiter); // Saltar header real

    $count = 0;
    $max_movfere = null;
    $fila_nro = 1;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        $fila_nro++;
        
        if (count($data) < 2) continue; // Saltar filas vacías

        // Limpieza básica
        $data = array_map(function($val) { 
            return ($val === '' || $val === ' ') ? null : trim($val); 
        }, $data);

        if ($es_sicopro_base) {
            // AJUSTE DINÁMICO SICOPRO
            // 1. Cortar si sobra
            if (count($data) > $columnas_esperadas_sicopro) {
                $data = array_slice($data, 0, $columnas_esperadas_sicopro);
            } 
            // 2. Rellenar si falta
            elseif (count($data) < $columnas_esperadas_sicopro) {
                $data = array_pad($data, $columnas_esperadas_sicopro, null);
            }

            // Capturar MOVFERE (Antepenúltima columna)
            // Usamos índice relativo para no fallar si cambia el count
            $idx_fecha = $columnas_esperadas_sicopro - 3; 
            $fecha_row = $data[$idx_fecha] ?? null; 
            
            if ($fecha_row && ($max_movfere === null || $fecha_row > $max_movfere)) {
                $max_movfere = $fecha_row;
            }
            
            try {
                $stmt->execute($data);
                $count++;
            } catch (PDOException $e) {
                throw new Exception("Error Fila $fila_nro SICOPRO: " . $e->getMessage());
            }

        } else {
            // AJUSTE TGF
            if (count($data) > $columnas_esperadas_tgf) {
                $data = array_slice($data, 0, $columnas_esperadas_tgf);
            } elseif (count($data) < $columnas_esperadas_tgf) {
                $data = array_pad($data, $columnas_esperadas_tgf, null);
            }

            array_unshift($data, $tipo); // Agregar tipo al inicio

            try {
                $stmt->execute($data);
                $count++;
            } catch (PDOException $e) {
                throw new Exception("Error Fila $fila_nro TGF: " . $e->getMessage());
            }
        }
    }
    fclose($handle);

    // Log
    $user_id = $_SESSION['user_id'] ?? 0;
    $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, usuario_id, nombre_archivo, registros_insertados, ultima_fecha_dato) VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($logSql)->execute([$tipo, $user_id, $archivo['name'], $count, $max_movfere]);

    $pdo->commit();
    header('Location: importar.php?status=success');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error Importación: " . $e->getMessage());
    header('Location: importar.php?status=error&msg=' . urlencode(substr($e->getMessage(), 0, 250)));
}
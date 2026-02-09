<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Aumentar tiempo de ejecución y memoria para archivos grandes (SICOPRO)
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

// CONFIGURACIÓN DE COLUMNAS ESPERADAS
// TGF tiene 15 columnas de datos.
// SICOPRO tiene 94 columnas de datos (según análisis de tu archivo).
$columnas_esperadas_tgf = 15;
$columnas_esperadas_sicopro = 94;

try {
    // Iniciamos transacción
    $pdo->beginTransaction();

    if ($es_sicopro_base) {
        // 1. LÓGICA SICOPRO
        // Borrar tabla completa
        $pdo->exec("TRUNCATE TABLE sicopro_principal");
        
        // Generamos placeholders dinámicos exactos (94 signos de pregunta)
        $placeholders = rtrim(str_repeat('?,', $columnas_esperadas_sicopro), ',');
        
        $sql = "INSERT INTO sicopro_principal (
            movejer, movtrju, movtrsa, movtrti, movtrnu, movtrse, movexpe, movalex, movjuri, movsa, 
            movunor, movfina, movfunc, movsecc, movsect, movppal, movppar, movspar, movfufi, movubge, 
            movatn1, movatn2, movatn3, movcpn1, movcpn2, movcpn3, movncde, movsade, movscde, movande, 
            movnccr, movsacr, movsccr, movancr, movprov, movatju, movatsa, movatti, movatnu, movatse, 
            movimpo, movdile, movrefe, movceco, movitem, movfeop, movnufa, movfefa, movvefa, movnuce, 
            movfece, movvece, movtice, movnuoc, movveoc, movnupa, movfepa, movlupa, movnure, movorfi, 
            movnuob, movtiga, movmaej, movfede, movcomp, movnump, movtire, movlega, movtdre, movndre, 
            movorin, movtgar, movenem, movcede, movnuca, movtdga, movnuga, movnupr, movnuco, movtico, 
            movvano, movcocr, movimcr, movctti, movctej, movctrud, movctctd, movctded, movctcld, movctruc, 
            movctctc, movctdec, movctclc, movfere, movhore, movoper
        ) VALUES ($placeholders)";

    } else {
        // 2. LÓGICA TGF (Anticipos)
        // Borrar solo los registros de este tipo
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_anticipos_tgf WHERE tipo_origen = ?");
        $stmtDel->execute([$tipo]);
        
        // 1 (tipo) + 15 (datos) = 16 placeholders
        $sql = "INSERT INTO sicopro_anticipos_tgf (
            tipo_origen, ejer, vto_comp, nro_tgf, actuacion, o_pago, proveedor, n_comp, 
            importe, n_lib, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    }

    $stmt = $pdo->prepare($sql);
    
    // Leer CSV
    $handle = fopen($archivo['tmp_name'], "r");
    // Leer encabezado para saltarlo
    $header = fgetcsv($handle, 0, ","); 
    // Detectar separador si no funcionó la coma (opcional, para Excel en español a veces es ;)
    if ($header && count($header) < 2) {
        rewind($handle);
        $header = fgetcsv($handle, 0, ";"); // Intentar punto y coma
    }

    $count = 0;
    $max_movfere = null;
    $fila_nro = 1; // Para debug

    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) { // Asumimos coma por defecto según tus archivos
        $fila_nro++;
        
        // Saltar filas vacías
        if (count($data) < 2) continue;

        // Limpieza de datos (vacíos a NULL)
        $data = array_map(function($val) { 
            return ($val === '' || $val === ' ') ? null : trim($val); 
        }, $data);

        if ($es_sicopro_base) {
            // CORRECCIÓN SICOPRO: Forzar exactamente 94 columnas
            // Si trae más, se corta. Si trae menos, se rellena con NULL.
            if (count($data) > $columnas_esperadas_sicopro) {
                $data = array_slice($data, 0, $columnas_esperadas_sicopro);
            } elseif (count($data) < $columnas_esperadas_sicopro) {
                $data = array_pad($data, $columnas_esperadas_sicopro, null);
            }

            // Capturar fecha movfere (antepenúltima columna aprox)
            // En array de 94, MOVFERE es índice 91
            $fecha_row = $data[91] ?? null; 
            if ($fecha_row && ($max_movfere === null || $fecha_row > $max_movfere)) {
                $max_movfere = $fecha_row;
            }
            
            // Ejecutar inserción
            try {
                $stmt->execute($data);
                $count++;
            } catch (PDOException $e) {
                // Capturar error específico de fila para saber cuál falla
                throw new Exception("Error en fila $fila_nro SICOPRO: " . $e->getMessage());
            }

        } else {
            // CORRECCIÓN TGF: Forzar exactamente 15 columnas
            if (count($data) > $columnas_esperadas_tgf) {
                $data = array_slice($data, 0, $columnas_esperadas_tgf);
            } elseif (count($data) < $columnas_esperadas_tgf) {
                $data = array_pad($data, $columnas_esperadas_tgf, null);
            }

            // Agregar el tipo al principio
            array_unshift($data, $tipo); 
            // Ahora $data tiene 16 elementos, coincide con los 16 placeholders

            try {
                $stmt->execute($data);
                $count++;
            } catch (PDOException $e) {
                throw new Exception("Error en fila $fila_nro TGF: " . $e->getMessage() . " (Columnas enviadas: ".count($data).")");
            }
        }
    }
    fclose($handle);

    // 3. Registrar Log
    $user_id = $_SESSION['user_id'] ?? 0;
    $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, usuario_id, nombre_archivo, registros_insertados, ultima_fecha_dato) VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($logSql)->execute([$tipo, $user_id, $archivo['name'], $count, $max_movfere]);

    $pdo->commit();
    header('Location: importar.php?status=success');

} catch (Exception $e) {
    // Solución al error "There is no active transaction"
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $errorMsg = $e->getMessage();
    // Limpiar mensaje para URL
    error_log("Error Importación SICOPRO: " . $errorMsg);
    header('Location: importar.php?status=error&msg=' . urlencode(substr($errorMsg, 0, 200)));
}
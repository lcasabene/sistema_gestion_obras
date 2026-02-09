<?php
// modulos/sicopro/procesar_importacion.php

// 1. CONFIGURACIÓN
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); 
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

// --- HELPERS ---
function detectarDelimitador($ruta) {
    $h = fopen($ruta, "r");
    if (!$h) return ';';
    $l = fgets($h); 
    fclose($h);
    return (substr_count($l, ';') > substr_count($l, ',')) ? ';' : ',';
}

function limpiarNumero($v, $formato = 'estandar') {
    $v = trim($v);
    // Eliminamos símbolos que no sean números o separadores
    $v = str_replace(['$', 'USD', ' ', 'Est.'], '', $v);
    if ($v === '' || $v === null) return "0.00";
    
    if ($formato === 'argentino') {
        // Ejemplo: 2.346.260.407,87 -> 2346260407.87
        $v = str_replace('.', '', $v); // Quita miles
        $v = str_replace(',', '.', $v); // Cambia coma por punto decimal
    } else {
        // Ejemplo: 2,346,260,407.87 -> 2346260407.87
        $v = str_replace(',', '', $v); 
    }

    // IMPORTANTE: No usamos (float). Retornamos el string limpio.
    // Validamos que sea un número para evitar errores de SQL
    return is_numeric($v) ? $v : "0.00";
}

function convertirFecha($f, $origen = 'Y-m-d', $anioCtx = null) {
    $f = trim($f);
    if (empty($f)) return null;
    try {
        if ($origen == 'dia_mes_texto') {
            if (preg_match('/^(\d{1,2})\/(\d{1,2})/', $f, $m)) {
                $anio = $anioCtx ?? date('Y');
                return "$anio-{$m[2]}-{$m[1]}";
            }
        }
        $f = str_replace('-', '/', $f);
        if ($origen == 'd/m/Y' || $origen == 'd/m/y') { 
            $d = DateTime::createFromFormat('d/m/Y', $f);
            if (!$d) $d = DateTime::createFromFormat('d/m/y', $f);
            return $d ? $d->format('Y-m-d') : null;
        }
        return date('Y-m-d', strtotime($f));
    } catch (Exception $e) { return null; }
}

$response = ['success' => false, 'mensaje' => '', 'error' => ''];

try {
    // 2. CONEXIÓN
    $path_db = __DIR__ . '/../../config/database.php';
    if (!file_exists($path_db)) throw new Exception("Error crítico: No se encuentra database.php");
    require_once $path_db;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Método no permitido.');
    if (empty($_POST['tipo_importacion'])) throw new Exception("Error: No se recibió el tipo de archivo.");
    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) 
        throw new Exception('Error al subir el archivo.');

    // Variables de control
    $tipo_seleccionado = $_POST['tipo_importacion']; // valor del select (ej: 'tgf')
    $nombre_para_log = $tipo_seleccionado; // Por defecto usamos el mismo nombre
    
    $archivo = $_FILES['archivo_csv']['tmp_name'];
    $delim = detectarDelimitador($archivo);
    $fmtMoneda = ($delim === ';') ? 'argentino' : 'estandar'; 

    $handle = fopen($archivo, "r");
    $pdo->beginTransaction();
    $filas = 0;
    
    // Saltar encabezados
    fgetcsv($handle, 0, $delim); 

    $stmt = null;

    // =================================================================
    // OPTIMIZACIÓN: Pre-carga de claves para evitar múltiples SELECTs
    // =================================================================
    $mapa_existentes = [];
    
    if ($tipo_seleccionado === 'sicopro_original') {
        // Traemos solo las columnas clave de la tabla para llenar la memoria
        // Esto reduce n consultas a 1 sola consulta inicial.
        $sqlCache = "SELECT movejer, movtrju, movtrsa, movexpe, movalex, movtrnu, movtrse 
                     FROM sicopro_principal";
        
        $stmtCache = $pdo->prepare($sqlCache);
        $stmtCache->execute();
        
        while ($row = $stmtCache->fetch(PDO::FETCH_ASSOC)) {
            // Creamos una clave única concatenando los valores
            $clave = $row['movejer'] . '|' . 
                     $row['movtrju'] . '|' . 
                     $row['movtrsa'] . '|' . 
                     $row['movexpe'] . '|' . 
                     $row['movalex'] . '|' . 
                     $row['movtrnu'] . '|' . 
                     $row['movtrse'];
            $mapa_existentes[$clave] = true;
        }
    }
    // =================================================================

    while (($d = fgetcsv($handle, 8192, $delim)) !== FALSE) { 
        if (count($d) < 2) continue; 

        // ---------------------------------------------------------
        // GRUPO 1: SIGUE
        // ---------------------------------------------------------
        if ($tipo_seleccionado === 'sigue') {
            $nombre_para_log = 'SIGUE'; 

            if (!$stmt) {
                $sql = "INSERT INTO sicopro_sigue (
                            liqn, fecha, numero, nro_pago, tipo, debito, credito, 
                            moneda, importe, obs_lote, obs_transferencia
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }

            $stmt->execute([
                $d[0],                                   
                convertirFecha($d[2], 'd/m/y'),          
                $d[1],                                   
                $d[3],
                utf8_encode($d[4] ?? ''),                
                $d[5],                                   
                $d[6],                                   
                $d[7],                                   
                limpiarNumero($d[8], 'estandar'),        
                utf8_encode($d[9] ?? ''),                
                utf8_encode($d[10] ?? '')                
            ]);
            $filas++;
        }

        // ---------------------------------------------------------
        // GRUPO 2: LIQUIDACIONES
        // ---------------------------------------------------------
        elseif ($tipo_seleccionado === 'liquidaciones') {
            $nombre_para_log = 'LIQUIDACIONES';
            
            $anio_calculado = date('Y'); 
            if (!empty($d[1])) {
                $f_obj = DateTime::createFromFormat('d/m/Y', $d[1]);
                if ($f_obj) {
                    $anio_calculado = $f_obj->format('Y');
                }
            }

            if (!$stmt) {
                $sql = "INSERT INTO sicopro_liquidaciones (
                            nro_liquidacion, ejer, fecha, expediente, gedo, op_sicopro, razon_social, 
                            nro_proveedor, imp_liq, fdo_rep, ret_suss, ret_gcias, ret_iibb, 
                            ret_otras, ret_multas, imp_a_pagar, observaciones, ref_expediente, 
                            tipo_documento, nro_docum
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }

            $stmt->execute([
                $d[0],                              
                $anio_calculado,                    
                convertirFecha($d[1], 'd/m/Y'),     
                $d[2],                              
                $d[3],                              
                $d[4],                              
                $d[5],                              
                $d[6],                              
                limpiarNumero($d[7], 'argentino'),  
                limpiarNumero($d[8], 'argentino'),  
                limpiarNumero($d[9], 'argentino'),  
                limpiarNumero($d[10], 'argentino'), 
                limpiarNumero($d[11], 'argentino'), 
                limpiarNumero($d[12], 'argentino'), 
                limpiarNumero($d[13], 'argentino'), 
                limpiarNumero($d[14], 'argentino'), 
                utf8_encode($d[15] ?? ''),          
                $d[16] ?? '',                       
                $d[17] ?? '',                       
                $d[18] ?? ''                        
            ]);
            $filas++;
        }

        // ---------------------------------------------------------
        // GRUPO 3: TGF / ANTICIPOS / SOLICITADO
        // ---------------------------------------------------------
        elseif (in_array($tipo_seleccionado, ['tgf', 'anticipos', 'solicitado'])) {
            
            $tipo_origen_db = '';
            switch($tipo_seleccionado) {
                case 'tgf': $tipo_origen_db = 'TOTAL_ANTICIPADO'; break;
                case 'solicitado': $tipo_origen_db = 'SOLICITADO'; break;
                case 'anticipos': $tipo_origen_db = 'SIN_PAGO'; break;
            }
            $nombre_para_log = $tipo_origen_db;

            if (!$stmt) {
                $sql = "INSERT INTO sicopro_anticipos_tgf (
                            ejer, vto_comp, nro_tgf, actuacion, o_pago, proveedor, n_comp, 
                            importe, n_libramiento, contrasiento, quita, n_anticipo, f_anticipo, 
                            pesos, letras, tipo_origen
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            
            $ejercicio = (int)$d[0]; 
            $fechaVto = convertirFecha($d[1], 'dia_mes_texto', $ejercicio); 
            if (!$fechaVto) $fechaVto = convertirFecha($d[1], 'd/m/Y'); 
            $fechaAnt = convertirFecha($d[12], 'd/m/Y');

            $stmt->execute([
                $ejercicio,                         
                $fechaVto,                          
                $d[2],                              
                $d[3],                              
                $d[4],                              
                $d[5],                              
                $d[6],                              
                limpiarNumero($d[7], $fmtMoneda),   
                $d[8],                              
                $d[9],                              
                $d[10],                             
                $d[11],                             
                $fechaAnt,                          
                $d[13],                             
                $d[14] ?? '',                       
                $tipo_origen_db                     
            ]);
            $filas++;
        }

        // ---------------------------------------------------------
        // GRUPO 4: SICOPRO ORIGINAL (OPTIMIZADO)
        // ---------------------------------------------------------
        elseif ($tipo_seleccionado === 'sicopro_original') {
            $nombre_para_log = 'SICOPRO'; 

            // 1. VERIFICACIÓN CONTRA MAPA EN MEMORIA (Velocidad instantánea)
            // Indices según CSV: 0:movejer, 1:movtrju, 2:movtrsa, 6:movexpe, 7:movalex, 4:movtrnu, 5:movtrse
            $huella_csv = $d[0] . '|' . 
                          $d[1] . '|' . 
                          $d[2] . '|' . 
                          $d[6] . '|' . 
                          $d[7] . '|' . 
                          $d[4] . '|' . 
                          $d[5];

            // Si existe en el mapa, es duplicado -> saltar
            if (isset($mapa_existentes[$huella_csv])) {
                continue; 
            }

            // 2. PREPARAR INSERT
            if (!$stmt) {
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
                ) VALUES (" . str_repeat('?,', 95) . "?)"; 
                $stmt = $pdo->prepare($sql);
            }

            // --- PROCESAMIENTO ---
            $p = [];
            // Llenamos datos crudos del 0 al 95
            for($k=0; $k<=95; $k++) {
                $p[$k] = $d[$k] ?? null;
            }

            // Correcciones de formato
            $p[40] = limpiarNumero($d[40] ?? 0, 'argentino'); // Importe

            $indices_fechas = [45, 47, 48, 50, 51, 54, 56, 63, 93]; 
            foreach ($indices_fechas as $idx) {
                $p[$idx] = convertirFecha($d[$idx] ?? '', 'd/m/Y');
            }

            $p[82] = limpiarNumero($d[82] ?? 0, 'argentino'); // Crédito

            // Ejecutar Inserción
            $stmt->execute($p);
            
            // Agregamos al mapa para evitar duplicados dentro del mismo archivo CSV
            $mapa_existentes[$huella_csv] = true;

            $filas++;
        }
    }

    // LOG DE AUDITORÍA
    try {
        $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, fecha_subida, registros_insertados) VALUES (?, NOW(), ?)";
        $pdo->prepare($logSql)->execute([$nombre_para_log, $filas]);
    } catch(Exception $e) {}

    $pdo->commit();
    fclose($handle);

    $response['success'] = true;
    $response['mensaje'] = "Importación Correcta ($nombre_para_log). Registros: $filas";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $response['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($response);
?>
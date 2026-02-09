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
    $v = str_replace(['$', 'USD', ' ', 'Est.'], '', $v);
    if ($v === '' || $v === null) return 0.00;
    
    if ($formato === 'argentino') {
        $v = str_replace('.', '', $v); 
        $v = str_replace(',', '.', $v); 
    } else {
        $v = str_replace(',', '', $v); 
    }
    return (float)$v;
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

    while (($d = fgetcsv($handle, 8192, $delim)) !== FALSE) { 
        if (count($d) < 2) continue; 

        // ---------------------------------------------------------
        // GRUPO 1: SIGUE
        // ---------------------------------------------------------
        if ($tipo_seleccionado === 'sigue') {
            $nombre_para_log = 'SIGUE'; // Estandarizamos mayúsculas para el log
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_pagos_sigue (numero_transaccion, fecha_pago, nro_pago_sistema, tipo, debito, credito, moneda, importe, beneficiario, observacion_lote, observacion_transferencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            $stmt->execute([
                $d[1], convertirFecha($d[2], 'd/m/y'), $d[3], $d[4], $d[5], $d[6], $d[7],
                limpiarNumero($d[8], $fmtMoneda), $d[9], $d[9], $d[10] ?? ''
            ]);
            $filas++;
        }

        // ---------------------------------------------------------
        // GRUPO 2: LIQUIDACIONES
        // ---------------------------------------------------------
        elseif ($tipo_seleccionado === 'liquidaciones') {
            $nombre_para_log = 'LIQUIDACIONES';
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_liquidaciones (fecha, expediente, gedo, op_sicopro, razon_social, n_proveedor, imp_liq, fdo_rep, ret_suss, ret_gcias, ret_iibb, ret_otras, ret_multas, imp_pagar, observaciones, ref_expediente, tipo_documento, n_docum) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            $stmt->execute([
                convertirFecha($d[1], 'd/m/Y'), $d[2], $d[3], $d[4], $d[5], $d[6],
                limpiarNumero($d[7], 'argentino'), limpiarNumero($d[8], 'argentino'), limpiarNumero($d[9], 'argentino'),
                limpiarNumero($d[10], 'argentino'), limpiarNumero($d[11], 'argentino'), limpiarNumero($d[12], 'argentino'),
                limpiarNumero($d[13], 'argentino'), limpiarNumero($d[14], 'argentino'), utf8_encode($d[15] ?? ''),
                $d[16] ?? '', $d[17] ?? '', $d[18] ?? ''
            ]);
            $filas++;
        }

        // ---------------------------------------------------------
        // GRUPO 3: TGF / ANTICIPOS / SOLICITADO
        // ---------------------------------------------------------
        elseif (in_array($tipo_seleccionado, ['tgf', 'anticipos', 'solicitado'])) {
            
            // Lógica de mapeo de nombres históricos
            $tipo_origen_db = '';
            switch($tipo_seleccionado) {
                case 'tgf': 
                    $tipo_origen_db = 'TOTAL_ANTICIPADO'; 
                    break;
                case 'solicitado': 
                    $tipo_origen_db = 'SOLICITADO'; 
                    break;
                case 'anticipos': 
                    $tipo_origen_db = 'SIN_PAGO'; 
                    break;
            }
            $nombre_para_log = $tipo_origen_db; // Usamos el nombre histórico también para el log

            if (!$stmt) {
                // Se agrega 'tipo_origen' al final (Total 16 campos)
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
                $ejercicio,                         // 1
                $fechaVto,                          // 2
                $d[2],                              // 3
                $d[3],                              // 4
                $d[4],                              // 5
                $d[5],                              // 6
                $d[6],                              // 7
                limpiarNumero($d[7], $fmtMoneda),   // 8
                $d[8],                              // 9 (n_libramiento)
                $d[9],                              // 10
                $d[10],                             // 11
                $d[11],                             // 12
                $fechaAnt,                          // 13
                $d[13],                             // 14
                $d[14] ?? '',                       // 15
                $tipo_origen_db                     // 16. GUARDAMOS EL TIPO DE ORIGEN (TOTAL_ANTICIPADO, etc)
            ]);
            $filas++;
        }

        // ---------------------------------------------------------
        // GRUPO 4: SICOPRO ORIGINAL
        // ---------------------------------------------------------
 
        elseif ($tipo_seleccionado === 'sicopro_original') {
            $nombre_para_log = 'SICOPRO'; 

             if (!$stmt) {
                // CORRECCIÓN: Lista exacta de 96 campos basada en tu estructura
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
                ) VALUES (" . str_repeat('?,', 95) . "?)"; // 96 Placeholders (95 comas + 1 final)
                $stmt = $pdo->prepare($sql);
            }
            
            // Inicializamos el array de parámetros
            $p = [];

            // Llenamos datos crudos del 0 al 95 (Total 96 columnas del CSV)
            for($k=0; $k<=95; $k++) {
                $p[$k] = $d[$k] ?? null;
            }

            // --- APLICAMOS CORRECCIONES DE FORMATO ESPECÍFICAS ---
            
            // 1. MONEDA (Importe) - Índice 40
            $p[40] = limpiarNumero($d[40] ?? 0, 'argentino');

            // 2. FECHAS (Indices corregidos para estructura de 96 cols)
            $indices_fechas = [45, 47, 48, 50, 51, 54, 56, 63, 93]; 
            // 45: movfeop, 47: movfefa, 48: movvefa, 50: movfece, 51: movvece, 
            // 54: movveoc, 56: movfepa, 63: movfede, 93: movfere
            
            foreach ($indices_fechas as $idx) {
                $p[$idx] = convertirFecha($d[$idx] ?? '', 'd/m/Y');
            }

            // 3. MONEDA (Crédito) - Índice 82 (movimcr)
            $p[82] = limpiarNumero($d[82] ?? 0, 'argentino');

            // Ejecutamos con el array corregido de 96 elementos
            $stmt->execute($p);
            $filas++;
        }
    }

    // LOG DE AUDITORÍA (Usando el nombre histórico mapeado)
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
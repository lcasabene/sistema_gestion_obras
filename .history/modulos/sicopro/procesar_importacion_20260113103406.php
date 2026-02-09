<?php
// modulos/sicopro/procesar_importacion.php

// 1. CONFIGURACIÓN
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); 
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

// --- HELPERS (Funciones de ayuda) ---
function detectarDelimitador($ruta) {
    $h = fopen($ruta, "r");
    if (!$h) return ';';
    $l = fgets($h); 
    fclose($h);
    // Si hay más puntos y coma que comas, es ;
    return (substr_count($l, ';') > substr_count($l, ',')) ? ';' : ',';
}

function limpiarNumero($v, $formato = 'estandar') {
    $v = trim($v);
    $v = str_replace(['$', 'USD', ' ', 'Est.'], '', $v);
    if ($v === '' || $v === null) return 0.00;
    
    if ($formato === 'argentino') {
        // 1.234,56 -> 1234.56
        $v = str_replace('.', '', $v); 
        $v = str_replace(',', '.', $v); 
    } else {
        // 1,234.56 -> 1234.56
        $v = str_replace(',', '', $v); 
    }
    return (float)$v;
}

function convertirFecha($f, $origen = 'Y-m-d', $anioCtx = null) {
    $f = trim($f);
    if (empty($f)) return null;
    try {
        // Caso "02/01/jueves" (Viene en archivos TGF nuevos)
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
    // 2. CONEXIÓN Y VALIDACIÓN
    $path_db = __DIR__ . '/../../config/database.php';
    if (!file_exists($path_db)) throw new Exception("Error crítico: No se encuentra database.php");
    require_once $path_db;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Método no permitido.');
    
    if (empty($_POST['tipo_importacion'])) throw new Exception("Error: No se recibió el tipo de archivo.");
    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) 
        throw new Exception('Error al subir el archivo al servidor.');

    $tipo = $_POST['tipo_importacion'];
    $archivo = $_FILES['archivo_csv']['tmp_name'];
    
    // 3. DETECCIÓN AUTOMÁTICA DE FORMATO
    $delim = detectarDelimitador($archivo);
    $fmtMoneda = ($delim === ';') ? 'argentino' : 'estandar'; // Regla general

    $handle = fopen($archivo, "r");
    if (!$handle) throw new Exception("No se pudo leer el archivo temporal.");

    $pdo->beginTransaction();
    $filas = 0;
    
    // Leer primera línea (encabezados) y descartarla
    fgetcsv($handle, 0, $delim); 

    $stmt = null;

    // 4. PROCESAMIENTO FILA POR FILA
    while (($d = fgetcsv($handle, 8192, $delim)) !== FALSE) { 
        if (count($d) < 2) continue; // Saltar filas vacías

        // ---------------------------------------------------------
        // GRUPO 1: SIGUE
        // ---------------------------------------------------------
        if ($tipo === 'sigue') {
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_pagos_sigue (numero_transaccion, fecha_pago, nro_pago_sistema, tipo, debito, credito, moneda, importe, beneficiario, observacion_lote, observacion_transferencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            // CSV: Liq(0), Num(1), Fec(2), NroPago(3), Tipo(4), Deb(5), Cred(6), Mon(7), Imp(8), Ben(9)...
            $stmt->execute([
                $d[1], convertirFecha($d[2], 'd/m/y'), $d[3], $d[4], $d[5], $d[6], $d[7],
                limpiarNumero($d[8], $fmtMoneda), $d[9], $d[9], $d[10] ?? ''
            ]);
            $filas++;
        }

        // ---------------------------------------------------------
        // GRUPO 2: LIQUIDACIONES
        // ---------------------------------------------------------
        elseif ($tipo === 'liquidaciones') {
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
        // GRUPO 3: TGF / ANTICIPOS / SOLICITADO (Comparten estructura)
        // ---------------------------------------------------------
        elseif ($tipo === 'tgf' || $tipo === 'anticipos' || $tipo === 'solicitado') {
            
            if (!$stmt) {
                // Usamos la misma tabla para los 3 reportes TGF
                $sql = "INSERT INTO sicopro_anticipos_tgf (ejer, vto_comp, nro_tgf, actuacion, opago, proveedor, n_comp, importe,  contrasiento, quita, n_anticipo, f_anticipo, pesos, letras) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            
            $ejercicio = (int)$d[0]; 
            // Detectar fecha compleja "02/01/jueves"
            $fechaVto = convertirFecha($d[1], 'dia_mes_texto', $ejercicio); 
            if (!$fechaVto) $fechaVto = convertirFecha($d[1], 'd/m/Y'); // Intento normal

            $fechaAnt = convertirFecha($d[12], 'd/m/Y');

            $stmt->execute([
                $ejercicio, $fechaVto, $d[2], $d[3], $d[4], $d[5], $d[6],
                limpiarNumero($d[7], $fmtMoneda), $d[8], $d[9], $d[10], $d[11], 
                $fechaAnt, $d[13], $d[14] ?? ''
            ]);
            $filas++;
        }

        // ---------------------------------------------------------
        // GRUPO 4: SICOPRO ORIGINAL (Tabla Grande)
        // ---------------------------------------------------------
        elseif ($tipo === 'sicopro_original') {
             if (!$stmt) {
                $sql = "INSERT INTO sicopro_original (
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
                ) VALUES (" . str_repeat('?,', 93) . "?)";
                $stmt = $pdo->prepare($sql);
            }
            
            // Mapeo manual 1 a 1
            $p = [];
            for($k=0; $k<=39; $k++) $p[] = $d[$k] ?? null;
            $p[40] = limpiarNumero($d[40] ?? 0, 'argentino');
            for($k=41; $k<=44; $k++) $p[$k] = $d[$k] ?? null;
            $p[45] = convertirFecha($d[45] ?? '', 'd/m/Y');
            $p[46] = $d[46] ?? null;
            $p[47] = convertirFecha($d[47] ?? '', 'd/m/Y');
            $p[48] = convertirFecha($d[48] ?? '', 'd/m/Y');
            $p[49] = $d[49] ?? null;
            $p[50] = convertirFecha($d[50] ?? '', 'd/m/Y');
            $p[51] = convertirFecha($d[51] ?? '', 'd/m/Y');
            $p[52] = $d[52] ?? null; $p[53] = $d[53] ?? null;
            $p[54] = convertirFecha($d[54] ?? '', 'd/m/Y');
            $p[55] = $d[55] ?? null;
            $p[56] = convertirFecha($d[56] ?? '', 'd/m/Y');
            for($k=57; $k<=62; $k++) $p[$k] = $d[$k] ?? null;
            $p[63] = convertirFecha($d[63] ?? '', 'd/m/Y');
            for($k=64; $k<=80; $k++) $p[$k] = $d[$k] ?? null;
            $p[81] = limpiarNumero($d[81] ?? 0, 'argentino');
            for($k=82; $k<=90; $k++) $p[$k] = $d[$k] ?? null;
            $p[91] = convertirFecha($d[91] ?? '', 'd/m/Y');
            $p[92] = $d[92] ?? null; $p[93] = $d[93] ?? null;

            $stmt->execute($p);
            $filas++;
        }
        
        // ---------------------------------------------------------
        // ERROR: TIPO NO RECONOCIDO
        // ---------------------------------------------------------
        else {
            // Este es el error que veías antes. Ahora no debería ocurrir si usas las opciones correctas.
            throw new Exception("Tipo de archivo no reconocido internamente: '$tipo'");
        }
    }

    // LOG DE AUDITORÍA
    try {
        $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, fecha_subida, registros_insertados) VALUES (?, NOW(), ?)";
        $pdo->prepare($logSql)->execute([$tipo, $filas]);
    } catch(Exception $e) {}

    $pdo->commit();
    fclose($handle);

    $response['success'] = true;
    $response['mensaje'] = "Proceso finalizado correctamente. Registros importados: $filas";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $response['error'] = $e->getMessage();
}

// Limpiar buffer y enviar JSON puro
ob_clean();
echo json_encode($response);
?>
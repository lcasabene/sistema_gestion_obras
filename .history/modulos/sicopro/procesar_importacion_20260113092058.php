<?php
// modulos/sicopro/procesar_importacion.php

// 1. CONFIGURACIÓN
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); // Aumentamos tiempo porque este archivo es grande
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

// --- FUNCIONES DE LIMPIEZA ---
function limpiarNumero($valor, $formato = 'estandar') {
    $valor = trim($valor);
    $valor = str_replace(['$', 'USD', ' '], '', $valor); 
    if ($valor === '' || $valor === null) return 0.00;

    if ($formato === 'argentino') {
        // Formato 1.234,56 -> SQL 1234.56
        $valor = str_replace('.', '', $valor); 
        $valor = str_replace(',', '.', $valor); 
    }
    return (float)$valor;
}

function convertirFecha($fecha, $origen = 'Y-m-d') {
    $fecha = trim($fecha);
    if (empty($fecha)) return null;
    try {
        // Soporta d/m/y (2 digitos) y d/m/Y (4 digitos)
        if ($origen == 'd/m/Y' || $origen == 'd/m/y') { 
            // Truco: str_replace para asegurar formato estándar si viene con guiones
            $fecha = str_replace('-', '/', $fecha);
            $d = DateTime::createFromFormat('d/m/Y', $fecha);
            if (!$d) $d = DateTime::createFromFormat('d/m/y', $fecha); // Intento con año corto
            return $d ? $d->format('Y-m-d') : null;
        }
        return date('Y-m-d', strtotime($fecha));
    } catch (Exception $e) { return null; }
}

$response = ['success' => false, 'mensaje' => '', 'error' => ''];

try {
    // 2. CONEXIÓN
    $path_db = __DIR__ . '/../../config/database.php';
    if (!file_exists($path_db)) throw new Exception("Error: No se encuentra database.php");
    require_once $path_db;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Método no permitido.');
    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) 
        throw new Exception('Error al subir el archivo.');

    $tipo_importacion = $_POST['tipo_importacion'] ?? '';
    $archivo_tmp = $_FILES['archivo_csv']['tmp_name'];

    // 3. ESTRATEGIA
    $delimitador = ';'; 
    $saltear_encabezados = 1;
    
    switch ($tipo_importacion) {
        case 'sigue':
            $delimitador = ';'; break;  
        case 'liquidaciones':
            $delimitador = ';'; break;
        case 'tgf': 
        case 'anticipos': 
            $delimitador = ','; break;
        case 'sicopro_original': // <--- NUEVO CASO PARA LA TABLA GRANDE
            $delimitador = ';'; break;
        default:
            throw new Exception("Seleccione un tipo de archivo válido.");
    }

    $handle = fopen($archivo_tmp, "r");
    if (!$handle) throw new Exception("No se pudo abrir el archivo CSV.");

    $pdo->beginTransaction();
    $filas = 0;

    // Saltear encabezados
    for ($i = 0; $i < $saltear_encabezados; $i++) fgetcsv($handle, 0, $delimitador);

    $stmt = null;

    while (($data = fgetcsv($handle, 8192, $delimitador)) !== FALSE) { // Buffer aumentado a 8192
        if (count($data) < 2) continue; 

        // ---------------- CASO 1: SIGUE ----------------
        if ($tipo_importacion === 'sigue') {
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_pagos_sigue (numero_transaccion, fecha_pago, nro_pago_sistema, tipo, debito, credito, moneda, importe, beneficiario, observacion_lote, observacion_transferencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            $stmt->execute([
                $data[1], convertirFecha($data[2], 'd/m/y'), $data[3], $data[4], $data[5], $data[6], $data[7],
                limpiarNumero($data[8], 'estandar'), $data[9], $data[9], $data[10] ?? ''
            ]);
            $filas++;
        }

        // ---------------- CASO 2: LIQUIDACIONES ----------------
        elseif ($tipo_importacion === 'liquidaciones') {
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_liquidaciones (fecha, expediente, gedo, op_sicopro, razon_social, n_proveedor, imp_liq, fdo_rep, ret_suss, ret_gcias, ret_iibb, ret_otras, ret_multas, imp_pagar, observaciones, ref_expediente, tipo_documento, n_docum) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            $stmt->execute([
                convertirFecha($data[1], 'd/m/Y'), $data[2], $data[3], $data[4], $data[5], $data[6],
                limpiarNumero($data[7], 'argentino'), limpiarNumero($data[8], 'argentino'), limpiarNumero($data[9], 'argentino'),
                limpiarNumero($data[10], 'argentino'), limpiarNumero($data[11], 'argentino'), limpiarNumero($data[12], 'argentino'),
                limpiarNumero($data[13], 'argentino'), limpiarNumero($data[14], 'argentino'), utf8_encode($data[15] ?? ''),
                $data[16] ?? '', $data[17] ?? '', $data[18] ?? ''
            ]);
            $filas++;
        }

        // ---------------- CASO 3: TGF / ANTICIPOS ----------------
        elseif ($tipo_importacion === 'tgf' || $tipo_importacion === 'anticipos') {
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_anticipos_tgf (ejercicio, fecha_vto, nro_tgf, actuacion, op_numero, proveedor, n_comprobante, importe, n_libramiento, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            $stmt->execute([
                $data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6],
                limpiarNumero($data[7], 'estandar'), $data[8], $data[9], $data[10], $data[11], $data[12], $data[13], $data[14] ?? ''
            ]);
            $filas++;
        }

        // ---------------- CASO 4: SICOPRO ORIGINAL (TABLA GRANDE) ----------------
        elseif ($tipo_importacion === 'sicopro_original') {
            if (!$stmt) {
                // Generamos el SQL gigante
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
                ) VALUES (" . str_repeat('?,', 93) . "?)"; // 94 placeholders
                $stmt = $pdo->prepare($sql);
            }

            // Mapeo manual para asegurar tipos de datos (Fechas y Decimales)
            // Array de parámetros mapeado 1 a 1 con el CSV
            $params = [];
            
            // 0-39 (Datos generales strings/ints)
            for($k=0; $k<=39; $k++) $params[] = $data[$k] ?? null;

            // 40: movimpo (Decimal Argentino)
            $params[40] = limpiarNumero($data[40] ?? 0, 'argentino');

            // 41-44 (Strings)
            for($k=41; $k<=44; $k++) $params[$k] = $data[$k] ?? null;

            // 45: movfeop (Fecha)
            $params[45] = convertirFecha($data[45] ?? '', 'd/m/Y');

            // 46: movnufa
            $params[46] = $data[46] ?? null;

            // 47-48: movfefa, movvefa (Fechas)
            $params[47] = convertirFecha($data[47] ?? '', 'd/m/Y');
            $params[48] = convertirFecha($data[48] ?? '', 'd/m/Y');

            // 49: movnuce
            $params[49] = $data[49] ?? null;

            // 50-51: movfece, movvece (Fechas)
            $params[50] = convertirFecha($data[50] ?? '', 'd/m/Y');
            $params[51] = convertirFecha($data[51] ?? '', 'd/m/Y');

            // 52-53
            $params[52] = $data[52] ?? null;
            $params[53] = $data[53] ?? null;

            // 54: movveoc (Fecha)
            $params[54] = convertirFecha($data[54] ?? '', 'd/m/Y');

            // 55: movnupa
            $params[55] = $data[55] ?? null;

            // 56: movfepa (Fecha)
            $params[56] = convertirFecha($data[56] ?? '', 'd/m/Y');

            // 57-62
            for($k=57; $k<=62; $k++) $params[$k] = $data[$k] ?? null;

            // 63: movfede (Fecha)
            $params[63] = convertirFecha($data[63] ?? '', 'd/m/Y');

            // 64-80
            for($k=64; $k<=80; $k++) $params[$k] = $data[$k] ?? null;

            // 81: movimcr (Decimal Argentino)
            $params[81] = limpiarNumero($data[81] ?? 0, 'argentino');

            // 82-90
            for($k=82; $k<=90; $k++) $params[$k] = $data[$k] ?? null;

            // 91: movfere (Fecha)
            $params[91] = convertirFecha($data[91] ?? '', 'd/m/Y');

            // 92-93: movhore, movoper
            $params[92] = $data[92] ?? null;
            $params[93] = $data[93] ?? null;

            $stmt->execute($params);
            $filas++;
        }
    }

    $pdo->commit();
    fclose($handle);

    // LOG (Opcional)
    try {
        $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, fecha_subida, registros_insertados) VALUES (?, NOW(), ?)";
        $pdo->prepare($logSql)->execute([$tipo_importacion, $filas]);
    } catch(Exception $e) {}

    $response['success'] = true;
    $response['mensaje'] = "Importación exitosa. Filas procesadas: $filas";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $response['error'] = "Error: " . $e->getMessage();
}

ob_clean();
echo json_encode($response);
?>
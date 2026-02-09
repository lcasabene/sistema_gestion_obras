<?php
// modulos/sicopro/procesar_importacion.php

// 1. CONFIGURACIÓN
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); 
ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

// --- FUNCIONES DE LIMPIEZA ---
function detectarDelimitador($rutaArchivo) {
    $handle = fopen($rutaArchivo, "r");
    $primeraLinea = fgets($handle);
    fclose($handle);
    // Si tiene más ; que , asumimos ; (común en Excel español)
    return (substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',')) ? ';' : ',';
}

function limpiarNumero($valor, $formato = 'estandar') {
    $valor = trim($valor);
    $valor = str_replace(['$', 'USD', ' ', 'Est.'], '', $valor); // Limpieza general
    if ($valor === '' || $valor === null) return 0.00;

    if ($formato === 'argentino') {
        // Formato 1.234,56 -> SQL 1234.56
        $valor = str_replace('.', '', $valor); // Quitar punto miles
        $valor = str_replace(',', '.', $valor); // Coma a punto
    } else {
        // Formato Inglés 1,234.56 -> SQL 1234.56
        $valor = str_replace(',', '', $valor); 
    }
    return (float)$valor;
}

function convertirFecha($fecha, $origen = 'Y-m-d', $anioContexto = null) {
    $fecha = trim($fecha);
    if (empty($fecha)) return null;

    try {
        // Caso especial: "02/01/jueves" (Viene en tu nuevo archivo TGF)
        if ($origen == 'dia_mes_texto') {
            // Extraemos solo los números del inicio: 02/01
            if (preg_match('/^(\d{1,2})\/(\d{1,2})/', $fecha, $matches)) {
                $dia = $matches[1];
                $mes = $matches[2];
                $anio = $anioContexto ?? date('Y');
                return "$anio-$mes-$dia";
            }
        }
        
        // Manejo estándar de fechas con barras
        $fecha = str_replace('-', '/', $fecha);
        if ($origen == 'd/m/Y' || $origen == 'd/m/y') { 
            $d = DateTime::createFromFormat('d/m/Y', $fecha);
            if (!$d) $d = DateTime::createFromFormat('d/m/y', $fecha);
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
    
    // Validar subida
    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) 
        throw new Exception('Error al subir el archivo o archivo muy grande.');

    // Validar selección de tipo (Aquí estaba tu error original)
    $tipo_importacion = $_POST['tipo_importacion'] ?? '';
    if (empty($tipo_importacion)) {
        throw new Exception("Por favor, selecciona una opción en el menú 'Tipo de Archivo'.");
    }

    $archivo_tmp = $_FILES['archivo_csv']['tmp_name'];

    // 3. DETECCIÓN AUTOMÁTICA
    $delimitador = detectarDelimitador($archivo_tmp);
    
    // Regla: Si el delimitador es ; (punto y coma), los números suelen ser formato argentino (1.000,00)
    // Si es , (coma), suelen ser formato inglés (1,000.00)
    $formatoMoneda = ($delimitador === ';') ? 'argentino' : 'estandar';

    $handle = fopen($archivo_tmp, "r");
    if (!$handle) throw new Exception("No se pudo abrir el archivo CSV.");

    $pdo->beginTransaction();
    $filas = 0;

    // Saltear encabezados
    fgetcsv($handle, 0, $delimitador); 

    $stmt = null;

    while (($data = fgetcsv($handle, 8192, $delimitador)) !== FALSE) { 
        if (count($data) < 2) continue; 

        // ---------------- CASO: SIGUE ----------------
        if ($tipo_importacion === 'sigue') {
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_pagos_sigue (numero_transaccion, fecha_pago, nro_pago_sistema, tipo, debito, credito, moneda, importe, beneficiario, observacion_lote, observacion_transferencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            $stmt->execute([
                $data[1], convertirFecha($data[2], 'd/m/y'), $data[3], $data[4], $data[5], $data[6], $data[7],
                limpiarNumero($data[8], $formatoMoneda), $data[9], $data[9], $data[10] ?? ''
            ]);
            $filas++;
        }

        // ---------------- CASO: LIQUIDACIONES ----------------
        elseif ($tipo_importacion === 'liquidaciones') {
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_liquidaciones (fecha, expediente, gedo, op_sicopro, razon_social, n_proveedor, imp_liq, fdo_rep, ret_suss, ret_gcias, ret_iibb, ret_otras, ret_multas, imp_pagar, observaciones, ref_expediente, tipo_documento, n_docum) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            // Liquidaciones siempre suele ser argentino, forzamos si es necesario, o usamos el detectado
            $stmt->execute([
                convertirFecha($data[1], 'd/m/Y'), $data[2], $data[3], $data[4], $data[5], $data[6],
                limpiarNumero($data[7], 'argentino'), limpiarNumero($data[8], 'argentino'), limpiarNumero($data[9], 'argentino'),
                limpiarNumero($data[10], 'argentino'), limpiarNumero($data[11], 'argentino'), limpiarNumero($data[12], 'argentino'),
                limpiarNumero($data[13], 'argentino'), limpiarNumero($data[14], 'argentino'), utf8_encode($data[15] ?? ''),
                $data[16] ?? '', $data[17] ?? '', $data[18] ?? ''
            ]);
            $filas++;
        }


        // --- CASO 3: TGF / ANTICIPOS / SOLICITADO (NUEVO) ---
        elseif ($tipo === 'tgf' || $tipo === 'anticipos' || $tipo === 'solicitado') {
            
            if (!$stmt) {
                $sql = "INSERT INTO sicopro_anticipos_tgf (ejercicio, fecha_vto, nro_tgf, actuacion, op_numero, proveedor, n_comprobante, importe, n_libramiento, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }
            
            // ... (el resto del bloque de inserción es idéntico al anterior) ...
            $ejercicio = (int)$d[0]; 
            $fechaVto = convertirFecha($d[1], 'dia_mes_texto', $ejercicio); 
            if (!$fechaVto) $fechaVto = convertirFecha($d[1], 'd/m/Y'); 
            $fechaAnt = convertirFecha($d[12], 'd/m/Y');

            $stmt->execute([
                $ejercicio, $fechaVto, $d[2], $d[3], $d[4], $d[5], $d[6],
                limpiarNumero($d[7], $fmtMoneda), $d[8], $d[9], $d[10], $d[11], 
                $fechaAnt, $d[13], $d[14] ?? ''
            ]);
            $filas++;
        }
        
        // ... (resto del código para sicopro_original y logs) ...

        // ---------------- CASO: SICOPRO ORIGINAL (TABLA GRANDE) ----------------
        elseif ($tipo_importacion === 'sicopro_original') {
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
            // ... (Mismo mapeo de 94 campos que te di antes, resumido aquí para brevedad) ...
            $params = [];
            for($k=0; $k<=39; $k++) $params[] = $data[$k] ?? null;
            $params[40] = limpiarNumero($data[40] ?? 0, 'argentino');
            for($k=41; $k<=44; $k++) $params[$k] = $data[$k] ?? null;
            $params[45] = convertirFecha($data[45] ?? '', 'd/m/Y');
            $params[46] = $data[46] ?? null;
            $params[47] = convertirFecha($data[47] ?? '', 'd/m/Y');
            $params[48] = convertirFecha($data[48] ?? '', 'd/m/Y');
            $params[49] = $data[49] ?? null;
            $params[50] = convertirFecha($data[50] ?? '', 'd/m/Y');
            $params[51] = convertirFecha($data[51] ?? '', 'd/m/Y');
            $params[52] = $data[52] ?? null;
            $params[53] = $data[53] ?? null;
            $params[54] = convertirFecha($data[54] ?? '', 'd/m/Y');
            $params[55] = $data[55] ?? null;
            $params[56] = convertirFecha($data[56] ?? '', 'd/m/Y');
            for($k=57; $k<=62; $k++) $params[$k] = $data[$k] ?? null;
            $params[63] = convertirFecha($data[63] ?? '', 'd/m/Y');
            for($k=64; $k<=80; $k++) $params[$k] = $data[$k] ?? null;
            $params[81] = limpiarNumero($data[81] ?? 0, 'argentino');
            for($k=82; $k<=90; $k++) $params[$k] = $data[$k] ?? null;
            $params[91] = convertirFecha($data[91] ?? '', 'd/m/Y');
            $params[92] = $data[92] ?? null;
            $params[93] = $data[93] ?? null;

            $stmt->execute($params);
            $filas++;
        }
        
        // Error si no coincide ningún tipo
        else {
             throw new Exception("Tipo de archivo no reconocido internamente.");
        }
    }

    $pdo->commit();
    fclose($handle);

    // LOG
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
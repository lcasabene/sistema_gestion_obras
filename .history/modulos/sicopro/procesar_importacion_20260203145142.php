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
            $nombre_para_log = 'SIGUE'; 

            if (!$stmt) {
                // Verificamos que los nombres de columnas coincidan con tu tabla real
                $sql = "INSERT INTO sicopro_sigue (
                            liqn, 
                            fecha, 
                            numero,
                            nro_pago, 
                            tipo, 
                            debito, 
                            credito, 
                            moneda, 
                            importe, 
                            obs_lote, 
                            obs_transferencia
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
            }

            // CSV Sigue: 
            // 0:LIQN | 1:Numero | 2:Fecha | 3:Nro.Pago | 4:Tipo | 5:Debito | 6:Credito | 7:Moneda | 8:Importe | 9:ObsLote | 10:ObsTransf

            $stmt->execute([
                $d[0],                                   // 1. numero_transaccion
                convertirFecha($d[2], 'd/m/y'),          // 2. fecha_pago (Formato origen 29/12/25)
                $d[1],                                   // 3. nro_pago_sistema
                $d[3],
                utf8_encode($d[4] ?? ''),                // 4. tipo (Ej: Transferencia)
                $d[5],                                   // 5. debito (Cuenta)
                $d[6],                                   // 6. credito (Cuenta)
                $d[7],                                   // 7. moneda ($)
                limpiarNumero($d[8], 'estandar'),        // 8. importe (IMPORTANTE: 'estandar' porque viene con punto)
                utf8_encode($d[9] ?? ''),                // 10. observacion_lote
                utf8_encode($d[10] ?? '')                // 11. observacion_transferencia
            ]);
            $filas++;
        }

    // ---------------------------------------------------------
        // GRUPO 2: LIQUIDACIONES (ADAPTADO CON CAMPO EJER)
        // ---------------------------------------------------------
        elseif ($tipo_seleccionado === 'liquidaciones') {
            $nombre_para_log = 'LIQUIDACIONES';
            
            // --- LOGICA NUEVA: CALCULAR EJERCICIO ---
            // Tomamos la fecha ($d[1]) y extraemos el año para llenar el campo 'ejer'.
            // Esto es fundamental para que funcione la Trazabilidad.
            $anio_calculado = date('Y'); // Valor por defecto (año actual)
            if (!empty($d[1])) {
                // Intentamos parsear la fecha formato dd/mm/yyyy
                $f_obj = DateTime::createFromFormat('d/m/Y', $d[1]);
                if ($f_obj) {
                    $anio_calculado = $f_obj->format('Y');
                }
            }
            // ------------------------------------------

            if (!$stmt) {
                // SE AGREGÓ el campo 'ejer' en la segunda posición
                $sql = "INSERT INTO sicopro_liquidaciones (
                            nro_liquidacion, ejer, fecha, expediente, gedo, op_sicopro, razon_social, 
                            nro_proveedor, imp_liq, fdo_rep, ret_suss, ret_gcias, ret_iibb, 
                            ret_otras, ret_multas, imp_a_pagar, observaciones, ref_expediente, 
                            tipo_documento, nro_docum
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                // Nota: Ahora hay 20 signos de pregunta (?) porque agregamos ejer
                $stmt = $pdo->prepare($sql);
            }

            $stmt->execute([
                $d[0],                              // 1. nro_liquidacion
                $anio_calculado,                    // 2. ejer (NUEVO: Calculado desde la fecha)
                convertirFecha($d[1], 'd/m/Y'),     // 3. fecha
                $d[2],                              // 4. expediente
                $d[3],                              // 5. gedo
                $d[4],                              // 6. op_sicopro
                $d[5],                              // 7. razon_social
                $d[6],                              // 8. nro_proveedor
                limpiarNumero($d[7], 'argentino'),  // 9. imp_liq
                limpiarNumero($d[8], 'argentino'),  // 10. fdo_rep
                limpiarNumero($d[9], 'argentino'),  // 11. ret_suss
                limpiarNumero($d[10], 'argentino'), // 12. ret_gcias
                limpiarNumero($d[11], 'argentino'), // 13. ret_iibb
                limpiarNumero($d[12], 'argentino'), // 14. ret_otras
                limpiarNumero($d[13], 'argentino'), // 15. ret_multas
                limpiarNumero($d[14], 'argentino'), // 16. imp_a_pagar
                utf8_encode($d[15] ?? ''),          // 17. observaciones
                $d[16] ?? '',                       // 18. ref_expediente
                $d[17] ?? '',                       // 19. tipo_documento
                $d[18] ?? ''                        // 20. nro_docum
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

            // 1. PREPARAR CONSULTA DE VERIFICACIÓN (DUPLICADOS)
            if (!isset($stmtCheck)) {
                // Buscamos coincidencia en los 7 campos clave
                $sqlCheck = "SELECT 1 FROM sicopro_principal 
                             WHERE movejer = ? 
                               AND movtrju = ? 
                               AND movtrsa = ? 
                               AND movexpe = ? 
                               AND movalex = ? 
                               AND movtrnu = ? 
                               AND movtrse = ? 
                             LIMIT 1";
                $stmtCheck = $pdo->prepare($sqlCheck);
            }

            // 2. PREPARAR CONSULTA DE INSERCIÓN
            if (!$stmt) {
                // Lista exacta de 96 campos
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

            // --- PASO A: VERIFICAR DUPLICADO ANTES DE PROCESAR ---
            // Asignación de índices según el orden del CSV (0 al 95):
            // 0:movejer, 1:movtrju, 2:movtrsa, 4:movtrnu, 5:movtrse, 6:movexpe, 7:movalex
            
            $stmtCheck->execute([
                $d[0], // movejer
                $d[1], // movtrju
                $d[2], // movtrsa
                $d[6], // movexpe
                $d[7], // movalex
                $d[4], // movtrnu
                $d[5]  // movtrse
            ]);

            // Si devuelve una columna, el registro ya existe -> Saltamos
            if ($stmtCheck->fetchColumn()) {
                continue; 
            }

            // --- PASO B: PROCESAMIENTO DE DATOS (Si no es duplicado) ---
            
            // Inicializamos el array de parámetros
            $p = [];

            // Llenamos datos crudos del 0 al 95
            for($k=0; $k<=95; $k++) {
                $p[$k] = $d[$k] ?? null;
            }

            // --- APLICAMOS CORRECCIONES DE FORMATO ESPECÍFICAS ---
            
            // 1. MONEDA (Importe) - Índice 40
            $p[40] = limpiarNumero($d[40] ?? 0, 'argentino');

            // 2. FECHAS 
            $indices_fechas = [45, 47, 48, 50, 51, 54, 56, 63, 93]; 
            foreach ($indices_fechas as $idx) {
                $p[$idx] = convertirFecha($d[$idx] ?? '', 'd/m/Y');
            }

            // 3. MONEDA (Crédito) - Índice 82 (movimcr)
            $p[82] = limpiarNumero($d[82] ?? 0, 'argentino');

            // Ejecutamos la inserción
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
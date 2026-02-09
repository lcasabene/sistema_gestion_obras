<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

ini_set('max_execution_time', 600);
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

// ==========================================
// FUNCIONES DE LIMPIEZA
// ==========================================

function limpiar_fecha($fecha_raw, $anio_contexto = null) {
    if (empty($fecha_raw)) return null;
    $fecha_limpia = preg_replace('/[a-zA-ZáéíóúñÁÉÍÓÚÑ]+/', '', $fecha_raw);
    $fecha_limpia = trim($fecha_limpia, " /-;.,");
    $partes = preg_split('/[\/\-]/', $fecha_limpia);
    
    // Formato dd/mm/yyyy ó dd/mm/yy
    if (count($partes) == 3) {
        $anio = (int)$partes[2];
        // Si el año viene como '26', convertir a '2026'
        if ($anio < 100) $anio += 2000;
        return "{$anio}-{$partes[1]}-{$partes[0]}";
    }
    // Formato dd/mm (con año contexto)
    if (count($partes) >= 2 && $anio_contexto) {
        $anio = (int)$anio_contexto; 
        return "{$anio}-{$partes[1]}-{$partes[0]}";
    }
    return null;
}

function limpiar_moneda($valor) {
    if (empty($valor)) return 0;
    // Eliminar $ y espacios
    $valor = str_replace(['$', ' '], '', $valor);
    $valor = str_replace('.', '', $valor); // Chau miles
    $valor = str_replace(',', '.', $valor); // Coma a punto
    return (float)$valor;
}

// ==========================================
// CONFIGURACIÓN DE COLUMNAS
// ==========================================
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

try {
    $pdo->beginTransaction();

    // 1. ABRIR Y DETECTAR DELIMITADOR
    $handle = fopen($archivo['tmp_name'], "r");
    $header_line = fgets($handle); 
    rewind($handle);
    // Detectar si usa ; o , contando apariciones en la primera línea
    $delimiter = (substr_count($header_line, ';') > substr_count($header_line, ',')) ? ';' : ',';

    // 2. LOGICA DE HEADER Y PRE-ESCANEO
    $anios_en_archivo = [];
    
    // SKIP HEADERS: Depende del archivo
    if ($tipo === 'LIQUIDACIONES') {
        // CORRECCIÓN: Buscamos "FECHA" o "EXPEDIENTE" en lugar de "N°"
        // para evitar errores de codificación con el símbolo °
        while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
            // Row[1] debería ser FECHA, Row[2] EXPEDIENTE
            if ( (isset($row[1]) && stripos($row[1], 'FECHA') !== false) || 
                 (isset($row[2]) && stripos($row[2], 'EXPEDIENTE') !== false) ) {
                break; // Encontramos el encabezado, paramos aquí.
            }
        }
    } elseif ($tipo === 'SIGUE' || $tipo === 'SICOPRO' || in_array($tipo, ['TOTAL_ANTICIPADO','SOLICITADO','SIN_PAGO'])) {
        // CSV Estándar (1 linea de header)
        fgetcsv($handle, 0, $delimiter);
    }

    $posicion_inicio_datos = ftell($handle); // Guardamos donde empiezan los datos reales

    // PRE-ESCANEO AÑOS
    while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        if (count($row) < 2) continue;
        
        $anio_fila = null;

        if ($tipo === 'SICOPRO' || in_array($tipo, ['TOTAL_ANTICIPADO','SOLICITADO','SIN_PAGO'])) {
            $anio_fila = trim($row[0]); // Columna 0 es Ejer
        } 
        elseif ($tipo === 'LIQUIDACIONES') {
            // Columna 1 es FECHA (08/01/2026) -> Sacar año
            $f = limpiar_fecha($row[1]);
            if ($f) $anio_fila = date('Y', strtotime($f));
        }
        elseif ($tipo === 'SIGUE') {
            // Columna 2 es Fecha (06/01/26) -> Sacar año
            $f = limpiar_fecha($row[2]);
            if ($f) $anio_fila = date('Y', strtotime($f));
        }

        if ($anio_fila && is_numeric($anio_fila) && $anio_fila > 2000) {
            $anios_en_archivo[$anio_fila] = true;
        }
    }
    
    $anios_a_borrar = array_keys($anios_en_archivo);
    
    // Validación más amigable si falla
    if (empty($anios_a_borrar)) {
        throw new Exception("No se detectó ningún año válido. Verifique que el archivo tenga datos y el formato de fecha sea correcto (dd/mm/aaaa). Delimitador detectado: [$delimiter]");
    }

    // 3. BORRADO INTELIGENTE
    $inQuery = implode(',', array_fill(0, count($anios_a_borrar), '?'));

    if ($tipo === 'SICOPRO') {
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_principal WHERE movejer IN ($inQuery)");
        $stmtDel->execute($anios_a_borrar);
        
        $cols = implode(',', $cols_sicopro_arr);
        $vals = rtrim(str_repeat('?,', count($cols_sicopro_arr)), ',');
        $sql = "INSERT INTO sicopro_principal ($cols) VALUES ($vals)";

    } elseif (in_array($tipo, ['TOTAL_ANTICIPADO','SOLICITADO','SIN_PAGO'])) {
        $params = array_merge([$tipo], $anios_a_borrar);
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_anticipos_tgf WHERE tipo_origen = ? AND ejer IN ($inQuery)");
        $stmtDel->execute($params);
        $sql = "INSERT INTO sicopro_anticipos_tgf (tipo_origen, ejer, vto_comp, nro_tgf, actuacion, o_pago, proveedor, n_comp, importe, n_lib, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    } elseif ($tipo === 'LIQUIDACIONES') {
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_liquidaciones WHERE ejer IN ($inQuery)");
        $stmtDel->execute($anios_a_borrar);
        $sql = "INSERT INTO sicopro_liquidaciones (ejer, nro_liquidacion, fecha, expediente, gedo, op_sicopro, razon_social, nro_proveedor, imp_liq, fdo_rep, ret_suss, ret_gcias, ret_iibb, ret_otras, ret_multas, imp_a_pagar, observaciones, ref_expediente, tipo_documento, nro_docum) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

    } elseif ($tipo === 'SIGUE') {
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_sigue WHERE ejer IN ($inQuery)");
        $stmtDel->execute($anios_a_borrar);
        $sql = "INSERT INTO sicopro_sigue (ejer, liqn, numero, fecha, nro_pago, tipo, debito, credito, moneda, importe, obs_lote, obs_transferencia) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
    }

    $stmt = $pdo->prepare($sql);

    // 4. INSERTAR DATOS
    fseek($handle, $posicion_inicio_datos); 

    $count = 0;
    $max_movfere = null;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        if (count($data) < 2) continue; 
        $data = array_map('trim', $data);
        $params = [];

        if ($tipo === 'SICOPRO') {
            $cols_esperadas = count($cols_sicopro_arr);
            if (count($data) > $cols_esperadas) $data = array_slice($data, 0, $cols_esperadas);
            elseif (count($data) < $cols_esperadas) $data = array_pad($data, $cols_esperadas, null);

            $indices_fechas = array_keys(array_intersect($cols_sicopro_arr, ['movfeop', 'movfefa', 'movvefa', 'movfece', 'movvece', 'movveoc', 'movfepa', 'movfede', 'movfere']));
            foreach ($indices_fechas as $idx) if (isset($data[$idx])) $data[$idx] = limpiar_fecha($data[$idx]);
            
            if (!empty($data[6]) && strpos($data[6], 'E+') === false) $data[6] = str_replace([',', '.'], '', $data[6]);
            if (isset($data[40])) $data[40] = limpiar_moneda($data[40]);
            if (isset($data[81])) $data[81] = limpiar_moneda($data[81]);

            $idx_movfere = array_search('movfere', $cols_sicopro_arr);
            if ($data[$idx_movfere] && ($max_movfere === null || $data[$idx_movfere] > $max_movfere)) $max_movfere = $data[$idx_movfere];

            $stmt->execute($data);

        } elseif (in_array($tipo, ['TOTAL_ANTICIPADO','SOLICITADO','SIN_PAGO'])) {
            $data = array_slice($data, 0, 15);
            $anio = (int)$data[0];
            
            $params = [
                $tipo, 
                $anio,
                limpiar_fecha($data[1], $anio),
                $data[2], $data[3], $data[4], $data[5], $data[6],
                limpiar_moneda($data[7]),
                $data[8], $data[9], limpiar_moneda($data[10]), $data[11],
                limpiar_fecha($data[12]),
                limpiar_moneda($data[13]), $data[14]
            ];
            $stmt->execute($params);

        } elseif ($tipo === 'LIQUIDACIONES') {
            // Mapeo LIQUIDACIONES
            $data = array_pad($data, 19, null);
            
            $fecha = limpiar_fecha($data[1]);
            $anio = $fecha ? date('Y', strtotime($fecha)) : 0;

            $params = [
                $anio,
                $data[0], // Nro Liq
                $fecha,
                $data[2], $data[3], $data[4], $data[5], $data[6],
                limpiar_moneda($data[7]), // Imp Liq
                limpiar_moneda($data[8]), // Fdo Rep
                limpiar_moneda($data[9]), // Suss
                limpiar_moneda($data[10]), // Gcias
                limpiar_moneda($data[11]), // IIBB
                limpiar_moneda($data[12]), // Otras
                limpiar_moneda($data[13]), // Multas
                limpiar_moneda($data[14]), // A Pagar
                $data[15], $data[16], $data[17], $data[18]
            ];
            $stmt->execute($params);

        } elseif ($tipo === 'SIGUE') {
            // Mapeo SIGUE
            $data = array_pad($data, 11, null);

            $fecha = limpiar_fecha($data[2]);
            $anio = $fecha ? date('Y', strtotime($fecha)) : 0;

            $params = [
                $anio,
                $data[0], // Liqn
                $data[1], // Numero
                $fecha,
                $data[3], // Nro Pago
                $data[4], // Tipo
                $data[5], // Debito
                $data[6], // Credito
                $data[7], // Moneda
                limpiar_moneda($data[8]), // Importe
                $data[9], // Obs Lote
                $data[10] // Obs Transf
            ];
            $stmt->execute($params);
        }
        $count++;
    }
    fclose($handle);

    // LOG
    $user_id = $_SESSION['user_id'] ?? 0;
    $info_anios = " (" . implode(', ', $anios_a_borrar) . ")";
    $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, usuario_id, nombre_archivo, registros_insertados, ultima_fecha_dato) VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($logSql)->execute([$tipo, $user_id, $archivo['name'] . $info_anios, $count, $max_movfere]);

    $pdo->commit();
    header('Location: importar.php?status=success');

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error Importación: " . $e->getMessage());
    header('Location: importar.php?status=error&msg=' . urlencode("Error: " . substr($e->getMessage(), 0, 200)));
}
<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Configuración para archivos grandes
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

$es_sicopro_base = ($tipo === 'SICOPRO');

// ==========================================
// FUNCIONES DE LIMPIEZA (Igual que antes)
// ==========================================

function limpiar_fecha($fecha_raw, $anio_contexto = null) {
    if (empty($fecha_raw)) return null;
    $fecha_limpia = preg_replace('/[a-zA-ZáéíóúñÁÉÍÓÚÑ]+/', '', $fecha_raw);
    $fecha_limpia = trim($fecha_limpia, " /-;.,");
    $partes = preg_split('/[\/\-]/', $fecha_limpia);
    
    // Formato dd/mm/yyyy
    if (count($partes) == 3 && strlen($partes[2]) == 4) {
        return "{$partes[2]}-{$partes[1]}-{$partes[0]}";
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
    $valor = str_replace('.', '', $valor); // Chau miles
    $valor = str_replace(',', '.', $valor); // Coma a punto
    return (float)$valor;
}

// ==========================================
// DEFINICIÓN DE COLUMNAS
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

$columnas_esperadas_sicopro = count($cols_sicopro_arr);
$columnas_esperadas_tgf = 15;

try {
    $pdo->beginTransaction();

    // 1. ABRIR ARCHIVO Y DETECTAR DELIMITADOR
    $handle = fopen($archivo['tmp_name'], "r");
    $header_line = fgets($handle); 
    rewind($handle);
    $delimiter = (substr_count($header_line, ';') > substr_count($header_line, ',')) ? ';' : ',';
    
    // Saltar encabezado
    fgetcsv($handle, 0, $delimiter); 

    // 2. PRE-ESCANEO: Detectar qué AÑOS (Ejercicios) vienen en el archivo
    // Esto es vital para borrar SOLO esos años y no tocar los anteriores.
    $anios_en_archivo = [];
    
    // Guardamos la posición actual del puntero para volver después
    $posicion_inicio_datos = ftell($handle);

    while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        if (count($row) < 1) continue;
        // La columna 0 siempre es el año (tanto en SICOPRO 'MOVEJER' como en TGF 'Ejer')
        $anio_fila = trim($row[0]);
        if (is_numeric($anio_fila) && $anio_fila > 2000) {
            $anios_en_archivo[$anio_fila] = true; // Usamos array asociativo para evitar duplicados
        }
    }
    
    // Obtener lista limpia de años (ej: [2025])
    $anios_a_borrar = array_keys($anios_en_archivo);

    if (empty($anios_a_borrar)) {
        throw new Exception("No se pudo detectar el año (Ejercicio) en el archivo.");
    }

    // 3. BORRADO INTELIGENTE (Solo los años detectados)
    if ($es_sicopro_base) {
        // SICOPRO: Borrar por movejer
        // Generamos placeholders (?,?,?) según cantidad de años
        $inQuery = implode(',', array_fill(0, count($anios_a_borrar), '?'));
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_principal WHERE movejer IN ($inQuery)");
        $stmtDel->execute($anios_a_borrar);
        
        // Preparar INSERT
        $cols_sql = implode(', ', $cols_sicopro_arr);
        $placeholders = rtrim(str_repeat('?,', $columnas_esperadas_sicopro), ',');
        $sql = "INSERT INTO sicopro_principal ($cols_sql) VALUES ($placeholders)";

    } else {
        // TGF: Borrar por tipo_origen Y ejer
        $inQuery = implode(',', array_fill(0, count($anios_a_borrar), '?'));
        // El array de parámetros para delete será: [tipo, anio1, anio2...]
        $paramsDelete = array_merge([$tipo], $anios_a_borrar);
        
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_anticipos_tgf WHERE tipo_origen = ? AND ejer IN ($inQuery)");
        $stmtDel->execute($paramsDelete);
        
        // Preparar INSERT
        $sql = "INSERT INTO sicopro_anticipos_tgf (
            tipo_origen, ejer, vto_comp, nro_tgf, actuacion, o_pago, proveedor, n_comp, 
            importe, n_lib, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    }

    $stmt = $pdo->prepare($sql);

    // 4. RESETEAR PUNTERO DEL ARCHIVO E INSERTAR
    fseek($handle, $posicion_inicio_datos); // Volvemos al inicio de los datos (post-header)

    $count = 0;
    $max_movfere = null;
    $fila_nro = 1;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        $fila_nro++;
        if (count($data) < 2) continue; 
        $data = array_map('trim', $data);

        if ($es_sicopro_base) {
            // --- LOGICA SICOPRO ---
            if (count($data) > $columnas_esperadas_sicopro) $data = array_slice($data, 0, $columnas_esperadas_sicopro);
            elseif (count($data) < $columnas_esperadas_sicopro) $data = array_pad($data, $columnas_esperadas_sicopro, null);

            // Índices de fechas SICOPRO
            $indices_fechas = array_keys(array_intersect($cols_sicopro_arr, [
                'movfeop', 'movfefa', 'movvefa', 'movfece', 'movvece', 'movveoc', 'movfepa', 'movfede', 'movfere'
            ]));
            
            foreach ($indices_fechas as $idx) {
                if (isset($data[$idx])) $data[$idx] = limpiar_fecha($data[$idx]);
            }

            // Limpieza específica
            if (!empty($data[6]) && strpos($data[6], 'E+') === false) {
                $data[6] = str_replace([',', '.'], '', $data[6]); // Expediente
            }
            if (isset($data[40])) $data[40] = limpiar_moneda($data[40]); // Importe
            if (isset($data[81])) $data[81] = limpiar_moneda($data[81]); // Crédito

            // Captura fecha log
            $idx_movfere = array_search('movfere', $cols_sicopro_arr);
            if ($data[$idx_movfere] && ($max_movfere === null || $data[$idx_movfere] > $max_movfere)) {
                $max_movfere = $data[$idx_movfere];
            }
            
            $stmt->execute($data);

        } else {
            // --- LOGICA TGF ---
            if (count($data) > $columnas_esperadas_tgf) $data = array_slice($data, 0, $columnas_esperadas_tgf);
            elseif (count($data) < $columnas_esperadas_tgf) $data = array_pad($data, $columnas_esperadas_tgf, null);

            $anio_row = (int)$data[0]; 
            $data[1] = limpiar_fecha($data[1], $anio_row); // Vto Comp (usa año contexto)
            $data[12] = limpiar_fecha($data[12]); // F. Anticipo
            $data[7] = limpiar_moneda($data[7]); // Importe
            $data[13] = limpiar_moneda($data[13]); // Pesos
            $data[10] = limpiar_moneda($data[10]); // Quita

            array_unshift($data, $tipo); 
            $stmt->execute($data);
        }
        $count++;
    }
    fclose($handle);

    // 5. REGISTRAR LOG
    $user_id = $_SESSION['user_id'] ?? 0;
    // Agregamos en el nombre del archivo qué años se detectaron, para control visual
    $info_anios = " (" . implode(', ', $anios_a_borrar) . ")";
    $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, usuario_id, nombre_archivo, registros_insertados, ultima_fecha_dato) VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($logSql)->execute([$tipo, $user_id, $archivo['name'] . $info_anios, $count, $max_movfere]);

    $pdo->commit();
    header('Location: importar.php?status=success');

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error Importación: " . $e->getMessage());
    header('Location: importar.php?status=error&msg=' . urlencode("Fila $fila_nro: " . substr($e->getMessage(), 0, 150)));
}
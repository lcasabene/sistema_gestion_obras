<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Configuración para archivos grandes y tiempos largos
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
// FUNCIONES DE AYUDA (LIMPIEZA DE DATOS)
// ==========================================

/**
 * Convierte formatos '30/12/2025', '30/12/jueves' a '2025-12-30'
 */
function limpiar_fecha($fecha_raw, $anio_contexto = null) {
    if (empty($fecha_raw)) return null;
    
    // Quitar nombres de días (lunes, martes...) y caracteres extraños
    // Dejar solo números y barras/guiones
    $fecha_limpia = preg_replace('/[a-zA-ZáéíóúñÁÉÍÓÚÑ]+/', '', $fecha_raw);
    $fecha_limpia = trim($fecha_limpia, " /-;.,");
    
    // Separar por / o -
    $partes = preg_split('/[\/\-]/', $fecha_limpia);
    
    // CASO 1: Formato dd/mm/yyyy (Ej: 30/12/2025)
    if (count($partes) == 3) {
        // Asegurar orden: si el último es de 4 dígitos, es el año
        if (strlen($partes[2]) == 4) {
            return "{$partes[2]}-{$partes[1]}-{$partes[0]}";
        }
    }
    
    // CASO 2: Formato dd/mm (Ej: 02/01) proveniente de TGF que trae el día de la semana
    // Usamos el $anio_contexto (columna 1 del archivo) para completar
    if (count($partes) >= 2 && $anio_contexto) {
        $anio = (int)$anio_contexto; // Limpiar '2025,0' a 2025
        return "{$anio}-{$partes[1]}-{$partes[0]}";
    }

    return null; // Si falla, null (mejor que 0000-00-00)
}

/**
 * Convierte montos europeos '10.000,50' a SQL '10000.50'
 */
function limpiar_moneda($valor) {
    if (empty($valor)) return 0;
    // Eliminar puntos de miles
    $valor = str_replace('.', '', $valor);
    // Cambiar coma decimal por punto
    $valor = str_replace(',', '.', $valor);
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

    if ($es_sicopro_base) {
        // --- SICOPRO ---
        $pdo->exec("TRUNCATE TABLE sicopro_principal");
        $cols_sql = implode(', ', $cols_sicopro_arr);
        $placeholders = rtrim(str_repeat('?,', $columnas_esperadas_sicopro), ',');
        $sql = "INSERT INTO sicopro_principal ($cols_sql) VALUES ($placeholders)";
    } else {
        // --- TGF (Anticipos) ---
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_anticipos_tgf WHERE tipo_origen = ?");
        $stmtDel->execute([$tipo]);
        $sql = "INSERT INTO sicopro_anticipos_tgf (
            tipo_origen, ejer, vto_comp, nro_tgf, actuacion, o_pago, proveedor, n_comp, 
            importe, n_lib, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    }

    $stmt = $pdo->prepare($sql);
    
    // Leer archivo
    $handle = fopen($archivo['tmp_name'], "r");
    
    // Detectar delimitador (Forzamos búsqueda de punto y coma por tus archivos)
    $header_line = fgets($handle); // Leer primera línea cruda
    rewind($handle);
    $delimiter = (substr_count($header_line, ';') > substr_count($header_line, ',')) ? ';' : ',';
    
    // Saltar encabezado real
    fgetcsv($handle, 0, $delimiter); 

    $count = 0;
    $max_movfere = null;
    $fila_nro = 1;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        $fila_nro++;
        if (count($data) < 2) continue; // Saltar vacíos

        // Limpieza general de espacios
        $data = array_map('trim', $data);

        if ($es_sicopro_base) {
            // =========================
            // PROCESAMIENTO SICOPRO
            // =========================
            
            // Ajustar cantidad de columnas
            if (count($data) > $columnas_esperadas_sicopro) $data = array_slice($data, 0, $columnas_esperadas_sicopro);
            elseif (count($data) < $columnas_esperadas_sicopro) $data = array_pad($data, $columnas_esperadas_sicopro, null);

            // CORRECCIÓN FECHAS SICOPRO (Índices basados en tu CSV)
            // MOVFEOP (aprox idx 45), MOVFEFA (47), MOVVEFA (48), MOVFECE (50), MOVVECE (51), MOVVEOC (54), MOVFEPA (56), MOVFEDE (62), MOVFERE (aprox 91)
            // Buscamos las columnas que sabemos que son fecha por el nombre del array
            $indices_fechas = array_keys(array_intersect($cols_sicopro_arr, [
                'movfeop', 'movfefa', 'movvefa', 'movfece', 'movvece', 'movveoc', 'movfepa', 'movfede', 'movfere'
            ]));
            
            foreach ($indices_fechas as $idx) {
                if (isset($data[$idx])) {
                    $data[$idx] = limpiar_fecha($data[$idx]);
                }
            }

            // CORRECCIÓN EXPEDIENTE (MOVEXPE - índice 6) y OTROS TEXTOS
            // Si viene notación científica de Excel (ej: 2,025E+11), intentamos arreglar lo obvio, 
            // pero lo ideal es corregir el Excel origen. Aquí quitamos comas erróneas en textos.
            if (!empty($data[6])) { 
                // A veces Excel pone comas en números largos. Las quitamos para guardar limpio el expediente.
                // Si es notación científica pura "E+", no se puede recuperar el dato perdido aquí.
                if (strpos($data[6], 'E+') === false) {
                    $data[6] = str_replace([',', '.'], '', $data[6]); 
                }
            }

            // CORRECCIÓN MONTOS (MOVIMPO - idx 40, MOVIMCR - idx 81)
            if (isset($data[40])) $data[40] = limpiar_moneda($data[40]);
            if (isset($data[81])) $data[81] = limpiar_moneda($data[81]);

            // Capturar MOVFERE para el log (antepenúltima aprox)
            $idx_movfere = array_search('movfere', $cols_sicopro_arr);
            if ($data[$idx_movfere] && ($max_movfere === null || $data[$idx_movfere] > $max_movfere)) {
                $max_movfere = $data[$idx_movfere];
            }
            
            $stmt->execute($data);

        } else {
            // =========================
            // PROCESAMIENTO TGF (Anticipos)
            // =========================
            
            // Ajustar columnas a 15
            if (count($data) > $columnas_esperadas_tgf) $data = array_slice($data, 0, $columnas_esperadas_tgf);
            elseif (count($data) < $columnas_esperadas_tgf) $data = array_pad($data, $columnas_esperadas_tgf, null);

            // Índices TGF:
            // 0: Ejer (Año)
            // 1: Vto.Comp (Fecha rara: 30/12/miércoles)
            // 7: Importe
            // 12: F.Anticipo (Fecha normal: 20/01/2025)
            // 13: Pesos (Moneda)

            // 1. Obtener Año del contexto (col 0)
            $anio_row = (int)$data[0]; 

            // 2. Limpiar Vto.Comp (Col 1) usando el año
            $data[1] = limpiar_fecha($data[1], $anio_row);

            // 3. Limpiar F.Anticipo (Col 12)
            $data[12] = limpiar_fecha($data[12]);

            // 4. Limpiar Importe (Col 7)
            $data[7] = limpiar_moneda($data[7]);
            
            // 5. Limpiar Pesos/Otros montos si los hay (Col 13)
            $data[13] = limpiar_moneda($data[13]);
            $data[10] = limpiar_moneda($data[10]); // Quita

            // Agregar tipo al inicio para el INSERT
            array_unshift($data, $tipo); 

            $stmt->execute($data);
        }
        $count++;
    }
    fclose($handle);

    // Log final
    $user_id = $_SESSION['user_id'] ?? 0;
    $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, usuario_id, nombre_archivo, registros_insertados, ultima_fecha_dato) VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($logSql)->execute([$tipo, $user_id, $archivo['name'], $count, $max_movfere]);

    $pdo->commit();
    header('Location: importar.php?status=success');

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error Importación: " . $e->getMessage());
    header('Location: importar.php?status=error&msg=' . urlencode("Fila $fila_nro: " . substr($e->getMessage(), 0, 150)));
}
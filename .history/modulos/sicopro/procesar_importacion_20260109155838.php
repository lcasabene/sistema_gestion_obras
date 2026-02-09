<?php
// modulos/sicopro/procesar_importacion.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Aumentar tiempo de ejecución para archivos grandes
set_time_limit(300); 
ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');

// --- 1. VALIDACIONES INICIALES ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Error al subir el archivo o archivo no seleccionado']);
    exit;
}

// Recibimos el tipo
$tipo_archivo = $_POST['tipo_archivo'] ?? '';

// --- 2. NORMALIZACIÓN DE NOMBRES (Solución al error "Tipo no válido") ---
// Esto permite que el HTML envíe "sigue", "banco", etc., y el PHP entienda qué tabla es.
$mapa_tablas = [
    'sicopro_sigue'         => 'sicopro_sigue',
    'sigue'                 => 'sicopro_sigue',         // Alias común
    'banco'                 => 'sicopro_sigue',         // Alias común
    
    'sicopro_principal'     => 'sicopro_principal',
    'principal'             => 'sicopro_principal',     // Alias común
    
    'sicopro_liquidaciones' => 'sicopro_liquidaciones',
    'liquidaciones'         => 'sicopro_liquidaciones', // Alias común
    
    'sicopro_anticipos_tgf' => 'sicopro_anticipos_tgf',
    'tgf'                   => 'sicopro_anticipos_tgf'  // Alias común
];

if (!array_key_exists($tipo_archivo, $mapa_tablas)) {
    echo json_encode(['error' => "Tipo de archivo no válido. Valor recibido: '$tipo_archivo'"]);
    exit;
}

// Asignamos el nombre real de la tabla
$tabla_destino = $mapa_tablas[$tipo_archivo];

// --- 3. APERTURA DEL ARCHIVO ---
$fileTmpPath = $_FILES['archivo_csv']['tmp_name'];
$handle = fopen($fileTmpPath, "r");

if ($handle === FALSE) {
    echo json_encode(['error' => 'No se pudo abrir el archivo temporal']);
    exit;
}

try {
    $pdo->beginTransaction();

    // --- 4. LIMPIEZA PREVIA (Truncate) ---
    // Borramos los datos viejos de la tabla seleccionada para reemplazar por los nuevos
    $pdo->exec("TRUNCATE TABLE $tabla_destino");

    // --- 5. PREPARACIÓN DE SENTENCIAS SQL ---
    $stmt = null;
    $delimiter = ','; // Por defecto coma

    // Detección automática de punto y coma (común en SIGUE)
    $linea_prueba = fgets($handle);
    if (strpos($linea_prueba, ';') !== false) {
        $delimiter = ';';
    }
    rewind($handle); // Volver al inicio

    if ($tabla_destino === 'sicopro_principal') {
        // Estructura: movejer, movtrju, movexpe, movtrti, movtrnu, movfufi, movfeop, movnupa, movprov, movrefe, movimpo, movfech, movcomp, movfere
        $sql = "INSERT INTO sicopro_principal (movejer, movtrju, movexpe, movtrti, movtrnu, movfufi, movfeop, movnupa, movprov, movrefe, movimpo, movfech, movcomp, movfere) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        // Saltar encabezado
        fgetcsv($handle, 0, $delimiter);

    } elseif ($tabla_destino === 'sicopro_liquidaciones') {
        // Estructura: ejer, op_sicopro, nro_liquidacion, imp_a_pagar, imp_retenciones, imp_liq (bruto), beneficiario
        $sql = "INSERT INTO sicopro_liquidaciones (ejer, op_sicopro, nro_liquidacion, imp_a_pagar, imp_retenciones, imp_liq, beneficiario) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        fgetcsv($handle, 0, $delimiter);

    } elseif ($tabla_destino === 'sicopro_sigue') {
        // Estructura: liqn, numero, fecha, nro_pago, tipo, importe, obs_lote, ejer
        // NOTA: 'ejer' no viene en el CSV, lo deducimos de la fecha.
        $sql = "INSERT INTO sicopro_sigue (liqn, numero, fecha, nro_pago, tipo, importe, obs_lote, ejer) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        fgetcsv($handle, 0, $delimiter);

    } elseif ($tabla_destino === 'sicopro_anticipos_tgf') {
        // Estructura: ejer, saf, o_pago, f_anticipo, tipo_origen, total_anticipado, cta_bancaria
        $sql = "INSERT INTO sicopro_anticipos_tgf (ejer, saf, o_pago, f_anticipo, tipo_origen, total_anticipado, cta_bancaria) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        fgetcsv($handle, 0, $delimiter);
    }

    // --- 6. FUNCIONES HELPER (Limpieza de datos) ---
    
    // Función para fechas (soporta dd/mm/yyyy y dd/mm/yy)
    $cleanDate = function($d) {
        if (!$d || strtoupper($d) == 'NULL' || trim($d) == '') return null;
        $d = trim($d);
        // Formato dd/mm/yyyy o dd/mm/yy
        if (strpos($d, '/') !== false) {
            $parts = explode('/', $d);
            if(count($parts) == 3) {
                $day = $parts[0];
                $month = $parts[1];
                $year = $parts[2];
                // Si el año tiene 2 dígitos (ej: 25), asumir 2000 (2025)
                if (strlen($year) == 2) {
                    $year = '20' . $year;
                }
                return "$year-$month-$day";
            }
        }
        // Si ya viene como YYYY-MM-DD
        if (strpos($d, '-') !== false) return $d;
        return null;
    };
    
    // Función para moneda ARGENTINA (1.000,00 -> 1000.00)
    // Usar SOLO para archivos que traen coma decimal.
    $cleanMoneyArg = function($v) {
        if (!$v) return 0;
        $v = str_replace('.', '', $v); // Quitar punto de miles
        $v = str_replace(',', '.', $v); // Cambiar coma decimal por punto
        return floatval($v);
    };

    // --- 7. PROCESAMIENTO FILA POR FILA ---
    $filas_insertadas = 0;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        
        // A. SICOPRO PRINCIPAL
        if ($tabla_destino === 'sicopro_principal') {
            // Filtrar basura
            $trti = trim($data[3] ?? '');
            if (in_array($trti, ['AA', 'RE', 'AC', 'CO', 'AP'])) continue;
            if (count($data) < 14) continue;

            $params = [
                $data[0], // movejer
                $data[1], // movtrju
                $data[2], // movexpe
                $data[3], // movtrti
                $data[4], // movtrnu
                $data[5], // movfufi
                $cleanDate($data[6]), // movfeop
                $data[7], // movnupa
                utf8_encode($data[8]), // movprov
                utf8_encode($data[9]), // movrefe
                $cleanMoneyArg($data[10]), // movimpo (Usa formato Arg)
                $cleanDate($data[11]), // movfech
                $data[12], // movcomp
                $cleanDate($data[13])  // movfere
            ];
            $stmt->execute($params);
            $filas_insertadas++;
        }

        // B. SICOPRO SIGUE (El del problema de importes)
        elseif ($tabla_destino === 'sicopro_sigue') {
            // Indices CSV analizados: 
            // 0=LIQN, 1=Numero, 2=Fecha, 3=NroPago, 4=Tipo, 8=Importe(con punto), 9=Obs
            
            if (empty($data[0]) || $data[0] == '0') continue; // Saltar filas vacías
            
            $fecha = $cleanDate($data[2]);
            
            // IMPORTANTE: El CSV 'Sigue' trae el importe con punto (2919857.19).
            // NO USAR cleanMoneyArg, usar floatval directo.
            $importe = isset($data[8]) ? floatval($data[8]) : 0;
            
            // Deducir ejercicio
            $ejer = $fecha ? date('Y', strtotime($fecha)) : date('Y');

            $stmt->execute([
                trim($data[0]), // liqn
                trim($data[1]), // numero interno
                $fecha,         // fecha
                trim($data[3]), // nro_pago
                trim($data[4]), // tipo
                $importe,       // importe (YA VIENE CON PUNTO)
                utf8_encode($data[9] ?? ''), // obs
                $ejer           // ejer deducido
            ]);
            $filas_insertadas++;
        }

        // C. LIQUIDACIONES
        elseif ($tabla_destino === 'sicopro_liquidaciones') {
            if(count($data) < 6) continue;
            $stmt->execute([
                $data[0], // ejer
                $data[1], // op
                $data[2], // nro_liq
                $cleanMoneyArg($data[3]), // a_pagar
                $cleanMoneyArg($data[4]), // retenciones
                $cleanMoneyArg($data[5]), // imp_liq (bruto)
                utf8_encode($data[6] ?? '') // benef
            ]);
            $filas_insertadas++;
        }
        
        // D. TGF
        elseif ($tabla_destino === 'sicopro_anticipos_tgf') {
            $stmt->execute([
                $data[0], // ejer
                $data[1], // saf
                $data[2], // op
                $cleanDate($data[3]), // f_anticipo
                $data[4], // tipo
                $cleanMoneyArg($data[5]), // total
                $data[6] ?? '' // cta
            ]);
            $filas_insertadas++;
        }
    }

    $pdo->commit();
    fclose($handle);
    
    echo json_encode(['success' => true, 'mensaje' => "Se importaron $filas_insertadas registros correctamente en $tabla_destino."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fclose($handle);
    echo json_encode(['error' => 'Error crítico en base de datos: ' . $e->getMessage()]);
}
?>
<?php
// modulos/sicopro/procesar_importacion.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Aumentar límites para archivos pesados
set_time_limit(300); 
ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. VALIDACIONES BÁSICAS
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido (Use POST)');
    }

    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió el archivo o hubo un error en la subida (Código: ' . ($_FILES['archivo_csv']['error'] ?? 'N/A') . ')');
    }

    $fileTmpPath = $_FILES['archivo_csv']['tmp_name'];
    $tipo_recibido = trim($_POST['tipo_archivo'] ?? '');

    // 2. AUTO-DETECCIÓN INTELIGENTE DEL TIPO DE ARCHIVO
    // Leemos la primera línea para ver qué encabezados trae
    $handle = fopen($fileTmpPath, "r");
    if ($handle === FALSE) throw new Exception('No se pudo abrir el archivo temporal.');
    
    $primera_linea = fgets($handle);
    rewind($handle); // Volver al inicio

    // Detectamos delimitador (Sigue usa ; y Sicopro usa ,)
    $delimiter = (strpos($primera_linea, ';') !== false) ? ';' : ',';

    // Lógica de Auto-Detección
    $tabla_destino = '';
    
    // Si el usuario seleccionó algo, intentamos respetarlo primero, normalizando alias
    $mapa_alias = [
        'sigue' => 'sicopro_sigue', 'banco' => 'sicopro_sigue', 'sicopro_sigue' => 'sicopro_sigue',
        'principal' => 'sicopro_principal', 'sicopro_principal' => 'sicopro_principal',
        'liquidaciones' => 'sicopro_liquidaciones', 'sicopro_liquidaciones' => 'sicopro_liquidaciones',
        'tgf' => 'sicopro_anticipos_tgf', 'sicopro_anticipos_tgf' => 'sicopro_anticipos_tgf'
    ];
    
    if (array_key_exists($tipo_recibido, $mapa_alias)) {
        $tabla_destino = $mapa_alias[$tipo_recibido];
    }

    // SI NO COINCIDE O ESTÁ VACÍO, INTENTAMOS ADIVINAR POR CONTENIDO
    if (empty($tabla_destino)) {
        if (stripos($primera_linea, 'LIQN') !== false) {
            $tabla_destino = 'sicopro_sigue';
        } elseif (stripos($primera_linea, 'movejer') !== false) {
            $tabla_destino = 'sicopro_principal';
        } elseif (stripos($primera_linea, 'imp_a_pagar') !== false || stripos($primera_linea, 'nro_liquidacion') !== false) {
            $tabla_destino = 'sicopro_liquidaciones';
        }
    }

    // SI AÚN ASÍ NO SABEMOS QUÉ ES, LANZAMOS ERROR DETALLADO
    if (empty($tabla_destino)) {
        throw new Exception("Tipo de archivo no válido. Se recibió: '$tipo_recibido' y el encabezado del archivo es: '" . substr($primera_linea, 0, 30) . "...'");
    }

    // 3. PREPARAR BASE DE DATOS
    $pdo->beginTransaction();
    $pdo->exec("TRUNCATE TABLE $tabla_destino");

    $stmt = null;
    
    // Definir sentencias SQL según la tabla detectada
    if ($tabla_destino === 'sicopro_sigue') {
        $sql = "INSERT INTO sicopro_sigue (liqn, numero, fecha, nro_pago, tipo, importe, obs_lote, ejer) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_principal') {
        $sql = "INSERT INTO sicopro_principal (movejer, movtrju, movexpe, movtrti, movtrnu, movfufi, movfeop, movnupa, movprov, movrefe, movimpo, movfech, movcomp, movfere) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_liquidaciones') {
        $sql = "INSERT INTO sicopro_liquidaciones (ejer, op_sicopro, nro_liquidacion, imp_a_pagar, imp_retenciones, imp_liq, beneficiario) VALUES (?, ?, ?, ?, ?, ?, ?)";
    } elseif ($tabla_destino === 'sicopro_anticipos_tgf') {
        $sql = "INSERT INTO sicopro_anticipos_tgf (ejer, saf, o_pago, f_anticipo, tipo_origen, total_anticipado, cta_bancaria) VALUES (?, ?, ?, ?, ?, ?, ?)";
    }
    
    if (isset($sql)) {
        $stmt = $pdo->prepare($sql);
    } else {
        throw new Exception("Error interno: No se generó sentencia SQL para $tabla_destino");
    }

    // 4. PROCESAR FILAS
    $filas = 0;
    
    // Funciones de limpieza
    $cleanDate = function($d) {
        if (!$d || trim($d) == '' || strtoupper($d) == 'NULL') return null;
        $d = trim($d);
        // dd/mm/yy o dd/mm/yyyy
        if (strpos($d, '/') !== false) {
            $parts = explode('/', $d);
            if(count($parts) == 3) {
                // Si año tiene 2 digitos (25 -> 2025)
                if(strlen($parts[2]) == 2) $parts[2] = '20'.$parts[2];
                return $parts[2].'-'.$parts[1].'-'.$parts[0];
            }
        }
        return $d; 
    };
    
    $cleanMoneyArg = function($v) {
        if (!$v) return 0;
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
        return floatval($v);
    };

    // Saltar encabezado
    fgetcsv($handle, 0, $delimiter);

    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        // --- LOGICA ESPECÍFICA SIGUE ---
        if ($tabla_destino === 'sicopro_sigue') {
            // Estructura CSV detectada: 0=LIQN, 2=Fecha, 3=NroPago, 4=Tipo, 8=Importe
            if (empty($data[0]) || $data[0] == '0') continue; // Ignorar vacíos
            
            // IMPORTANTE: El CSV 'Sigue' trae el importe con punto (2919857.19).
            // Lo tomamos directo. Si viene vacío ponemos 0.
            $importe = isset($data[8]) ? floatval($data[8]) : 0;
            
            $fecha = $cleanDate($data[2]);
            $ejer = $fecha ? date('Y', strtotime($fecha)) : date('Y'); // Deducir ejercicio

            $stmt->execute([
                trim($data[0]), // LIQN
                trim($data[1]), // Numero
                $fecha,         // Fecha
                trim($data[3]), // NroPago
                trim($data[4]), // Tipo
                $importe,       // Importe (SIN CONVERSIÓN ARGENTINA)
                utf8_encode($data[9] ?? ''), // Obs
                $ejer           // Ejercicio
            ]);
            $filas++;
        }
        
        // --- LOGICA PRINCIPAL ---
        elseif ($tabla_destino === 'sicopro_principal') {
            // Validar basura
            if (in_array(trim($data[3]??''), ['AA', 'RE', 'AC', 'CO', 'AP'])) continue;
            if (count($data) < 14) continue;

            $stmt->execute([
                $data[0], $data[1], $data[2], $data[3], $data[4], $data[5],
                $cleanDate($data[6]), $data[7], utf8_encode($data[8]), utf8_encode($data[9]),
                $cleanMoneyArg($data[10]), // Este SÍ usa formato argentino
                $cleanDate($data[11]), $data[12], $cleanDate($data[13])
            ]);
            $filas++;
        }
        
        // --- LOGICA LIQUIDACIONES ---
        elseif ($tabla_destino === 'sicopro_liquidaciones') {
            if (count($data) < 6) continue;
            $stmt->execute([
                $data[0], $data[1], $data[2],
                $cleanMoneyArg($data[3]), 
                $cleanMoneyArg($data[4]), 
                $cleanMoneyArg($data[5]), 
                utf8_encode($data[6]??'')
            ]);
            $filas++;
        }
        
        // --- LOGICA TGF ---
        elseif ($tabla_destino === 'sicopro_anticipos_tgf') {
             $stmt->execute([
                $data[0], $data[1], $data[2],
                $cleanDate($data[3]), $data[4],
                $cleanMoneyArg($data[5]), $data[6]??''
            ]);
            $filas++;
        }
    }

    $pdo->commit();
    fclose($handle);
    
    echo json_encode([
        'success' => true, 
        'mensaje' => "Importación Exitosa. Se detectó archivo '$tabla_destino' y se cargaron $filas registros."
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    echo json_encode(['error' => $e->getMessage()]);
}
?>
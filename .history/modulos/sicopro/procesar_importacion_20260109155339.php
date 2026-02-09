<?php
// modulos/sicopro/procesar_importacion.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Error al subir el archivo']);
    exit;
}

$tipo_archivo = $_POST['tipo_archivo'] ?? '';
$valid_types = ['sicopro_principal', 'sicopro_liquidaciones', 'sicopro_anticipos_tgf', 'sicopro_sigue'];

if (!in_array($tipo_archivo, $valid_types)) {
    echo json_encode(['error' => 'Tipo de archivo no válido']);
    exit;
}

$fileTmpPath = $_FILES['archivo_csv']['tmp_name'];
$handle = fopen($fileTmpPath, "r");

if ($handle === FALSE) {
    echo json_encode(['error' => 'No se pudo abrir el archivo']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Limpiar tabla antes de importar (Reemplazo total)
    // NOTA: Para producción, quizás prefieras NO borrar todo, sino hacer UPSERT.
    // Pero mantengo tu lógica actual de limpieza total para asegurar consistencia.
    $pdo->exec("TRUNCATE TABLE $tipo_archivo");

    // Preparar INSERT según el tipo
    $stmt = null;
    $es_sicopro_base = ($tipo_archivo === 'sicopro_principal');
    $es_liquidaciones = ($tipo_archivo === 'sicopro_liquidaciones');
    $es_sigue = ($tipo_archivo === 'sicopro_sigue');
    $es_tgf = ($tipo_archivo === 'sicopro_anticipos_tgf');

    // Detectar delimitador (Sigue suele usar ';', Sicopro suele usar ',')
    $delimiter = ($es_sigue) ? ';' : ','; 
    
    // Si es TGF o Liquidaciones, a veces también usan punto y coma. 
    // Truco: leer la primera línea para detectar
    $firstLine = fgets($handle);
    if (strpos($firstLine, ';') !== false) $delimiter = ';';
    rewind($handle); // Volver al inicio

    if ($es_sicopro_base) {
        // SICOPRO PRINCIPAL
        // Estructura esperada: movejer, movtrju, movexpe, movtrti, movtrnu, movfufi, movfeop, movnupa, movprov, movrefe, movimpo, movfech, movcomp, movfere
        $sql = "INSERT INTO sicopro_principal (movejer, movtrju, movexpe, movtrti, movtrnu, movfufi, movfeop, movnupa, movprov, movrefe, movimpo, movfech, movcomp, movfere) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        
        // Saltar encabezados
        fgetcsv($handle, 0, $delimiter); 

    } elseif ($es_liquidaciones) {
        // LIQUIDACIONES
        // Estructura: ejer, op_sicopro, nro_liquidacion, imp_a_pagar, imp_retenciones, imp_bruto, beneficiario
        $sql = "INSERT INTO sicopro_liquidaciones (ejer, op_sicopro, nro_liquidacion, imp_a_pagar, imp_retenciones, imp_liq, beneficiario) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        fgetcsv($handle, 0, $delimiter);

    } elseif ($es_sigue) {
        // SIGUE (BANCARIO)
        // Estructura: liqn, numero_interno, fecha, nro_pago, tipo, debito, credito, moneda, importe, obs_lote
        // Indices CSV: 0=LIQN, 1=Numero, 2=Fecha, 3=NroPago, ..., 8=Importe
        $sql = "INSERT INTO sicopro_sigue (liqn, numero, fecha, nro_pago, tipo, importe, obs_lote, ejer) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        fgetcsv($handle, 0, $delimiter);

    } elseif ($es_tgf) {
        // TGF
        // Estructura: ejer, saf, o_pago, f_anticipo, tipo_origen, total_anticipado, cta_bancaria
        $sql = "INSERT INTO sicopro_anticipos_tgf (ejer, saf, o_pago, f_anticipo, tipo_origen, total_anticipado, cta_bancaria) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        fgetcsv($handle, 0, $delimiter);
    }

    $filas_insertadas = 0;
    
    // Helpers para limpieza
    $cleanDate = function($d) {
        // Convierte dd/mm/yyyy o dd/mm/yy a YYYY-MM-DD
        if (!$d || $d == 'NULL') return null;
        $d = trim($d);
        if (strpos($d, '/') !== false) {
            $parts = explode('/', $d);
            if(count($parts)==3) {
                // Si el año tiene 2 digitos (ej 25), asumir 2000
                if(strlen($parts[2]) == 2) $parts[2] = '20'.$parts[2];
                return $parts[2].'-'.$parts[1].'-'.$parts[0];
            }
        }
        return null;
    };
    
    $cleanMoney = function($v) {
        // Para formato Argentino: 1.000,00 -> 1000.00
        if (!$v) return 0;
        $v = str_replace('.', '', $v); // Quitar miles
        $v = str_replace(',', '.', $v); // Coma a punto
        return floatval($v);
    };

    // Bucle de lectura
    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        
        // --- PROCESAMIENTO SICOPRO PRINCIPAL ---
        if ($es_sicopro_base) {
            // Filtrar basura (AA, RE, etc)
            $tipo_tramite = trim($data[3] ?? '');
            if (in_array($tipo_tramite, ['AA', 'RE', 'AC', 'CO', 'AP'])) continue;

            // Mapeo columnas (según tu CSV original)
            // Asumo que el orden es el estándar de tu exportación
            if(count($data) < 14) continue; 

            $params = [
                $data[0], // movejer
                $data[1], // movtrju
                $data[2], // movexpe
                $data[3], // movtrti
                $data[4], // movtrnu
                $data[5], // movfufi
                $cleanDate($data[6]), // movfeop
                $data[7], // movnupa
                utf8_encode($data[8]), // movprov (utf8 para acentos)
                utf8_encode($data[9]), // movrefe
                $cleanMoney($data[10]), // movimpo
                $cleanDate($data[11]), // movfech
                $data[12], // movcomp
                $cleanDate($data[13])  // movfere
            ];
            $stmt->execute($params);
            $filas_insertadas++;
        }

        // --- PROCESAMIENTO SIGUE (CORREGIDO) ---
        elseif ($es_sigue) {
            // CSV: 0=LIQN, 2=Fecha, 3=NroPago, 4=Tipo, 8=Importe, 9=Obs
            // Validar que tenga datos mínimos
            if (empty($data[0]) || $data[0] == '0') continue; // Saltar filas vacías o LIQN 0

            $liqn = trim($data[0]);
            $fecha = $cleanDate($data[2]);
            $nro_pago = trim($data[3]); // Nro Transf / Cheque
            $tipo = trim($data[4]);
            
            // CORRECCIÓN IMPORTES SIGUE:
            // El CSV trae punto como decimal (2919857.19). NO usar cleanMoney argentino.
            $importe = floatval($data[8]); 

            $obs = utf8_encode($data[9] ?? '');
            
            // Deducir Ejercicio (ej: si fecha es 2025-01-02, ejer = 2025)
            // Si no hay fecha, intentamos deducir por el año actual o un default
            $ejer = $fecha ? date('Y', strtotime($fecha)) : date('Y');

            $stmt->execute([$liqn, $data[1], $fecha, $nro_pago, $tipo, $importe, $obs, $ejer]);
            $filas_insertadas++;
        }

        // --- PROCESAMIENTO LIQUIDACIONES ---
        elseif ($es_liquidaciones) {
            // Ajustar índices según tu CSV real
            // Ejemplo típico: 0=Ejer, 1=OP, 2=Liq, 3=Neto, 4=Ret, 5=Bruto, 6=Benef
            if(count($data) < 6) continue;
            
            $stmt->execute([
                $data[0], // ejer
                $data[1], // op
                $data[2], // nro_liq
                $cleanMoney($data[3]), // a_pagar
                $cleanMoney($data[4]), // retenciones
                $cleanMoney($data[5]), // bruto (imp_liq)
                utf8_encode($data[6] ?? '') // benef
            ]);
            $filas_insertadas++;
        }
        
        // --- PROCESAMIENTO TGF ---
        elseif ($es_tgf) {
            $stmt->execute([
                $data[0], // ejer
                $data[1], // saf
                $data[2], // op
                $cleanDate($data[3]), // f_anticipo
                $data[4], // tipo
                $cleanMoney($data[5]), // total
                $data[6] ?? '' // cta
            ]);
            $filas_insertadas++;
        }
    }

    $pdo->commit();
    fclose($handle);
    
    echo json_encode(['success' => true, 'mensaje' => "Se importaron $filas_insertadas registros correctamente."]);

} catch (Exception $e) {
    $pdo->rollBack();
    fclose($handle);
    echo json_encode(['error' => 'Error en base de datos: ' . $e->getMessage()]);
}
?>
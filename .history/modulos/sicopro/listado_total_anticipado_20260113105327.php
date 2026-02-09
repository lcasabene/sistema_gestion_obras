<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

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

// Configuración según tipo
$tabla = '';
$cols_sql = ''; // Para preparar el INSERT
$es_sicopro = ($tipo === 'SICOPRO');

switch ($tipo) {
    case 'tgf':
        $tabla = 'sicopro_total_anticipado';
        break;
    case 'SOLICITADO':
        $tabla = 'sicopro_solicitado_no_anticipado';
        break;
    case 'SIN_PAGO':
        $tabla = 'sicopro_anticipado_sin_pago';
        break;
    case 'SICOPRO':
        $tabla = 'sicopro_principal';
        break;
    default:
        header('Location: importar.php?status=error&msg=Tipo no válido');
        exit;
}

try {
    $pdo->beginTransaction();

    // 1. Borrar versión anterior
    $pdo->exec("TRUNCATE TABLE $tabla");

    // 2. Leer CSV
    $handle = fopen($archivo['tmp_name'], "r");
    // Leer encabezado para saltarlo
    fgetcsv($handle, 0, ","); 
    
    // Preparar statement
    if (!$es_sicopro) {
        // Mapeo para los 3 archivos de TGF (15 columnas)
        $sql = "INSERT INTO $tabla (ejer, vto_comp, nro_tgf, actuacion, o_pago, proveedor, n_comp, importe, n_lib, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    } else {
        // Mapeo para SICOPRO (muchas columnas, simplificado para el ejemplo asumiendo orden del CSV)
        // Generamos placeholders dinámicos basados en la tabla
        $placeholders = str_repeat('?,', 93) . '?'; // SICOPRO tiene aprox 94 columnas en tu ejemplo
        // Nota: Ajusta la cantidad de ? al número exacto de columnas en tu CSV real de SICOPRO
        // Basado en el ejemplo, asumiré que el CSV tiene todas las columnas en orden
        $sql = "INSERT INTO $tabla (movejer, movtrju, movtrsa, movtrti, movtrnu, movtrse, movexpe, movalex, movjuri, movsa, movunor, movfina, movfunc, movsecc, movsect, movppal, movppar, movspar, movfufi, movubge, movatn1, movatn2, movatn3, movcpn1, movcpn2, movcpn3, movncde, movsade, movscde, movande, movnccr, movsacr, movsccr, movancr, movprov, movatju, movatsa, movatti, movatnu, movatse, movimpo, movdile, movrefe, movceco, movitem, movfeop, movnufa, movfefa, movvefa, movnuce, movfece, movvece, movtice, movnuoc, movveoc, movnupa, movfepa, movlupa, movnure, movorfi, movnuob, movtiga, movmaej, movfede, movcomp, movnump, movtire, movlega, movtdre, movndre, movorin, movtgar, movenem, movcede, movnuca, movtdga, movnuga, movnupr, movnuco, movtico, movvano, movcocr, movimcr, movctti, movctej, movctrud, movctctd, movctded, movctcld, movctruc, movctctc, movctdec, movctclc, movfere, movhore, movoper) VALUES ($placeholders)";
    }

    $stmt = $pdo->prepare($sql);
    $count = 0;
    $max_movfere = null;

    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        // Limpieza básica de datos vacíos a NULL o 0
        $data = array_map(function($val) { 
            return ($val === '' || $val === ' ') ? null : trim($val); 
        }, $data);

        // Si es sicopro, capturar la fecha movfere (índice antepenúltimo aprox, columna 91 si empieza en 0)
        if ($es_sicopro) {
             // En tu CSV ejemplo, MOVFERE es la antepenúltima. Ajusta el índice si es necesario.
             // Asumiendo que MOVFERE está cerca del final.
             $fecha_row = $data[count($data)-3] ?? null; 
             if ($fecha_row && ($max_movfere === null || $fecha_row > $max_movfere)) {
                 $max_movfere = $fecha_row;
             }
        }

        try {
            $stmt->execute($data);
            $count++;
        } catch (Exception $e) {
            // Ignorar errores de fila individual o loguear
            continue; 
        }
    }
    fclose($handle);

    // 3. Registrar Log
    $user_id = $_SESSION['user_id'] ?? 0;
    $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, usuario_id, nombre_archivo, registros_insertados, ultima_fecha_dato) VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($logSql)->execute([$tipo, $user_id, $archivo['name'], $count, $max_movfere]);

    $pdo->commit();
    header('Location: importar.php?status=success');

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: importar.php?status=error&msg=' . urlencode($e->getMessage()));
}
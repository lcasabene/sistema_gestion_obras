<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: importar.php'); exit; }

$tipo = $_POST['tipo_importacion'];
$archivo = $_FILES['archivo_csv'];

if ($archivo['error'] !== UPLOAD_ERR_OK) {
    header('Location: importar.php?status=error&msg=Error al subir archivo'); exit;
}

$es_sicopro_base = ($tipo === 'SICOPRO');

try {
    $pdo->beginTransaction();

    // 1. Borrar versión anterior ESPECÍFICA
    if ($es_sicopro_base) {
        $pdo->exec("TRUNCATE TABLE sicopro_principal"); // SICOPRO borra todo
        $sql = "INSERT INTO sicopro_principal (movejer, movtrju, movtrsa, movtrti, movtrnu, movtrse, movexpe, movalex, movjuri, movsa, movunor, movfina, movfunc, movsecc, movsect, movppal, movppar, movspar, movfufi, movubge, movatn1, movatn2, movatn3, movcpn1, movcpn2, movcpn3, movncde, movsade, movscde, movande, movnccr, movsacr, movsccr, movancr, movprov, movatju, movatsa, movatti, movatnu, movatse, movimpo, movdile, movrefe, movceco, movitem, movfeop, movnufa, movfefa, movvefa, movnuce, movfece, movvece, movtice, movnuoc, movveoc, movnupa, movfepa, movlupa, movnure, movorfi, movnuob, movtiga, movmaej, movfede, movcomp, movnump, movtire, movlega, movtdre, movndre, movorin, movtgar, movenem, movcede, movnuca, movtdga, movnuga, movnupr, movnuco, movtico, movvano, movcocr, movimcr, movctti, movctej, movctrud, movctctd, movctded, movctcld, movctruc, movctctc, movctdec, movctclc, movfere, movhore, movoper) VALUES (/* 94 placeholders aprox */ " . str_repeat('?,', 93) . "?)";
    } else {
        // Para los 3 reportes, borramos solo los de su tipo
        $stmtDel = $pdo->prepare("DELETE FROM sicopro_anticipos_tgf WHERE tipo_origen = ?");
        $stmtDel->execute([$tipo]);
        
        // Insertamos inyectando el tipo en la primera columna
        $sql = "INSERT INTO sicopro_anticipos_tgf (tipo_origen, ejer, vto_comp, nro_tgf, actuacion, o_pago, proveedor, n_comp, importe, n_lib, contrasiento, quita, n_anticipo, f_anticipo, pesos, letras) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    }

    // 2. Leer y cargar CSV
    $handle = fopen($archivo['tmp_name'], "r");
    fgetcsv($handle, 0, ","); // Saltar encabezado
    
    $stmt = $pdo->prepare($sql);
    $count = 0;
    $max_movfere = null;

    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        $data = array_map(function($val) { return ($val === '' || $val === ' ') ? null : trim($val); }, $data);

        if ($es_sicopro_base) {
            // Lógica SICOPRO Base
             $fecha_row = $data[count($data)-3] ?? null; 
             if ($fecha_row && ($max_movfere === null || $fecha_row > $max_movfere)) $max_movfere = $fecha_row;
             $stmt->execute($data);
        } else {
            // Lógica Unificada: Agregamos el $tipo al inicio del array de datos
            array_unshift($data, $tipo); // [TOTAL_ANTICIPADO, 2025, '2025-01-01', ...]
            $stmt->execute($data);
        }
        $count++;
    }
    fclose($handle);

    // 3. Log
    $user_id = $_SESSION['user_id'] ?? 0;
    $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, usuario_id, nombre_archivo, registros_insertados, ultima_fecha_dato) VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($logSql)->execute([$tipo, $user_id, $archivo['name'], $count, $max_movfere]);

    $pdo->commit();
    header('Location: importar.php?status=success');

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: importar.php?status=error&msg=' . urlencode($e->getMessage()));
}
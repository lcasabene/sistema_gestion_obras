<?php
// modulos/sicopro/procesar_importacion.php

// 1. CONFIGURACIÓN DE ERRORES (Vital para depurar pantallas blancas)
ini_set('display_errors', 0); // En producción AJAX, mejor 0 para no romper el JSON
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Configuración para archivos grandes
set_time_limit(300); 
ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');

// Funciones de Ayuda (Helpers)
function limpiarNumero($valor, $formato = 'estandar') {
    // $formato 'estandar': 1234.56 (usado en Sigue y TGF)
    // $formato 'argentino': 1.234,56 (usado en Liquidaciones)
    
    $valor = trim($valor);
    $valor = str_replace(['$', 'USD', ' '], '', $valor); // Quitar símbolos

    if ($formato === 'argentino') {
        $valor = str_replace('.', '', $valor); // Quitar punto de miles
        $valor = str_replace(',', '.', $valor); // Cambiar coma decimal a punto
    }
    
    return (float)$valor;
}

function convertirFecha($fecha, $origen = 'Y-m-d') {
    // Intenta convertir diferentes formatos a Y-m-d para SQL
    $fecha = trim($fecha);
    if (empty($fecha)) return null;

    try {
        if ($origen == 'd/m/y') { // Formato 02/01/25 (Sigue)
            $d = DateTime::createFromFormat('d/m/y', $fecha);
            return $d ? $d->format('Y-m-d') : null;
        }
        if ($origen == 'd/m/Y') { // Formato 02/01/2025 (Liquidaciones)
            $d = DateTime::createFromFormat('d/m/Y', $fecha);
            return $d ? $d->format('Y-m-d') : null;
        }
        // Por defecto asume Y-m-d o intenta parsear automático
        return date('Y-m-d', strtotime($fecha));
    } catch (Exception $e) {
        return null;
    }
}

$response = ['success' => false, 'mensaje' => '', 'error' => ''];

try {
    // 2. VERIFICACIÓN DE RUTAS Y AUTH
    $path_auth = __DIR__ . '/../../auth/middleware.php';
    $path_db   = __DIR__ . '/../../config/database.php';
    
    if (!file_exists($path_auth)) throw new Exception("Error interno: No se encuentra middleware.php");
    require_once $path_auth;
    // require_login(); // Descomentar si la sesión está activa
    require_once $path_db;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido.');
    }

    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir el archivo. Código: ' . ($_FILES['archivo_csv']['error'] ?? 'N/A'));
    }

    $tipo_importacion = $_POST['tipo_importacion'] ?? '';
    $archivo_tmp = $_FILES['archivo_csv']['tmp_name'];

    // 3. DEFINIR ESTRATEGIA SEGÚN EL TIPO DE ARCHIVO
    $delimitador = ';'; // Por defecto
    $saltear_encabezados = 1;
    $tabla_destino = '';
    
    // Aquí ajustamos la lógica según lo que el usuario seleccionó en el <select>
    switch ($tipo_importacion) {
        case 'sigue':
            $tabla_destino = 'sicopro_pagos_sigue'; // AJUSTAR A TU NOMBRE REAL DE TABLA
            $delimitador = ';';
            break;
            
        case 'liquidaciones':
            $tabla_destino = 'sicopro_liquidaciones';
            $delimitador = ';';
            break;
            
        case 'tgf': // Archivos TGF ANTICIPADA
        case 'anticipos': // Archivos ANTICIPADO SIN PAGO
            $tabla_destino = 'sicopro_anticipos_tgf'; // AJUSTAR A TU NOMBRE REAL DE TABLA
            $delimitador = ','; // ¡Estos archivos usan coma!
            break;

        case 'sicopro_general': // El archivo grande SICOPRO 2025...
            $tabla_destino = 'sicopro_movimientos';
            $delimitador = ';';
            break;

        default:
            throw new Exception("Tipo de importación no válido: $tipo_importacion");
    }

    // 4. PROCESAR ARCHIVO
    $handle = fopen($archivo_tmp, "r");
    if (!$handle) throw new Exception("No se pudo abrir el archivo CSV.");

    $pdo->beginTransaction();
    $filas = 0;
    $errores_fila = 0;

    // Saltear encabezados
    for ($i = 0; $i < $saltear_encabezados; $i++) {
        fgetcsv($handle, 0, $delimitador);
    }

    $sql = "";
    $stmt = null;

    while (($data = fgetcsv($handle, 4096, $delimitador)) !== FALSE) {
        // Filtrar filas vacías
        if (count($data) < 2 || empty($data[0])) continue;

        try {
            if ($tipo_importacion === 'sigue') {
                // Estructura Sigue: LIQN(0);Numero(1);Fecha(2);Nro.Pago(3);Tipo(4);...Importe(8);...
                if (!$stmt) {
                    $sql = "INSERT INTO sicopro_pagos_sigue (numero_transaccion, fecha_pago, nro_pago_sistema, tipo, importe, beneficiario, observacion) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                }
                
                $fecha = convertirFecha($data[2], 'd/m/y'); // Viene como 02/01/25
                $importe = limpiarNumero($data[8], 'estandar'); // Viene con punto 2919857.19
                $beneficiario = $data[9] ?? ''; // Columna ObservacionLote suele tener el nombre
                
                $stmt->execute([$data[1], $fecha, $data[3], $data[4], $importe, $beneficiario, $data[10]??'']);
                $filas++;

            } elseif ($tipo_importacion === 'liquidaciones') {
                // Estructura Liq: N°(0);FECHA(1);EXPEDIENTE(2);GEDO(3);OP(4);RAZON(5);PROV(6);IMP_LIQ(7)...
                if (!$stmt) {
                    $sql = "INSERT INTO sicopro_liquidaciones (fecha, expediente, gedo, op_sicopro, razon_social, importe_liq, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                }

                $fecha = convertirFecha($data[1], 'd/m/Y'); // Viene como 09/01/2026
                $importe = limpiarNumero($data[7], 'argentino'); // Viene como 8.970.000,00
                
                $stmt->execute([$fecha, $data[2], $data[3], $data[4], $data[5], $importe, $data[15]??'']);
                $filas++;

            } elseif ($tipo_importacion === 'tgf' || $tipo_importacion === 'anticipos') {
                // Estructura TGF: Ejer(0), Vto(1), NroTGF(2), Actuacion(3), OP(4), Prov(5), NComp(6), Importe(7)...
                if (!$stmt) {
                    $sql = "INSERT INTO sicopro_anticipos_tgf (ejercicio, fecha_vto, nro_tgf, actuacion, op_numero, proveedor, importe) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                }

                $importe = limpiarNumero($data[7], 'estandar'); // En CSV TGF viene con punto
                $stmt->execute([$data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $importe]);
                $filas++;
            }

        } catch (Exception $eRow) {
            // Si falla una fila, la ignoramos pero seguimos (o puedes hacer rollback)
            // error_log("Error en fila: " . $eRow->getMessage());
            $errores_fila++;
        }
    }

    $pdo->commit();
    fclose($handle);

    // LOG (Opcional)
    try {
        $logSql = "INSERT INTO sicopro_import_log (tipo_importacion, fecha_subida, registros_insertados) VALUES (?, NOW(), ?)";
        $pdo->prepare($logSql)->execute([$tipo_importacion, $filas]);
    } catch(Exception $e) {}

    $response['success'] = true;
    $response['mensaje'] = "Proceso finalizado. Filas importadas: $filas. Errores/Omitidos: $errores_fila";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $response['error'] = $e->getMessage();
}

// Limpiar cualquier salida previa para asegurar JSON puro
ob_clean(); 
echo json_encode($response);
?>
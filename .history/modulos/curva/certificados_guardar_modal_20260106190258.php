<?php
// modulos/certificados/certificados_guardar_modal.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login(); // Asumo que tienes un sistema de login, si no, comenta esto.
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // ---------------------------------------------------------
        // 1. RECUPERACIÓN Y LIMPIEZA DE DATOS
        // ---------------------------------------------------------
        
        // IDs clave
        $cert_id       = isset($_POST['cert_id']) ? (int)$_POST['cert_id'] : 0;
        $obra_id       = $_POST['obra_id'];
        $curva_item_id = !empty($_POST['curva_item_id']) ? $_POST['curva_item_id'] : null; // VITAL
        $version_prev  = $_POST['version_prev_id'] ?? 0; // Para volver a la pantalla anterior

        // Datos descriptivos
        $tipo    = $_POST['tipo'];
        $periodo = $_POST['periodo']; // Viene del item de la curva
        $nro     = $_POST['nro_certificado'] ?? '';

        // Helper para limpiar moneda (ej: "1.500,00" -> 1500.00)
        $f = function($v){ 
            if(!$v) return 0;
            // Eliminar puntos de mil, cambiar coma decimal por punto
            return (float)str_replace(',','.', str_replace('.','',$v)); 
        };

        // Valores Monetarios y Porcentuales
        $monto_bruto   = $f($_POST['monto_bruto'] ?? 0);
        $avance_fisico = isset($_POST['avance_fisico']) ? (float)$_POST['avance_fisico'] : 0;
        $fri           = isset($_POST['fri']) ? (float)$_POST['fri'] : 1.0000;
        
        // Deducciones
        $fondo_reparo_sust  = isset($_POST['fondo_reparo_sustituido']) ? 1 : 0;
        $fondo_reparo_pct   = $fondo_reparo_sust ? 0 : 5; 
        $fondo_reparo_monto = $f($_POST['fondo_reparo_monto'] ?? 0);
        
        $anticipo_pct_ap = (float)($_POST['anticipo_pct_aplicado'] ?? 0);
        $anticipo_desc   = $f($_POST['anticipo_descuento'] ?? 0);
        $multas          = $f($_POST['multas_monto'] ?? 0);
        $monto_neto      = $f($_POST['monto_neto'] ?? 0); 

        // Lógica de columnas según tipo (Básico vs Redet)
        $monto_basico = 0; 
        $monto_redet  = 0;
        
        if ($tipo === 'REDETERMINACION') {
            $monto_redet = $monto_bruto;
            // En redet el avance físico suele ser 0 o irrelevante para la curva S física, 
            // pero mantenemos el dato si viniera.
        } else {
            // ORDINARIO o ANTICIPO
            $monto_basico = $monto_bruto;
        }

        // Validación básica
        if(empty($curva_item_id)) {
            throw new Exception("Error de vinculación: No se recibió el ID del item de curva.");
        }

        // ---------------------------------------------------------
        // 2. LÓGICA DE GUARDADO (INSERT O UPDATE)
        // ---------------------------------------------------------

        if ($cert_id == 0) {
            // === INSERTAR NUEVO ===

            // 1. Obtener Empresa ID (si no viene en post, lo sacamos de la obra)
            $stmtEmp = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
            $stmtEmp->execute([$obra_id]);
            $empresa_id = $stmtEmp->fetchColumn();

            // 2. Autonumeración si está vacío
            if(empty($nro)) {
                $stmtNro = $pdo->prepare("SELECT COALESCE(MAX(nro_certificado), 0) + 1 FROM certificados WHERE obra_id = ?");
                $stmtNro->execute([$obra_id]);
                $nro = $stmtNro->fetchColumn();
            }

            $sql = "INSERT INTO certificados 
                (obra_id, empresa_id, curva_item_id, nro_certificado, tipo, periodo, fecha_medicion,
                 monto_basico, monto_redeterminado, monto_bruto, fri,
                 fondo_reparo_pct, fondo_reparo_sustituido, fondo_reparo_monto, 
                 anticipo_pct_aplicado, anticipo_descuento, multas_monto,
                 monto_neto_pagar, avance_fisico_mensual, estado)
                VALUES 
                (:obra, :empresa, :item_id, :nro, :tipo, :periodo, NOW(),
                 :basico, :redet, :bruto, :fri,
                 :fr_pct, :fr_sust, :fr_monto,
                 :ant_pct, :ant_desc, :multas,
                 :neto, :avance, 'BORRADOR')";
            
            $stmt = $pdo->prepare($sql);
            $execParams = [
                ':obra'      => $obra_id,
                ':empresa'   => $empresa_id,
                ':item_id'   => $curva_item_id, // <--- Aquí guardamos la vinculación estricta
                ':nro'       => $nro,
                ':tipo'      => $tipo,
                ':periodo'   => $periodo,
                ':basico'    => $monto_basico,
                ':redet'     => $monto_redet,
                ':bruto'     => $monto_bruto,
                ':fri'       => $fri,
                ':fr_pct'    => $fondo_reparo_pct,
                ':fr_sust'   => $fondo_reparo_sust,
                ':fr_monto'  => $fondo_reparo_monto,
                ':ant_pct'   => $anticipo_pct_ap,
                ':ant_desc'  => $anticipo_desc,
                ':multas'    => $multas,
                ':neto'      => $monto_neto,
                ':avance'    => $avance_fisico
            ];
            
            if (!$stmt->execute($execParams)) {
                throw new Exception("Error al ejecutar INSERT SQL.");
            }
            $cert_id = $pdo->lastInsertId();

        } else {
            // === ACTUALIZAR EXISTENTE (UPDATE) ===
            
            // Nota: No actualizamos obra_id ni empresa_id por seguridad, suelen ser fijos.
            // Sí actualizamos curva_item_id por si hubo una corrección de vínculo.

            $sql = "UPDATE certificados SET 
                nro_certificado = :nro,
                curva_item_id = :item_id,
                monto_basico = :basico, 
                monto_redeterminado = :redet, 
                monto_bruto = :bruto, 
                fri = :fri,
                fondo_reparo_pct = :fr_pct, 
                fondo_reparo_sustituido = :fr_sust, 
                fondo_reparo_monto = :fr_monto,
                anticipo_pct_aplicado = :ant_pct, 
                anticipo_descuento = :ant_desc, 
                multas_monto = :multas,
                monto_neto_pagar = :neto, 
                avance_fisico_mensual = :avance
                WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $execParams = [
                ':nro'       => $nro,
                ':item_id'   => $curva_item_id, // Permitimos corregir el vínculo si fuera necesario
                ':basico'    => $monto_basico,
                ':redet'     => $monto_redet,
                ':bruto'     => $monto_bruto,
                ':fri'       => $fri,
                ':fr_pct'    => $fondo_reparo_pct,
                ':fr_sust'   => $fondo_reparo_sust,
                ':fr_monto'  => $fondo_reparo_monto,
                ':ant_pct'   => $anticipo_pct_ap,
                ':ant_desc'  => $anticipo_desc,
                ':multas'    => $multas,
                ':neto'      => $monto_neto,
                ':avance'    => $avance_fisico,
                ':id'        => $cert_id
            ];

            if (!$stmt->execute($execParams)) {
                throw new Exception("Error al ejecutar UPDATE SQL.");
            }
        }

        // ---------------------------------------------------------
        // 3. FIN Y REDIRECCIÓN
        // ---------------------------------------------------------

        $pdo->commit();

        // Redirigir a la misma versión de curva donde estaba el usuario
        if($version_prev > 0){
             header("Location: ../curva/curva_ver.php?version_id=$version_prev&msg=ok");
        } else {
             // Fallback por si se perdió el ID de versión
             header("Location: ../curva/curva_list.php?obra_id=$obra_id&msg=ok");
        }
        exit;

    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        // Mostrar error amigable
        die("<div style='color:red; padding:20px; border:1px solid red; margin:20px;'>
                <h3>Error al guardar</h3>
                <p>" . $e->getMessage() . "</p>
                <a href='javascript:history.back()'>Volver</a>
             </div>");
    }
} else {
    die("Acceso no válido.");
}
?>
<?php
// modulos/certificados/certificados_guardar_modal.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login(); 
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // ==================================================================
        // 1. OBTENCIÓN Y LIMPIEZA DE DATOS
        // ==================================================================
        
        $cert_id       = isset($_POST['cert_id']) ? (int)$_POST['cert_id'] : 0;
        $obra_id       = $_POST['obra_id'];
        $version_prev  = $_POST['version_prev_id'] ?? 0;
        
        // 1. CORRECCIÓN ID: Validar que el ID venga del formulario (NO usar 275 fijo)
        if (isset($_POST['curva_item_id']) && is_numeric($_POST['curva_item_id']) && $_POST['curva_item_id'] > 0) {
            $curva_item_id = (int)$_POST['curva_item_id'];
        } else {
            throw new Exception("Error de Datos: No se recibió el ID del Item de Curva. Recargue la página e intente nuevamente.");
        }

        $tipo    = $_POST['tipo'];
        $periodo = $_POST['periodo'];
        $nro     = $_POST['nro_certificado'] ?? '';

        // --- FUNCIONES DE LIMPIEZA DIFERENCIADAS ---

        // A. Para Inputs VISIBLES con máscara (Ej: "1.500,50")
        // Elimina puntos de mil y cambia coma por punto.
        $f_moneda = function($v){ 
            if(empty($v)) return 0;
            return (float)str_replace(',','.', str_replace('.','',$v)); 
        };

        // B. Para Inputs OCULTOS o NUMÉRICOS (Ej: "1500.50" o "1500,50")
        // NO elimina puntos, solo asegura que la coma sea punto (por si el navegador está en ES).
        $f_standard = function($v){
            if(empty($v)) return 0;
            return (float)str_replace(',','.', $v);
        };

        // --- PROCESAMIENTO ---

        // 1. Montos Visibles (Usan f_moneda porque vienen con puntos de mil)
        $monto_bruto        = $f_moneda($_POST['monto_bruto'] ?? 0);
        $fondo_reparo_monto = $f_moneda($_POST['fondo_reparo_monto'] ?? 0);
        $anticipo_desc      = $f_moneda($_POST['anticipo_descuento'] ?? 0);
        $multas             = $f_moneda($_POST['multas_monto'] ?? 0);

        // 2. Valores Calculados/Numéricos (Usan f_standard porque vienen limpios de JS)
        // CORRECCIÓN CRÍTICA: 'monto_neto' viene de un .toFixed(2) en JS (tiene punto, no miles)
        $monto_neto      = $f_standard($_POST['monto_neto'] ?? 0); 
        $avance_fisico   = $f_standard($_POST['avance_fisico'] ?? 0);
        $anticipo_pct_ap = $f_standard($_POST['anticipo_pct_aplicado'] ?? 0);

        // FRI: Validación especial
        $fri_input = $_POST['fri'] ?? '';
        $fri = ($fri_input === '') ? 1.0000 : $f_standard($fri_input);
        
        // Deducciones flags
        $fondo_reparo_sust  = isset($_POST['fondo_reparo_sustituido']) ? 1 : 0;
        $fondo_reparo_pct   = $fondo_reparo_sust ? 0 : 5; 

        // Separación Lógica
        $monto_basico = 0; 
        $monto_redet  = 0;
        
        if ($tipo === 'REDETERMINACION') {
            $monto_redet = $monto_bruto;
        } else {
            $monto_basico = $monto_bruto;
        }

        // ==================================================================
        // 2. OPERACIONES EN BASE DE DATOS
        // ==================================================================

        if ($cert_id == 0) {
            // INSERT
            $stmtEmp = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
            $stmtEmp->execute([$obra_id]);
            $empresa_id = $stmtEmp->fetchColumn();

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
            $stmt->execute([
                ':obra'      => $obra_id,
                ':empresa'   => $empresa_id,
                ':item_id'   => $curva_item_id,
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
            ]);
            $cert_id = $pdo->lastInsertId();

        } else {
            // UPDATE
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
            $stmt->execute([
                ':nro'       => $nro,
                ':item_id'   => $curva_item_id,
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
            ]);
        }

        $pdo->commit();
        
        // Redirección
        if($version_prev > 0){
             header("Location: ../curva/curva_ver.php?version_id=$version_prev&msg=ok");
        } else {
             header("Location: ../certificados/certificados_listado.php?obra_id=$obra_id&msg=ok");
        }
        exit;

    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        die("<div style='color:red; padding:20px;'><h3>Error al Guardar:</h3>" . $e->getMessage() . "<br><button onclick='history.back()'>Volver</button></div>");
    }
} else {
    die("Acceso denegado.");
}
?>
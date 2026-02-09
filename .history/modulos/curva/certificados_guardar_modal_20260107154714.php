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
        
        // --- VALIDACIÓN DE VINCULACIÓN (CORREGIDO) ---
        // Antes estaba fijo en 275. Ahora valida que venga del formulario.
        if (isset($_POST['curva_item_id']) && is_numeric($_POST['curva_item_id']) && $_POST['curva_item_id'] > 0) {
            $curva_item_id = (int)$_POST['curva_item_id'];
        } else {
            throw new Exception("Error Crítico de Datos: No se recibió el ID del Item de la Curva. Intente recargar la página.");
        }

        $tipo    = $_POST['tipo'];
        $periodo = $_POST['periodo'];
        $nro     = $_POST['nro_certificado'] ?? '';

        // --- FUNCIONES DE LIMPIEZA ---
        
        // 1. Para MONEDA (Quita puntos de miles, cambia coma por punto)
        // Ejemplo: "1.500,50" -> 1500.50
        $f_moneda = function($v){ 
            if(empty($v)) return 0;
            return (float)str_replace(',','.', str_replace('.','',$v)); 
        };

        // 2. Para DECIMALES SIMPLES (Porcentajes, FRI, Coeficientes)
        // Ejemplo: "1,25" -> 1.25 (No quita puntos de miles para no confundir)
        $f_decimal = function($v){
            if(empty($v)) return 0;
            return (float)str_replace(',','.', $v);
        };

        // --- PROCESAMIENTO DE VALORES ---

        // Montos (Usan limpieza de moneda)
        $monto_bruto   = $f_moneda($_POST['monto_bruto'] ?? 0);
        $monto_neto    = $f_moneda($_POST['monto_neto'] ?? 0);
        
        // Decimales (Usan limpieza simple)
        $avance_fisico = $f_decimal($_POST['avance_fisico'] ?? 0);
        
        // FRI: Validación especial para que no sea 0 si viene vacío
        $fri_input = $_POST['fri'] ?? '';
        $fri = ($fri_input === '') ? 1.0000 : $f_decimal($fri_input);
        
        // Deducciones
        $fondo_reparo_sust  = isset($_POST['fondo_reparo_sustituido']) ? 1 : 0;
        $fondo_reparo_pct   = $fondo_reparo_sust ? 0 : 5; 
        $fondo_reparo_monto = $f_moneda($_POST['fondo_reparo_monto'] ?? 0);
        
        $anticipo_pct_ap = $f_decimal($_POST['anticipo_pct_aplicado'] ?? 0);
        $anticipo_desc   = $f_moneda($_POST['anticipo_descuento'] ?? 0);
        $multas          = $f_moneda($_POST['multas_monto'] ?? 0);

        // Separación Lógica: Básico vs Redeterminación
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
            // --- INSERTAR NUEVO ---
            
            // Buscar empresa
            $stmtEmp = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
            $stmtEmp->execute([$obra_id]);
            $empresa_id = $stmtEmp->fetchColumn();

            // Autonumeración si está vacío
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
            // --- ACTUALIZAR EXISTENTE ---
            
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
        
        // ==================================================================
        // 3. REDIRECCIÓN (CORREGIDA)
        // ==================================================================

        if($version_prev > 0){
             // Si venimos de la vista de curva, volvemos ahí
             header("Location: ../curva/curva_ver.php?version_id=$version_prev&msg=ok");
        } else {
             // Si venimos del listado general, vamos a certificados_listado.php (NO list.php)
             header("Location: ../certificados/certificados_listado.php?obra_id=$obra_id&msg=ok");
        }
        exit;

    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        
        // Mensaje de Error Amigable
        die("
            <div style='font-family:sans-serif; max-width:600px; margin:50px auto; padding: 20px; border: 1px solid #dc3545; background-color: #f8d7da; border-radius: 8px; color: #842029;'>
                <h3 style='margin-top:0'>⛔ Error al Guardar Certificado</h3>
                <p><strong>Detalle técnico:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                <hr style='border-top: 1px solid #f5c2c7;'>
                <p>Por favor, regrese e intente nuevamente. Si el error persiste, verifique que los campos numéricos sean válidos.</p>
                <button onclick='history.back()' style='padding:10px 20px; cursor:pointer; background:#dc3545; color:white; border:none; border-radius:4px; font-weight:bold;'>&larr; Volver al Formulario</button>
            </div>
        ");
    }
} else {
    die("Acceso denegado.");
}
?>
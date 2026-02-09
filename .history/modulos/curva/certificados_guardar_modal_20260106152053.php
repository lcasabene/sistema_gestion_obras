<?php
// modulos/certificados/certificados_guardar_modal.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // 1. Recibir y limpiar datos básicos
        $obra_id = $_POST['obra_id'];
        $periodo = $_POST['periodo'];
        $tipo    = $_POST['tipo'];
        $cert_id = (int)$_POST['cert_id']; 
        
        // CAPTURA SEGURA DEL ID DE VINCULACIÓN
        $curva_item_id = null;
        if (isset($_POST['curva_item_id']) && is_numeric($_POST['curva_item_id']) && $_POST['curva_item_id'] > 0) {
            $curva_item_id = (int)$_POST['curva_item_id'];
        }

        // Función para limpiar montos (de '1.000,00' a 1000.00)
        $f = function($v){ 
            if(!$v) return 0;
            return (float)str_replace(',','.', str_replace('.','',$v)); 
        };
        
        $monto_bruto = $f($_POST['monto_bruto'] ?? 0);
        $avance_fisico = isset($_POST['avance_fisico']) ? (float)$_POST['avance_fisico'] : 0;
        $fri = isset($_POST['fri']) ? (float)$_POST['fri'] : 1.0000;
        
        // Lógica de Fondo de Reparo
        $fondo_reparo_sust = isset($_POST['fondo_reparo_sustituido']) ? 1 : 0;
        $fondo_reparo_pct = $fondo_reparo_sust ? 0 : 5; 
        $fondo_reparo_monto = $f($_POST['fondo_reparo_monto'] ?? 0);
        
        // Deducciones
        $anticipo_pct_ap = (float)($_POST['anticipo_pct_aplicado'] ?? 0);
        $anticipo_desc = $f($_POST['anticipo_descuento'] ?? 0);
        $multas = $f($_POST['multas_monto'] ?? 0);
        $monto_neto = $f($_POST['monto_neto'] ?? 0); 

        // Definir Básico vs Redeterminación
        $monto_basico = 0; 
        $monto_redet = 0;
        if($tipo == 'ORDINARIO' || $tipo == 'ANTICIPO') {
            $monto_basico = $monto_bruto;
        } elseif ($tipo == 'REDETERMINACION') {
            $monto_redet = $monto_bruto;
        }

        // 2. INSERT O UPDATE (Usando parámetros nombrados para evitar errores de orden)
        if ($cert_id == 0) {
            // --- INSERTAR NUEVO ---
            
            // Obtener empresa y nro automático
            $stmtEmp = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
            $stmtEmp->execute([$obra_id]);
            $empresa_id = $stmtEmp->fetchColumn();

            $nro = $_POST['nro_certificado'] ?? '';
            if(empty($nro)) {
                $stmtNro = $pdo->prepare("SELECT COALESCE(MAX(nro_certificado), 0) + 1 FROM certificados WHERE obra_id = ?");
                $stmtNro->execute([$obra_id]);
                $nro = $stmtNro->fetchColumn();
            }

            $sql = "INSERT INTO certificados 
                (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion,
                 monto_basico, monto_redeterminado, monto_bruto, fri,
                 fondo_reparo_pct, fondo_reparo_sustituido, fondo_reparo_monto, 
                 anticipo_pct_aplicado, anticipo_descuento, multas_monto,
                 monto_neto_pagar, avance_fisico_mensual, estado, curva_item_id)
                VALUES 
                (:obra, :empresa, :nro, :tipo, :periodo, NOW(),
                 :basico, :redet, :bruto, :fri,
                 :fr_pct, :fr_sust, :fr_monto,
                 :ant_pct, :ant_desc, :multas,
                 :neto, :avance, 'BORRADOR', :item_id)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':obra' => $obra_id,
                ':empresa' => $empresa_id,
                ':nro' => $nro,
                ':tipo' => $tipo,
                ':periodo' => $periodo,
                ':basico' => $monto_basico,
                ':redet' => $monto_redet,
                ':bruto' => $monto_bruto,
                ':fri' => $fri,
                ':fr_pct' => $fondo_reparo_pct,
                ':fr_sust' => $fondo_reparo_sust,
                ':fr_monto' => $fondo_reparo_monto,
                ':ant_pct' => $anticipo_pct_ap,
                ':ant_desc' => $anticipo_desc,
                ':multas' => $multas,
                ':neto' => $monto_neto,
                ':avance' => $avance_fisico,
                ':item_id' => $curva_item_id  // Aquí se guarda el 275
            ]);
            $cert_id = $pdo->lastInsertId();

        } else {
            // --- ACTUALIZAR EXISTENTE ---
            $nro = $_POST['nro_certificado'];
            
            $sql = "UPDATE certificados SET 
                nro_certificado = :nro,
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
                avance_fisico_mensual = :avance, 
                curva_item_id = :item_id
                WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nro' => $nro,
                ':basico' => $monto_basico,
                ':redet' => $monto_redet,
                ':bruto' => $monto_bruto,
                ':fri' => $fri,
                ':fr_pct' => $fondo_reparo_pct,
                ':fr_sust' => $fondo_reparo_sust,
                ':fr_monto' => $fondo_reparo_monto,
                ':ant_pct' => $anticipo_pct_ap,
                ':ant_desc' => $anticipo_desc,
                ':multas' => $multas,
                ':neto' => $monto_neto,
                ':avance' => $avance_fisico,
                ':item_id' => $curva_item_id, // Actualiza la vinculación
                ':id' => $cert_id
            ]);
        }

        // 3. GUARDAR FUENTES (Borrar y re-insertar)
        $pdo->prepare("DELETE FROM certificados_financiamiento WHERE certificado_id = ?")->execute([$cert_id]);

        if (isset($_POST['fuente_id']) && is_array($_POST['fuente_id'])) {
            $stmtF = $pdo->prepare("INSERT INTO certificados_financiamiento (certificado_id, fuente_id, porcentaje, monto_asignado) VALUES (?, ?, ?, ?)");
            foreach ($_POST['fuente_id'] as $k => $fid) {
                if (!empty($fid)) {
                    $pct = $_POST['fuente_pct'][$k] ?? 0;
                    $montoRaw = $_POST['fuente_monto'][$k] ?? '0';
                    $montoLimpio = $f($montoRaw);
                    $stmtF->execute([$cert_id, $fid, $pct, $montoLimpio]);
                }
            }
        }

        // 4. GUARDAR FACTURAS (Borrar y re-insertar)
        $pdo->prepare("UPDATE comprobantes_arca SET estado_uso='DISPONIBLE' WHERE id IN (SELECT comprobante_arca_id FROM certificados_facturas WHERE certificado_id=?)")->execute([$cert_id]);
        $pdo->prepare("DELETE FROM certificados_facturas WHERE certificado_id = ?")->execute([$cert_id]);

        if (isset($_POST['facturas_arca'])) {
            $stmtLink = $pdo->prepare("INSERT INTO certificados_facturas (certificado_id, comprobante_arca_id) VALUES (?, ?)");
            $stmtEstado = $pdo->prepare("UPDATE comprobantes_arca SET estado_uso='VINCULADO' WHERE id=?");
            foreach ($_POST['facturas_arca'] as $facId) {
                $stmtLink->execute([$cert_id, $facId]);
                $stmtEstado->execute([$facId]);
            }
        }

        $pdo->commit();

        // 5. Redirección
        $stmtVer = $pdo->prepare("SELECT id FROM curva_version WHERE obra_id = ? AND es_vigente=1");
        $stmtVer->execute([$obra_id]);
        $verId = $stmtVer->fetchColumn();
        
        if(!$verId){
             header("Location: ../certificados/certificados_list.php?obra_id=$obra_id&msg=ok");
        } else {
             header("Location: ../curva/curva_ver.php?version_id=$verId&msg=ok");
        }
        exit;

    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        die("Error al guardar: " . $e->getMessage());
    }
}
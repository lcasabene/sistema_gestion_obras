<?php
// modulos/certificados/certificados_guardar_modal.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $obra_id = $_POST['obra_id'];
        $periodo = $_POST['periodo'];
        $tipo = $_POST['tipo'];
        $cert_id = (int)$_POST['cert_id']; 
        
        // 1. CAPTURAMOS EL ID (Si viene vacío o 0, se guarda NULL)
        $curva_item_id = 275;

        $f = function($v){ return (float)str_replace(',','.', str_replace('.','',$v)); };
        
        $monto_bruto = $f($_POST['monto_bruto']);
        $avance_fisico = isset($_POST['avance_fisico']) ? (float)$_POST['avance_fisico'] : 0;
        $fri = isset($_POST['fri']) ? (float)$_POST['fri'] : 1.0000;
        $fondo_reparo_sust = isset($_POST['fondo_reparo_sustituido']) ? 1 : 0;
        $fondo_reparo_pct = $fondo_reparo_sust ? 0 : 5; 
        $fondo_reparo_monto = $f($_POST['fondo_reparo_monto']);
        $anticipo_pct_ap = (float)$_POST['anticipo_pct_aplicado'];
        $anticipo_desc = $f($_POST['anticipo_descuento']);
        $multas = $f($_POST['multas_monto']);
        $monto_neto = $f($_POST['monto_neto']); 

        $monto_basico = 0; $monto_redet = 0;
        if($tipo == 'ORDINARIO' || $tipo == 'ANTICIPO') $monto_basico = $monto_bruto;
        elseif ($tipo == 'REDETERMINACION') $monto_redet = $monto_bruto;

        if ($cert_id == 0) {
            // --- INSERTAR NUEVO ---
            $stmtEmp = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
            $stmtEmp->execute([$obra_id]);
            $empresa_id = $stmtEmp->fetchColumn();

            $nro = $_POST['nro_certificado'] ?? '';
            if(empty($nro)) {
                $stmtNro = $pdo->prepare("SELECT COALESCE(MAX(nro_certificado), 0) + 1 FROM certificados WHERE obra_id = ?");
                $stmtNro->execute([$obra_id]);
                $nro = $stmtNro->fetchColumn();
            }

            // AQUI ESTABA EL ERROR ANTES: Faltaba agregar curva_item_id en el SQL
            $sql = "INSERT INTO certificados 
                (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion,
                 monto_basico, monto_redeterminado, monto_bruto, fri,
                 fondo_reparo_pct, fondo_reparo_sustituido, fondo_reparo_monto, 
                 anticipo_pct_aplicado, anticipo_descuento, multas_monto,
                 monto_neto_pagar, avance_fisico_mensual, estado, curva_item_id)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'BORRADOR', ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $obra_id, $empresa_id, $nro, $tipo, $periodo,
                $monto_basico, $monto_redet, $monto_bruto, $fri,
                $fondo_reparo_pct, $fondo_reparo_sust, $fondo_reparo_monto,
                $anticipo_pct_ap, $anticipo_desc, $multas,
                $monto_neto, $avance_fisico, $curva_item_id // <--- Aquí pasamos el 275
            ]);
            $cert_id = $pdo->lastInsertId();

        } else {
            // --- ACTUALIZAR EXISTENTE ---
            $nro = $_POST['nro_certificado'];
            
            $sql = "UPDATE certificados SET 
                nro_certificado=?,
                monto_basico=?, monto_redeterminado=?, monto_bruto=?, fri=?,
                fondo_reparo_pct=?, fondo_reparo_sustituido=?, fondo_reparo_monto=?,
                anticipo_pct_aplicado=?, anticipo_descuento=?, multas_monto=?,
                monto_neto_pagar=?, avance_fisico_mensual=?, curva_item_id=?
                WHERE id=?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $nro,
                $monto_basico, $monto_redet, $monto_bruto, $fri,
                $fondo_reparo_pct, $fondo_reparo_sust, $fondo_reparo_monto,
                $anticipo_pct_ap, $anticipo_desc, $multas,
                $monto_neto, $avance_fisico, $curva_item_id, 
                $cert_id
            ]);
        }

        // 2. FUENTES
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

        // 3. FACTURAS
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
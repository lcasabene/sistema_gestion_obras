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
        
        // CAPTURA ROBUSTA DEL ID
        // Si viene 0 o cadena vacía, lo dejamos NULL, si no, guardamos el entero
        $curva_item_id = (isset($_POST['curva_item_id']) && $_POST['curva_item_id'] !== '') ? (int)$_POST['curva_item_id'] : null;

        // Normalización: si llega 0 (o algo no válido), lo tratamos como NULL
        if ($curva_item_id !== null && (int)$curva_item_id <= 0) {
            $curva_item_id = null;
        }

        // Si no viene curva_item_id, intentamos resolverlo por obra + período en la curva vigente
        if ($curva_item_id === null) {
            $stmtVig = $pdo->prepare("SELECT id FROM curva_version WHERE obra_id = ? AND vigente = 1 ORDER BY id DESC LIMIT 1");
            $stmtVig->execute([$obra_id]);
            $version_vigente_id = (int)$stmtVig->fetchColumn();

            if ($version_vigente_id > 0) {
                $stmtItem = $pdo->prepare("SELECT id FROM curva_item WHERE version_id = ? AND periodo = ? LIMIT 1");
                $stmtItem->execute([$version_vigente_id, $periodo]);
                $tmpItemId = (int)$stmtItem->fetchColumn();
                if ($tmpItemId > 0) {
                    $curva_item_id = $tmpItemId;
                }
            }
        }

        // ANTICIPO: si se intenta crear uno nuevo para el mismo período, reutilizamos el existente (evita duplicados)
        if ($cert_id === 0 && $tipo === 'ANTICIPO') {
            if ($curva_item_id !== null) {
                $stmtEx = $pdo->prepare("SELECT id, nro_certificado FROM certificados 
                                         WHERE obra_id = ? AND tipo = 'ANTICIPO' AND periodo = ? AND curva_item_id = ? 
                                         ORDER BY id DESC LIMIT 1");
                $stmtEx->execute([$obra_id, $periodo, $curva_item_id]);
            } else {
                $stmtEx = $pdo->prepare("SELECT id, nro_certificado FROM certificados 
                                         WHERE obra_id = ? AND tipo = 'ANTICIPO' AND periodo = ? AND curva_item_id IS NULL 
                                         ORDER BY id DESC LIMIT 1");
                $stmtEx->execute([$obra_id, $periodo]);
            }
            $ex = $stmtEx->fetch(PDO::FETCH_ASSOC);
            if ($ex && (int)$ex['id'] > 0) {
                $cert_id = (int)$ex['id'];
                // aseguramos nro_certificado para el UPDATE
                $_POST['nro_certificado'] = $ex['nro_certificado'];
            }
        }


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
            // INSERT
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
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'BORRADOR', ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $obra_id, $empresa_id, $nro, $tipo, $periodo,
                $monto_basico, $monto_redet, $monto_bruto, $fri,
                $fondo_reparo_pct, $fondo_reparo_sust, $fondo_reparo_monto,
                $anticipo_pct_ap, $anticipo_desc, $multas,
                $monto_neto, $avance_fisico, $curva_item_id
            ]);
            $cert_id = $pdo->lastInsertId();
        } else {
            // UPDATE
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

        // FUENTES
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

        // FACTURAS
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
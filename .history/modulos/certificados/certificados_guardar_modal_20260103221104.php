<?php
// modulos/certificados/certificados_guardar_modal.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Recibir datos
        $obra_id = $_POST['obra_id'];
        $periodo = $_POST['periodo'];
        $tipo = $_POST['tipo'];
        $cert_id = (int)$_POST['cert_id']; // 0 si es nuevo

        // Helpers de números
        $f = function($v){ return (float)str_replace(',','.', str_replace('.','',$v)); };
        
        $monto_bruto = $f($_POST['monto_bruto']);
        $avance_fisico = isset($_POST['avance_fisico']) ? (float)$_POST['avance_fisico'] : 0;
        $fri = isset($_POST['fri']) ? (float)$_POST['fri'] : 1.0000;

        // Deducciones
        $fondo_reparo_monto = $f($_POST['fondo_reparo_monto']);
        $fondo_reparo_sust = isset($_POST['fondo_reparo_sustituido']) ? 1 : 0;
        $fondo_reparo_pct = $fondo_reparo_sust ? 0 : 5; // Si está sustituido, el pct efectivo es 0

        $anticipo_pct_ap = (float)$_POST['anticipo_pct_aplicado'];
        $anticipo_desc = $f($_POST['anticipo_descuento']);
        $multas = $f($_POST['multas_monto']);
        
        $monto_neto = $f($_POST['monto_neto']); // O recalcular aquí: $monto_bruto - $fondo_reparo_monto - $anticipo_desc - $multas;

        // Mapeo de campos según tipo
        $monto_basico = 0; $monto_redet = 0;
        if($tipo == 'ORDINARIO' || $tipo == 'ANTICIPO') {
            $monto_basico = $monto_bruto;
        } elseif ($tipo == 'REDETERMINACION') {
            $monto_redet = $monto_bruto;
        }

        // Si es nuevo, buscar prox numero
        if ($cert_id == 0) {
            $stmtNro = $pdo->prepare("SELECT COALESCE(MAX(nro_certificado), 0) + 1 FROM certificados WHERE obra_id = ?");
            $stmtNro->execute([$obra_id]);
            $nro = $stmtNro->fetchColumn();
            
            // Buscar empresa
            $stmtEmp = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
            $stmtEmp->execute([$obra_id]);
            $empresa_id = $stmtEmp->fetchColumn();

            $sql = "INSERT INTO certificados 
                (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion,
                 monto_basico, monto_redeterminado, monto_bruto, fri,
                 fondo_reparo_pct, fondo_reparo_sustituido, fondo_reparo_monto, 
                 anticipo_pct_aplicado, anticipo_descuento, multas_monto,
                 monto_neto_pagar, avance_fisico_mensual, estado)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'BORRADOR')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $obra_id, $empresa_id, $nro, $tipo, $periodo,
                $monto_basico, $monto_redet, $monto_bruto, $fri,
                $fondo_reparo_pct, $fondo_reparo_sust, $fondo_reparo_monto,
                $anticipo_pct_ap, $anticipo_desc, $multas,
                $monto_neto, $avance_fisico
            ]);
        } else {
            // TODO: Lógica de UPDATE si quisieras editar desde el modal (por ahora el botón editar lleva al form completo)
        }

        // Volver a la curva
        // Buscamos ID de versión para redirect
        $stmtVer = $pdo->prepare("SELECT id FROM curva_version WHERE obra_id = ? AND es_vigente=1");
        $stmtVer->execute([$obra_id]);
        $verId = $stmtVer->fetchColumn();

        header("Location: ../curva/curva_ver.php?version_id=$verId&msg=ok");
        exit;

    } catch (Exception $e) {
        die("Error al guardar: " . $e->getMessage());
    }
}
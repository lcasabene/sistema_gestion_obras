<?php
// modulos/certificados/certificados_guardar_modal.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $obra_id = $_POST['obra_id'];
        $periodo = $_POST['periodo'];
        $tipo = $_POST['tipo'];
        
        // 1. Obtener Empresa y último número si es automático
        $stmtInfo = $pdo->prepare("SELECT empresa_id, COALESCE(MAX(nro_certificado), 0) + 1 as prox_nro 
                                   FROM certificados WHERE obra_id = ?");
        // Nota: para el MAX nro lo ideal es buscar el ultimo DE LA OBRA, no importa el id
        // Corrección: necesitamos empresa_id de la obra si no hay certificados previos
        $stmtEmp = $pdo->prepare("SELECT empresa_id FROM obras WHERE id = ?");
        $stmtEmp->execute([$obra_id]);
        $empresa_id = $stmtEmp->fetchColumn();

        // Obtener próximo numero
        $stmtNro = $pdo->prepare("SELECT COALESCE(MAX(nro_certificado), 0) + 1 FROM certificados WHERE obra_id = ?");
        $stmtNro->execute([$obra_id]);
        $nro = $stmtNro->fetchColumn();

        // Valores por defecto
        $monto_basico = 0;
        $monto_redet = 0;
        $monto_bruto = 0;
        $avance_fisico = 0;
        
        // Procesar según tipo
        if ($tipo == 'ORDINARIO') {
            $avance_fisico = (float)$_POST['avance_fisico'];
            // Recalculamos monto básico desde backend por seguridad
            $stmtMonto = $pdo->prepare("SELECT monto_actualizado FROM obras WHERE id = ?");
            $stmtMonto->execute([$obra_id]);
            $monto_total_obra = $stmtMonto->fetchColumn();
            
            $monto_basico = $monto_total_obra * ($avance_fisico / 100);
            $monto_bruto = $monto_basico;
        } 
        elseif ($tipo == 'REDETERMINACION') {
            $monto_redet = (float)$_POST['monto_redet'];
            $monto_bruto = $monto_redet;
            // Opcional: Guardar concepto en certificados_items si fuera necesario
        }
        elseif ($tipo == 'ANTICIPO') {
            $monto_basico = (float)$_POST['monto_anticipo']; // Usamos columna basico para el monto del anticipo
            $monto_bruto = $monto_basico;
        }

        // Calcular Deducciones Automáticas (Básico)
        $fondo_reparo = 0;
        $anticipo_desc = 0;

        if ($tipo == 'ORDINARIO') {
            // Fondo Reparo
            $pct_fr = (float)($_POST['fondo_reparo_pct'] ?? 0);
            $fondo_reparo = $monto_bruto * ($pct_fr / 100);

            // Devolución Anticipo (Proporcional al avance o según lógica)
            // Lógica simple: Descontar el % de anticipo de la obra sobre el monto básico
            $pct_ant_obra = (float)($_POST['anticipo_pct_obra'] ?? 0);
            $anticipo_desc = $monto_bruto * ($pct_ant_obra / 100);
        }

        $monto_neto = $monto_bruto - $fondo_reparo - $anticipo_desc;

        // INSERT
        $sql = "INSERT INTO certificados 
                (obra_id, empresa_id, nro_certificado, tipo, periodo, fecha_medicion,
                 monto_basico, monto_redeterminado, monto_bruto, 
                 fondo_reparo_pct, fondo_reparo_monto, anticipo_descuento, 
                 monto_neto_pagar, avance_fisico_mensual, estado)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, 'BORRADOR')";
        
        $stmtIns = $pdo->prepare($sql);
        $stmtIns->execute([
            $obra_id, $empresa_id, $nro, $tipo, $periodo,
            $monto_basico, $monto_redet, $monto_bruto,
            ($_POST['fondo_reparo_pct']??0), $fondo_reparo, $anticipo_desc,
            $monto_neto, $avance_fisico
        ]);

        // Volver a la curva
        // Obtenemos el ID de la version para volver
        $stmtVer = $pdo->prepare("SELECT id FROM curva_version WHERE obra_id = ? AND es_vigente=1");
        $stmtVer->execute([$obra_id]);
        $verId = $stmtVer->fetchColumn();

        header("Location: ../curva/curva_ver.php?version_id=$verId&msg=ok");
        exit;

    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }
}
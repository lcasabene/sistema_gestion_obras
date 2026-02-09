<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // 1. Datos Principales
    $stmt = $pdo->prepare("SELECT * FROM certificados WHERE id = ?");
    $stmt->execute([$id]);
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cert) {
        // 2. Fuentes de Financiamiento
        $stmtF = $pdo->prepare("
            SELECT cf.*, ff.nombre 
            FROM certificados_financiamiento cf 
            JOIN fuentes_financiamiento ff ON cf.fuente_id = ff.id 
            WHERE cf.certificado_id = ?
        ");
        $stmtF->execute([$id]);
        $cert['fuentes'] = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        // 3. Facturas Vinculadas (NUEVO)
        $stmtFac = $pdo->prepare("
            SELECT cf.comprobante_arca_id, ca.numero, ca.importe_total, ca.tipo_comprobante
            FROM certificados_facturas cf
            JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id
            WHERE cf.certificado_id = ?
        ");
        $stmtFac->execute([$id]);
        $cert['facturas'] = $stmtFac->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($cert);
        exit;
    }
}

http_response_code(404);
echo json_encode(['error' => 'No encontrado']);
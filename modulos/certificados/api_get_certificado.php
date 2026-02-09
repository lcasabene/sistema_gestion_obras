<?php
// modulos/certificados/api_get_certificado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Desactivar visualización de errores HTML para no romper el JSON
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id <= 0) {
        throw new Exception("ID inválido");
    }

    // 1. Datos del Certificado
    $stmt = $pdo->prepare("SELECT * FROM certificados WHERE id = ?");
    $stmt->execute([$id]);
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cert) {
        throw new Exception("Certificado no encontrado");
    }

    // 2. Fuentes de Financiamiento
    $stmtF = $pdo->prepare("
        SELECT cf.*, ff.nombre 
        FROM certificados_financiamiento cf 
        JOIN fuentes_financiamiento ff ON cf.fuente_id = ff.id 
        WHERE cf.certificado_id = ?
    ");
    $stmtF->execute([$id]);
    $cert['fuentes'] = $stmtF->fetchAll(PDO::FETCH_ASSOC);

    // 3. Facturas
    $stmtFac = $pdo->prepare("
        SELECT cf.comprobante_arca_id, ca.numero, ca.importe_total
        FROM certificados_facturas cf
        JOIN comprobantes_arca ca ON cf.comprobante_arca_id = ca.id
        WHERE cf.certificado_id = ?
    ");
    $stmtFac->execute([$id]);
    $cert['facturas'] = $stmtFac->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($cert);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
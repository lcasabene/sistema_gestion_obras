<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$empresa_id = $_GET['empresa_id'] ?? 0;

if (!$empresa_id) {
    echo json_encode([]);
    exit;
}

try {
    // Buscamos facturas de esa empresa que NO estén ya usadas en la tabla intermedia
    // Asumimos que comprobantes_arca tiene un campo 'empresa_id' o vinculamos por CUIT
    // Ajusta 'cuit_emisor' o 'empresa_id' según tu tabla real de comprobantes_arca
    
    // OPCION A: Si comprobantes_arca tiene empresa_id
    /* $sql = "SELECT id, fecha_emision, numero, importe_total, punto_venta 
            FROM comprobantes_arca 
            WHERE empresa_id = ? 
            AND id NOT IN (SELECT comprobante_arca_id FROM certificados_facturas)
            ORDER BY fecha_emision DESC";
    */

    // OPCION B: Si vinculamos por CUIT (Más común en ARCA)
    $sql = "SELECT c.id, c.fecha, c.numero, c.importe_total, c.punto_venta 
            FROM comprobantes_arca c
            JOIN empresas e ON e.cuit = c.cuit_emisor
            WHERE e.id = ? 
            AND c.id NOT IN (SELECT comprobante_arca_id FROM certificados_facturas)
            ORDER BY c.fecha DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empresa_id]);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formateamos para el JSON
    $resultado = array_map(function($f) {
        return [
            'id' => $f['id'],
            'texto' => date('d/m/Y', strtotime($f['fecha'])) . " - Fac: " . str_pad($f['punto_venta'], 4, '0', STR_PAD_LEFT) . "-" . str_pad($f['numero'], 8, '0', STR_PAD_LEFT),
            'monto' => $f['importe_total'],
            'monto_fmt' => number_format($f['importe_total'], 2, ',', '.')
        ];
    }, $facturas);

    echo json_encode($resultado);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
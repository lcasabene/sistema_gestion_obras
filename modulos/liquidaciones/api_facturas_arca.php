<?php
// API AJAX: Devuelve comprobantes ARCA vinculados a una empresa (por CUIT)
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$empresa_id = (int)($_GET['empresa_id'] ?? 0);
if (!$empresa_id) { echo json_encode([]); exit; }

try {
    // Traer comprobantes ARCA del proveedor (por CUIT) + marcar si ya están en una liquidación
    $sql = "SELECT c.id, c.fecha, c.tipo_comprobante, c.punto_venta, c.numero, 
                   c.importe_total, c.importe_iva, c.importe_neto, c.cae,
                   COALESCE(c.estado_uso, 'DISPONIBLE') AS estado_uso,
                   (SELECT COALESCE(SUM(l.importe_pago), 0) FROM liquidaciones l 
                    WHERE l.comprobante_arca_id = c.id AND l.estado NOT IN ('ANULADO')) AS total_pagado,
                   (SELECT GROUP_CONCAT(l.id) FROM liquidaciones l 
                    WHERE l.comprobante_arca_id = c.id AND l.estado NOT IN ('ANULADO')) AS liquidacion_ids
            FROM comprobantes_arca c
            JOIN empresas e ON e.cuit = c.cuit_emisor
            WHERE e.id = ?
            ORDER BY c.fecha DESC
            LIMIT 300";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empresa_id]);
    $rows = $stmt->fetchAll();

    $resultado = array_map(function($r) {
        $totalPagado = (float)$r['total_pagado'];
        $totalFactura = (float)$r['importe_total'];
        $saldo = $totalFactura - $totalPagado;
        $pagadoTotal = ($totalPagado >= $totalFactura && $totalPagado > 0);
        return [
            'id'             => (int)$r['id'],
            'fecha'          => date('d/m/Y', strtotime($r['fecha'])),
            'fecha_raw'      => $r['fecha'],
            'tipo'           => $r['tipo_comprobante'],
            'punto_venta'    => str_pad($r['punto_venta'], 5, '0', STR_PAD_LEFT),
            'numero'         => str_pad($r['numero'], 8, '0', STR_PAD_LEFT),
            'numero_completo'=> str_pad($r['punto_venta'], 5, '0', STR_PAD_LEFT) . '-' . str_pad($r['numero'], 8, '0', STR_PAD_LEFT),
            'total'          => $totalFactura,
            'total_fmt'      => number_format($totalFactura, 2, ',', '.'),
            'iva'            => (float)$r['importe_iva'],
            'iva_fmt'        => number_format($r['importe_iva'], 2, ',', '.'),
            'neto'           => (float)$r['importe_neto'],
            'neto_fmt'       => number_format($r['importe_neto'], 2, ',', '.'),
            'cae'            => $r['cae'] ?? '',
            'estado_uso'     => $r['estado_uso'],
            'total_pagado'   => $totalPagado,
            'total_pagado_fmt' => number_format($totalPagado, 2, ',', '.'),
            'saldo'          => round($saldo, 2),
            'saldo_fmt'      => number_format($saldo, 2, ',', '.'),
            'pagado_total'   => $pagadoTotal,
            'usado_en_liq'   => $totalPagado > 0,
            'liquidacion_ids'=> $r['liquidacion_ids'] ? explode(',', $r['liquidacion_ids']) : [],
        ];
    }, $rows);

    echo json_encode($resultado);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

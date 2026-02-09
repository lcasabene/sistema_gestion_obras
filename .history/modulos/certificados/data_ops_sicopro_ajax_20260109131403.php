<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$q = $_GET['q'] ?? '';

// Validar longitud mínima para no saturar
if (strlen($q) < 3) {
    echo json_encode([]);
    exit;
}

try {
    $term = "%$q%";

    // SOLUCIÓN AL ERROR HY093:
    // Usamos parámetros únicos (:q1, :q2, :q3) para cada campo de búsqueda
    $sql = "SELECT movejer, movnupa, movtrju, movtrnu, movprov, movimpo, movfeop 
            FROM sicopro_principal 
            WHERE (movnupa LIKE :q1 OR movtrnu LIKE :q2 OR movprov LIKE :q3)
            AND movimpo > 0
            ORDER BY movejer DESC, movfeop DESC
            LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    
    // Pasamos el mismo término de búsqueda a las 3 variables
    $stmt->execute([
        ':q1' => $term,
        ':q2' => $term,
        ':q3' => $term
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = array_map(function($r) {
        // Formatear importe
        $importeFmt = number_format((float)$r['movimpo'], 2, ',', '.');
        $fecha = $r['movfeop'] ? date('d/m/Y', strtotime($r['movfeop'])) : '-';
        
        // Crear etiqueta descriptiva para el listado
        // Ej: "2025 | OP: 1866 | $ 1.500,00 | PROVEEDOR..."
        $label = "{$r['movejer']} | OP: {$r['movnupa']} | $ $importeFmt | " . substr($r['movprov'], 0, 30);

        return [
            'ejercicio' => $r['movejer'],
            'numero_op' => $r['movnupa'], // Usamos movnupa como N° OP principal
            'importe'   => $r['movimpo'],
            'importe_fmt' => $importeFmt,
            'detalle'   => $label,
            'proveedor' => $r['movprov']
        ];
    }, $rows);

    echo json_encode($resultado);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
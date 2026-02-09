<?php
// modulos/certificados/data_ops_sicopro_ajax.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$q = $_GET['q'] ?? '';
if (strlen($q) < 3) { echo json_encode([]); exit; }

try {
    $term = "%$q%";

    // AGREGAMOS 'movfufi' A LA CONSULTA
    $sql = "SELECT movejer, movnupa, movtrju, movtrnu, movprov, movimpo, movfeop, movtrti, movfufi 
            FROM sicopro_principal 
            WHERE (movnupa LIKE :q1 OR movtrnu LIKE :q2 OR movprov LIKE :q3)
            AND movtrti IN ('LI', 'LF') 
            AND movimpo > 0
            ORDER BY movejer DESC, movfeop DESC
            LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':q1' => $term, ':q2' => $term, ':q3' => $term]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = array_map(function($r) {
        $importeFmt = number_format((float)$r['movimpo'], 2, ',', '.');
        
        // En el detalle mostramos la Fuente
        $label = "{$r['movejer']} | FF: {$r['movfufi']} | OP: {$r['movnupa']} | $ $importeFmt | " . substr($r['movprov'], 0, 20);

        return [
            'ejercicio' => $r['movejer'],
            'numero_op' => $r['movnupa'], 
            'importe'   => $r['movimpo'],
            'importe_fmt' => $importeFmt,
            'detalle'   => $label,
            'proveedor' => $r['movprov'],
            'tipo'      => $r['movtrti'],
            'fuente'    => $r['movfufi'] // Dato clave
        ];
    }, $rows);

    echo json_encode($resultado);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
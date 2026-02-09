<?php
// Evitar que errores de PHP se impriman directo en el JSON
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Iniciar buffer de salida para capturar cualquier "eco" indeseado
ob_start();

require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Limpiar cualquier salida previa (espacios en blanco, warnings de includes, etc.)
ob_end_clean(); 

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Definición de columnas (Mapeo índice -> nombre BD)
    $columns = [
        0 => 'movejer',
        1 => 'movtrju',
        2 => 'movexpe',
        3 => 'movprov',
        4 => 'movrefe',
        5 => 'movimpo',
        6 => 'movfeop',
        7 => 'movcomp',
        8 => 'movfere'
    ];

    // 2. Total registros
    $sql_count = "SELECT COUNT(*) FROM sicopro_principal";
    $stmt = $pdo->query($sql_count);
    $totalRecords = $stmt->fetchColumn();

    // 3. Preparar consulta base
    $sql = "SELECT * FROM sicopro_principal";
    $where = " WHERE 1=1 ";
    $params = [];

    // 4. BÚSQUEDA
    if (!empty($_POST['search']['value'])) {
        $search = $_POST['search']['value'];
        // Usamos paréntesis para aislar los OR
        $where .= " AND (
            movprov LIKE :search 
            OR movrefe LIKE :search 
            OR movexpe LIKE :search 
            OR movcomp LIKE :search
        )";
        $params[':search'] = "%$search%";
    }

    // 5. ORDEN
    $sqlOrder = " ORDER BY movejer DESC, movtrju DESC ";
    if (isset($_POST['order'])) {
        $colIndex = $_POST['order'][0]['column'];
        $dir = $_POST['order'][0]['dir'] === 'asc' ? 'ASC' : 'DESC';
        if (isset($columns[$colIndex])) {
            $sqlOrder = " ORDER BY " . $columns[$colIndex] . " " . $dir;
        }
    }

    // 6. Total Filtrado
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sicopro_principal " . $where);
    $stmt->execute($params);
    $totalRecordwithFilter = $stmt->fetchColumn();

    // 7. Paginación
    $limit = "";
    if (isset($_POST['start']) && $_POST['length'] != -1) {
        $start = (int)$_POST['start'];
        $length = (int)$_POST['length'];
        $limit = " LIMIT $start, $length";
    }

    // 8. Ejecutar
    $stmt = $pdo->prepare($sql . $where . $sqlOrder . $limit);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 9. Formateo
    $data_formatted = [];
    foreach ($data as $row) {
        // Función auxiliar para asegurar UTF-8
        $clean = function($str) {
            return mb_convert_encoding($str ?? '', 'UTF-8', 'UTF-8');
        };

        $tramite = $row['movtrju'] . '-' . $row['movtrnu'];
        $importe = '$ ' . number_format($row['movimpo'], 2, ',', '.');
        
        // Detalle con tooltip
        $detalle_texto = $clean($row['movrefe']);
        $detalle_corto = mb_substr($detalle_texto, 0, 50) . '...';
        $detalle_html = '<span title="'.htmlspecialchars($detalle_texto).'">'.htmlspecialchars($detalle_corto).'</span>';

        $fechaOp = $row['movfeop'] ? date('d/m/Y', strtotime($row['movfeop'])) : '-';
        $fechaReg = $row['movfere'] ? date('d/m/Y', strtotime($row['movfere'])) : '-';

        $data_formatted[] = [
            $row['movejer'],
            $tramite,
            $clean($row['movexpe']),
            htmlspecialchars($clean($row['movprov'])),
            $detalle_html,
            '<div class="text-end fw-bold">'.$importe.'</div>',
            $fechaOp,
            $clean($row['movcomp']),
            $fechaReg
        ];
    }

    echo json_encode([
        "draw" => intval($_POST['draw']),
        "iTotalRecords" => $totalRecords,
        "iTotalDisplayRecords" => $totalRecordwithFilter,
        "aaData" => $data_formatted
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Si hay error, devolverlo en formato JSON para que DataTables lo muestre (o consola)
    echo json_encode([
        "error" => $e->getMessage()
    ]);
}
?>
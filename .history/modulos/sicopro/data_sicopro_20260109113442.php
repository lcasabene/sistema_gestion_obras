<?php
// Configuración de errores (ocultos en la salida para no romper JSON)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Limpiar buffer para asegurar JSON limpio
ob_start();

require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Limpiar cualquier eco previo
ob_end_clean(); 

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Columnas para ordenamiento
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

    // 2. Total registros (Sin filtros)
    $sql_count = "SELECT COUNT(*) FROM sicopro_principal";
    $stmt = $pdo->query($sql_count);
    $totalRecords = $stmt->fetchColumn();

    // 3. Consulta Base
    $sql = "SELECT * FROM sicopro_principal";
    $where = " WHERE 1=1 ";
    $params = [];

    // 4. BÚSQUEDA AVANZADA
    if (!empty($_POST['search']['value'])) {
        $searchVal = $_POST['search']['value'];
        $term = "%$searchVal%";
        
        // AHORA BUSCAMOS EN 8 LUGARES (Agregado movnupa)
        $where .= " AND (
            movprov LIKE :s1 
            OR movrefe LIKE :s2 
            OR movexpe LIKE :s3 
            OR movcomp LIKE :s4
            OR movtrnu LIKE :s5 
            OR movtrju LIKE :s6 
            OR movejer LIKE :s7
            OR movnupa LIKE :s8  -- <-- NUEVO CAMPO AGREGADO
        )";
        
        // Asignamos el término a cada variable para evitar error de parámetros
        $params[':s1'] = $term;
        $params[':s2'] = $term;
        $params[':s3'] = $term;
        $params[':s4'] = $term;
        $params[':s5'] = $term;
        $params[':s6'] = $term;
        $params[':s7'] = $term;
        $params[':s8'] = $term; // Parametro para movnupa
    }

    // 5. ORDENAMIENTO
    $sqlOrder = " ORDER BY movejer DESC, movtrju DESC ";
    if (isset($_POST['order'])) {
        $colIndex = $_POST['order'][0]['column'];
        $dir = $_POST['order'][0]['dir'] === 'asc' ? 'ASC' : 'DESC';
        if (isset($columns[$colIndex])) {
            $sqlOrder = " ORDER BY " . $columns[$colIndex] . " " . $dir;
        }
    }

    // 6. Total Filtrado (Count con el WHERE aplicado)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sicopro_principal " . $where);
    $stmt->execute($params);
    $totalRecordwithFilter = $stmt->fetchColumn();

    // 7. Paginación (Limit)
    $limit = "";
    if (isset($_POST['start']) && $_POST['length'] != -1) {
        $start = (int)$_POST['start'];
        $length = (int)$_POST['length'];
        $limit = " LIMIT $start, $length";
    }

    // 8. Ejecutar Consulta de Datos
    $stmt = $pdo->prepare($sql . $where . $sqlOrder . $limit);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 9. Formateo de Datos para DataTables
    $data_formatted = [];
    foreach ($data as $row) {
        $clean = function($str) {
            return mb_convert_encoding($str ?? '', 'UTF-8', 'UTF-8');
        };

        $tramite = $row['movtrju'] . '-' . $row['movtrnu'];
        $importe = '$ ' . number_format($row['movimpo'], 2, ',', '.');
        
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
    echo json_encode(["error" => "Error: " . $e->getMessage()]);
}
?>
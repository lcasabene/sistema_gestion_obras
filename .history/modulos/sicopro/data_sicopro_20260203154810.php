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
        1 => 'movtrti',
        2 => 'movexpe',
        3 => 'movprov',
        4 => 'movrefe',
        5 => 'movimpo',
        6 => 'movfeop',
        7 => 'movcomp',
        8 => 'movfere',
        9 => 'movalex'
    ];

    // 2. Definimos el FILTRO BASE (Aquí está la depuración visual)
    // Esto oculta los registros sin borrarlos de la base de datos
    $filtro_depuracion = " WHERE movtrti NOT IN ('AA', 'RE', 'AC', 'CO', 'AP') ";

    // 3. Total registros (Aplicando la depuración para que el contador sea real)
    $sql_count = "SELECT COUNT(*) FROM sicopro_principal" . $filtro_depuracion;
    $stmt = $pdo->query($sql_count);
    $totalRecords = $stmt->fetchColumn();

    // 4. Consulta Base con el filtro aplicado
    $sql = "SELECT * FROM sicopro_principal";
    $where = $filtro_depuracion; 
    $params = [];

    // 5. BÚSQUEDA AVANZADA (Usuario escribe en el buscador)
    if (!empty($_POST['search']['value'])) {
        $searchVal = $_POST['search']['value'];
        $term = "%$searchVal%";
        
        // Concatenamos con AND a nuestro filtro de depuración
        $where .= " AND (
            movprov LIKE :s1 
            OR movrefe LIKE :s2 
            OR movexpe LIKE :s3 
            OR movcomp LIKE :s4
            OR movtrnu LIKE :s5 
            OR movimpo LIKE :s6 
            OR movejer LIKE :s7
            OR movnupa LIKE :s8
        )";
        
        $params[':s1'] = $term;
        $params[':s2'] = $term;
        $params[':s3'] = $term;
        $params[':s4'] = $term;
        $params[':s5'] = $term;
        $params[':s6'] = $term;
        $params[':s7'] = $term;
        $params[':s8'] = $term;
    }

    // 6. ORDENAMIENTO
    $sqlOrder = " ORDER BY movejer DESC, movtrju DESC ";
    if (isset($_POST['order'])) {
        $colIndex = $_POST['order'][0]['column'];
        $dir = $_POST['order'][0]['dir'] === 'asc' ? 'ASC' : 'DESC';
        if (isset($columns[$colIndex])) {
            $sqlOrder = " ORDER BY " . $columns[$colIndex] . " " . $dir;
        }
    }

    // 7. Total Filtrado (Count con el WHERE completo)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sicopro_principal " . $where);
    $stmt->execute($params);
    $totalRecordwithFilter = $stmt->fetchColumn();

    // 8. Paginación (Limit)
    $limit = "";
    if (isset($_POST['start']) && $_POST['length'] != -1) {
        $start = (int)$_POST['start'];
        $length = (int)$_POST['length'];
        $limit = " LIMIT $start, $length";
    }

    // 9. Ejecutar Consulta de Datos Final
    $stmt = $pdo->prepare($sql . $where . $sqlOrder . $limit);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 10. Formateo de Datos
    $data_formatted = [];
    foreach ($data as $row) {
        $clean = function($str) {
            return mb_convert_encoding($str ?? '', 'UTF-8', 'UTF-8');
        };

        $tramite = $row['movtrti'] . '-' . $row['movtrnu']. '-'.$row['movtrse'].'-'.$row['movnupa'];
        $importe = '$ ' . number_format($row['movimpo'], 2, ',', '.');
        
        $detalle_texto = $clean($row['movrefe']);
        $detalle_corto = mb_substr($detalle_texto, 0, 50) . '...';
        $detalle_html = '<span title="'.htmlspecialchars($detalle_texto).'">'.htmlspecialchars($detalle_corto).'</span>';

        $fechaOp = $row['movfeop'] ? date('d/m/Y', strtotime($row['movfeop'])) : '-';
        $fechaReg = $row['movfere'] ? date('d/m/Y', strtotime($row['movfere'])) : '-';
        $alcance = $row['movalex'];

        $data_formatted[] = [
            $row['movejer'],
            $tramite,
            $clean($row['movexpe'].'-'.$alcance),
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
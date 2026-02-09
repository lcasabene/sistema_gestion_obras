<?php
// Configuración de errores para depuración (pero ocultos en la salida visual)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Limpiar buffer de salida para evitar que espacios en blanco rompan el JSON
ob_start();

require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Limpiamos cualquier "eco" anterior (espacios, warnings)
ob_end_clean(); 

// Cabecera JSON obligatoria
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

    // 2. Total registros (Sin filtros)
    $sql_count = "SELECT COUNT(*) FROM sicopro_principal";
    $stmt = $pdo->query($sql_count);
    $totalRecords = $stmt->fetchColumn();

    // 3. Preparar consulta base
    $sql = "SELECT * FROM sicopro_principal";
    $where = " WHERE 1=1 ";
    $params = [];

    // 4. BÚSQUEDA (CORREGIDA: Usamos parámetros únicos para cada campo)
    if (!empty($_POST['search']['value'])) {
        $searchVal = $_POST['search']['value'];
        $term = "%$searchVal%";
        
        // Usamos :s1, :s2, :s3, :s4 para evitar el error HY093
        $where .= " AND (
            movprov LIKE :s1 
            OR movrefe LIKE :s2 
            OR movexpe LIKE :s3 
            OR movcomp LIKE :s4
        )";
        
        // Asignamos el mismo valor a las 4 variables
        $params[':s1'] = $term;
        $params[':s2'] = $term;
        $params[':s3'] = $term;
        $params[':s4'] = $term;
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

    // 9. Formateo de Datos
    $data_formatted = [];
    foreach ($data as $row) {
        // Función para limpiar caracteres (UTF-8)
        $clean = function($str) {
            return mb_convert_encoding($str ?? '', 'UTF-8', 'UTF-8');
        };

        $tramite = $row['movtrju'] . '-' . $row['movtrnu'];
        $importe = '$ ' . number_format($row['movimpo'], 2, ',', '.');
        
        // Tooltip para detalle largo
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

    // 10. Respuesta JSON
    echo json_encode([
        "draw" => intval($_POST['draw']),
        "iTotalRecords" => $totalRecords,
        "iTotalDisplayRecords" => $totalRecordwithFilter,
        "aaData" => $data_formatted
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Si hay error, devolver JSON de error
    echo json_encode([
        "error" => "Error servidor: " . $e->getMessage()
    ]);
}
?>
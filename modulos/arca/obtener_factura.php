<?php
// obtener_facturas.php
ob_start(); // <--- 1. AGREGA ESTO EN LA PRIMERA LÍNEA (inicia la grabación)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/middleware.php';

// Columnas ordenables (con prefijo de tabla para evitar ambigüedad con el JOIN)
$columns = [
    0 => 'c.id',
    1 => 'c.fecha',
    2 => 'c.nombre_emisor',
    3 => 'c.numero',
    4 => 'c.importe_total',
    5 => 'c.estado_uso'
];

// 1. Obtener total general de registros
$stmt = $pdo->query("SELECT COUNT(*) FROM comprobantes_arca");
$totalRecords = $stmt->fetchColumn();

// 2. Preparar la consulta base
$sql = "SELECT c.id, c.fecha, c.nombre_emisor, c.tipo_comprobante,
               COALESCE(t.descripcion, c.tipo_comprobante) AS tipo_descripcion,
               c.punto_venta, c.numero, c.importe_total, c.estado_uso
        FROM comprobantes_arca c
        LEFT JOIN tipos_comprobante_arca t ON t.codigo = LPAD(c.tipo_comprobante, 3, '0')";
$whereSql = "";
$params = [];

// 3. Lógica de Búsqueda (CORREGIDO)
if (!empty($_POST['search']['value'])) {
    $searchValue = $_POST['search']['value'];
    // Usamos etiquetas diferentes (:search1, :search2, :search3) para evitar conflictos
    $whereSql = " WHERE (c.nombre_emisor LIKE :search1 
                   OR c.numero LIKE :search2 
                   OR c.importe_total LIKE :search3)";
    
    $params[':search1'] = "%$searchValue%";
    $params[':search2'] = "%$searchValue%";
    $params[':search3'] = "%$searchValue%";
}

// 4. Ordenamiento (Clic en cabeceras)
$orderSql = "";
if (isset($_POST['order'])) {
    $columnIndex = $_POST['order'][0]['column'];
    $columnName = $columns[$columnIndex];
    $direction = $_POST['order'][0]['dir'];
    // Evitar inyección SQL en el ordenamiento
    if(in_array($columnName, $columns)) {
        $orderSql = " ORDER BY $columnName $direction";
    }
} else {
    $orderSql = " ORDER BY c.id DESC"; // Orden por defecto
}

// 5. Paginación (Limit y Offset)
$limitSql = "";
if (isset($_POST['length']) && $_POST['length'] != -1) {
    $limit = intval($_POST['length']);
    $offset = intval($_POST['start']);
    $limitSql = " LIMIT $limit OFFSET $offset";
}

// 6. Obtener total filtrado - usa el mismo FROM+JOIN para que los aliases c. funcionen
$sqlBase = "FROM comprobantes_arca c LEFT JOIN tipos_comprobante_arca t ON t.codigo = LPAD(c.tipo_comprobante, 3, '0')";
$stmt = $pdo->prepare("SELECT COUNT(*) " . $sqlBase . $whereSql);
$stmt->execute($params);
$totalRecordwithFilter = $stmt->fetchColumn();

// 7. Obtener los datos finales
$stmt = $pdo->prepare($sql . $whereSql . $orderSql . $limitSql);
$stmt->execute($params);
$data = $stmt->fetchAll();

// 8. Formatear datos para la vista (opcional, pero recomendado)
$dataResponse = [];
foreach ($data as $row) {
    $sub_array = [];
    $sub_array[] = $row['id'];
    $sub_array[] = date("d/m/Y", strtotime($row['fecha'])); // Formato fecha
    $sub_array[] = $row['nombre_emisor'];
    
    // Armamos el número completo: PV-Numero (Ej: 00001-12345678)
    $numeroCompleto = str_pad($row['punto_venta'], 5, "0", STR_PAD_LEFT) . "-" . $row['numero'];
    $tipoDesc = $row['tipo_descripcion'] ?? $row['tipo_comprobante'];
    $sub_array[] = '<small class="text-muted">'.htmlspecialchars($tipoDesc).'</small><br><span class="font-monospace">' . $numeroCompleto.'</span>';
    
    $sub_array[] = "$ " . number_format($row['importe_total'], 2, ',', '.');
    
    // Etiqueta de estado
    $badge = ($row['estado_uso'] == 'DISPONIBLE') ? 'bg-success' : 'bg-secondary';
    $sub_array[] = "<span class='badge $badge'>{$row['estado_uso']}</span>";
    
    // Botones de acción
    $sub_array[] = '';
    
    $dataResponse[] = $sub_array;
}

// 9. Respuesta JSON
$response = [
    "draw" => intval($_POST['draw']),
    "recordsTotal" => $totalRecords,
    "recordsFiltered" => $totalRecordwithFilter,
    "data" => $dataResponse
];

echo json_encode($response);
// if (json_last_error() !== JSON_ERROR_NONE) {
//     echo json_last_error_msg();
//     exit;
// }
?>
<?php
// obtener_facturas.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/middleware.php';

// Columnas que DataTables va a leer (deben coincidir con el orden visual)
$columns = [
    0 => 'id',
    1 => 'fecha',
    2 => 'nombre_emisor',
    3 => 'numero',
    4 => 'importe_total',
    5 => 'estado_uso'
];

// 1. Obtener total general de registros
$stmt = $pdo->query("SELECT COUNT(*) FROM comprobantes_arca");
$totalRecords = $stmt->fetchColumn();

// 2. Preparar la consulta base
$sql = "SELECT id, fecha, nombre_emisor, tipo_comprobante, punto_venta, numero, importe_total, estado_uso FROM comprobantes_arca";
$whereSql = "";
$params = [];

// 3. Lógica de Búsqueda (CORREGIDO)
if (!empty($_POST['search']['value'])) {
    $searchValue = $_POST['search']['value'];
    // Usamos etiquetas diferentes (:search1, :search2, :search3) para evitar conflictos
    $whereSql = " WHERE (nombre_emisor LIKE :search1 
                   OR numero LIKE :search2 
                   OR importe_total LIKE :search3)";
    
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
    $orderSql = " ORDER BY id DESC"; // Orden por defecto
}

// 5. Paginación (Limit y Offset)
$limitSql = "";
if (isset($_POST['length']) && $_POST['length'] != -1) {
    $limit = intval($_POST['length']);
    $offset = intval($_POST['start']);
    $limitSql = " LIMIT $limit OFFSET $offset";
}

// 6. Obtener total filtrado (para la paginación correcta cuando buscas)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM comprobantes_arca" . $whereSql);
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
    $sub_array[] = '<small class="text-muted">'.$row['tipo_comprobante'].'</small><br>' . $numeroCompleto;
    
    $sub_array[] = "$ " . number_format($row['importe_total'], 2, ',', '.');
    
    // Etiqueta de estado
    $badge = ($row['estado_uso'] == 'DISPONIBLE') ? 'bg-success' : 'bg-secondary';
    $sub_array[] = "<span class='badge $badge'>{$row['estado_uso']}</span>";
    
    // Botones de acción
    $sub_array[] = '<button class="btn btn-sm btn-primary">Ver</button>';
    
    $dataResponse[] = $sub_array;
}

// 9. Respuesta JSON
$response = [
    "draw" => intval($_POST['draw']),
    "recordsTotal" => $totalRecords,
    "recordsFiltered" => $totalRecordwithFilter,
    "data" => $dataResponse
];

// echo json_encode($response);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_last_error_msg();
    exit;
}
?>
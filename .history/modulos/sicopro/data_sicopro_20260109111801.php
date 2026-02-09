<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login(); // Protegemos el archivo
require_once __DIR__ . '/../../config/database.php';

// 1. Definición de columnas que se mostrarán y se podrán buscar
// El índice del array debe coincidir con el orden de las columnas en el HTML (th)
$columns = [
    0 => 'movejer',
    1 => 'movtrju', // Para Trámite
    2 => 'movexpe',
    3 => 'movprov',
    4 => 'movrefe',
    5 => 'movimpo',
    6 => 'movfeop',
    7 => 'movcomp',
    8 => 'movfere'
];

// 2. Obtener total de registros (sin filtros)
$sql_count = "SELECT COUNT(*) FROM sicopro_principal";
$stmt = $pdo->query($sql_count);
$totalRecords = $stmt->fetchColumn();

// 3. Preparar la consulta principal
$sql = "SELECT * FROM sicopro_principal";
$where = " WHERE 1=1 ";
$params = [];

// 4. Lógica de BÚSQUEDA (Search)
if (!empty($_POST['search']['value'])) {
    $search = $_POST['search']['value'];
    $where .= " AND (
        movprov LIKE :search 
        OR movrefe LIKE :search 
        OR movexpe LIKE :search 
        OR movcomp LIKE :search
    )";
    $params[':search'] = "%$search%";
}

// 5. Lógica de ORDENAMIENTO (Order)
$sqlOrder = " ORDER BY movejer DESC, movtrju DESC "; // Default
if (isset($_POST['order'])) {
    $colIndex = $_POST['order'][0]['column'];
    $dir = $_POST['order'][0]['dir'] === 'asc' ? 'ASC' : 'DESC';
    if (isset($columns[$colIndex])) {
        $sqlOrder = " ORDER BY " . $columns[$colIndex] . " " . $dir;
    }
}

// 6. Obtener total filtrado (antes de aplicar el límite de paginación)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sicopro_principal " . $where);
$stmt->execute($params);
$totalRecordwithFilter = $stmt->fetchColumn();

// 7. Lógica de PAGINACIÓN (Limit)
$limit = "";
if (isset($_POST['start']) && $_POST['length'] != -1) {
    $start = (int)$_POST['start'];
    $length = (int)$_POST['length'];
    $limit = " LIMIT $start, $length";
}

// 8. Ejecutar consulta final
$stmt = $pdo->prepare($sql . $where . $sqlOrder . $limit);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 9. Formatear datos para DataTables
$data_formatted = [];
foreach ($data as $row) {
    $tramite = $row['movtrju'] . '-' . $row['movtrnu'];
    $importe = '$ ' . number_format($row['movimpo'], 2, ',', '.');
    $detalle = mb_substr($row['movrefe'], 0, 50) . '...';
    
    // Convertir fechas para visualización si es necesario
    $fechaOp = $row['movfeop'] ? date('d/m/Y', strtotime($row['movfeop'])) : '-';
    $fechaReg = $row['movfere'] ? date('d/m/Y', strtotime($row['movfere'])) : '-';

    $data_formatted[] = [
        $row['movejer'],
        $tramite,
        $row['movexpe'],
        htmlspecialchars($row['movprov']),
        '<span title="'.htmlspecialchars($row['movrefe']).'">'.$detalle.'</span>',
        '<div class="text-end fw-bold">'.$importe.'</div>',
        $fechaOp,
        $row['movcomp'],
        $fechaReg
    ];
}

// 10. Devolver JSON
$response = [
    "draw" => intval($_POST['draw']),
    "iTotalRecords" => $totalRecords,
    "iTotalDisplayRecords" => $totalRecordwithFilter,
    "aaData" => $data_formatted
];

header('Content-Type: application/json');
echo json_encode($response);
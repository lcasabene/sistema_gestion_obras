<?php
// api_get_curva.php
require_once __DIR__ . '/../config/database.php';

$obra_id = isset($_GET['obra_id']) ? (int)$_GET['obra_id'] : 0;
$periodo = $_GET['periodo'] ?? ''; // YYYY-MM

$response = ['planificado' => 0, 'existe' => false];

if ($obra_id > 0 && $periodo) {
    // Buscar la versión vigente de la curva
    $sqlVersion = "SELECT id FROM curva_version WHERE obra_id = ? AND es_vigente = 1 LIMIT 1";
    $stmtV = $pdo->prepare($sqlVersion);
    $stmtV->execute([$obra_id]);
    $version = $stmtV->fetch();

    if ($version) {
        // Buscar el ítem de ese periodo (agregando día 01 para coincidir formato fecha si es necesario)
        // Asumimos que en curva_items guardaste 'periodo' como date (YYYY-MM-01)
        $periodoDate = $periodo . '-01';
        
        $sqlItem = "SELECT porcentaje_fisico FROM curva_items WHERE version_id = ? AND periodo = ?";
        $stmtI = $pdo->prepare($sqlItem);
        $stmtI->execute([$version['id'], $periodoDate]);
        $item = $stmtI->fetch();

        if ($item) {
            $response['planificado'] = (float)$item['porcentaje_fisico'];
            $response['existe'] = true;
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
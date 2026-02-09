<?php
require_once __DIR__ . '/../../config/database.php';
$oid = (int)$_GET['obra_id'];
$sql = "SELECT ofc.fuente_id, ofc.porcentaje, ff.nombre 
        FROM obra_fuentes_config ofc 
        JOIN fuentes_financiamiento ff ON ofc.fuente_id = ff.id 
        WHERE ofc.obra_id = $oid";
echo json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
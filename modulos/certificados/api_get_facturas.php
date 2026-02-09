<?php
require_once __DIR__ . '/../../config/database.php';
$cuit = preg_replace('/[^0-9]/','',$_GET['cuit']);
$sql = "SELECT * FROM comprobantes_arca WHERE cuit_emisor = '$cuit' AND estado_uso = 'DISPONIBLE'";
$facs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if(count($facs)==0) echo "<p class='text-muted'>No hay facturas disponibles para este CUIT.</p>";

foreach($facs as $f) {
    echo "<div class='card mb-2 p-2'>
            <div class='d-flex justify-content-between'>
                <strong>{$f['tipo_comprobante']} {$f['numero']}</strong>
                <span class='text-success fw-bold'>$ ".number_format($f['importe_total'],2,',','.')."</span>
            </div>
            <button type='button' class='btn btn-sm btn-outline-info w-100 mt-1' 
            onclick='seleccionarFactura({$f['id']}, \"{$f['numero']}\", {$f['importe_total']})'>Seleccionar</button>
          </div>";
}
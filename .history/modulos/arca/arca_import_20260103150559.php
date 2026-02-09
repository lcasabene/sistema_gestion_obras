<?php
// modulos/arca/arca_import.php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/middleware.php';

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_arca'])) {
    if ($_FILES['csv_arca']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['csv_arca']['tmp_name'];
        $handle = fopen($tmpName, "r");
        
        $fila = 0;
        $importados = 0;
        
        // Preparar SQL
        $sql = "INSERT INTO comprobantes_arca 
                (fecha, tipo_comprobante, punto_venta, numero, cuit_emisor, nombre_emisor, cuit_receptor, importe_neto, importe_iva, importe_total, cae) 
                VALUES (:fecha, :tipo, :pv, :nro, :cuitE, :nomE, :cuitR, :neto, :iva, :total, :cae)";
        $stmt = $pdo->prepare($sql);

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $fila++;
            // Saltamos cabeceras (tu CSV tiene 1 o 2 filas de cabecera, ajustamos)
            // Según tu archivo, la data real empieza cuando la columna 0 es una fecha válida
            
            // Mapeo según tu CSV:
            // 0:Fecha, 1:Tipo, 2:PV, 3:NroDesde, 5:CAE, 7:CuitE, 8:NomE, 10:CuitR, 25:NetoTotal, 29:IVA, 30:Total
            
            $fechaRaw = $data[0];
            // Validar si es fecha DD/MM/AAAA
            $d = DateTime::createFromFormat('d/m/Y', $fechaRaw);
            if (!$d || $d->format('d/m/Y') !== $fechaRaw) continue; // Si no es fecha, saltar (es cabecera)

            try {
                $stmt->execute([
                    ':fecha' => $d->format('Y-m-d'),
                    ':tipo'  => utf8_encode($data[1]),
                    ':pv'    => (int)$data[2],
                    ':nro'   => $data[3], // Usamos 'Numero Desde' como nro de factura
                    ':cuitE' => preg_replace('/[^0-9]/', '', $data[7]),
                    ':nomE'  => utf8_encode($data[8]),
                    ':cuitR' => preg_replace('/[^0-9]/', '', $data[10]),
                    ':neto'  => (float)$data[25], // Neto Gravado Total
                    ':iva'   => (float)$data[29], // Total IVA
                    ':total' => (float)$data[30], // Imp Total
                    ':cae'   => $data[5]
                ]);
                $importados++;
            } catch (Exception $e) {
                // Si duplicamos (mismo CAE/Numero), ignoramos o logueamos
            }
        }
        fclose($handle);
        $mensaje = "Proceso finalizado. Se importaron $importados comprobantes.";
    } else {
        $mensaje = "Error al subir el archivo.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar ARCA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container my-5">
    <h3 class="mb-4"><i class="bi bi-cloud-upload"></i> Importar "Mis Comprobantes Recibidos" (ARCA)</h3>
    
    <?php if($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Seleccione archivo CSV descargado de AFIP</label>
                    <input type="file" name="csv_arca" class="form-control" accept=".csv" required>
                    <div class="form-text">El archivo debe ser el formato estándar "Mis Comprobantes Recibidos".</div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload"></i> Importar Comprobantes
                </button>
                <a href="../../public/menu.php" class="btn btn-secondary">Volver</a>
            </form>
        </div>
    </div>
    
    <hr>
    
    <h5>Últimos comprobantes importados</h5>
    <table class="table table-sm table-striped">
        <thead><tr><th>Fecha</th><th>Emisor</th><th>Tipo</th><th>Total</th><th>Estado</th></tr></thead>
        <tbody>
            <?php 
            $ultimos = $pdo->query("SELECT * FROM comprobantes_arca ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            foreach($ultimos as $c): ?>
            <tr>
                <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                <td><?= htmlspecialchars($c['nombre_emisor']) ?> <br><small class="text-muted"><?= $c['cuit_emisor'] ?></small></td>
                <td><?= $c['tipo_comprobante'] ?> N° <?= $c['numero'] ?></td>
                <td class="text-end fw-bold">$ <?= number_format($c['importe_total'], 2, ',', '.') ?></td>
                <td><span class="badge bg-<?= $c['estado_uso']=='DISPONIBLE'?'success':'secondary' ?>"><?= $c['estado_uso'] ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
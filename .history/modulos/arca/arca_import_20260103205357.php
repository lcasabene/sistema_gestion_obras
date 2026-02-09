<?php
// modulos/arca/arca_import.php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_login();

$mensaje = '';
$tipo_alerta = '';

// Función para limpiar montos (1.000,00 -> 1000.00)
function limpiarMonto($val) {
    if (!$val) return 0;
    $val = str_replace('.', '', $val);
    $val = str_replace(',', '.', $val);
    return (float)$val;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_arca'])) {
    if ($_FILES['csv_arca']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['csv_arca']['tmp_name'];
        
        // Detectar delimitador
        $linea1 = fgets(fopen($tmpName, 'r'));
        $delimitador = (strpos($linea1, ';') !== false) ? ';' : ',';
        
        $handle = fopen($tmpName, "r");
        
        $fila = 0;
        $importados = 0;
        $duplicados = 0; // Nuevo contador
        $errores = 0;
        
        // 1. SQL para VERIFICAR existencia
        // Verificamos CUIT Emisor, Tipo, Punto Venta, Número e Importe (para estar 100% seguros)
        $sqlCheck = "SELECT COUNT(*) FROM comprobantes_arca 
                     WHERE cuit_emisor = :cuitE 
                     AND tipo_comprobante = :tipo 
                     AND punto_venta = :pv 
                     AND numero = :nro 
                     AND importe_total = :total";
        $stmtCheck = $pdo->prepare($sqlCheck);

        // 2. SQL para INSERTAR
        $sqlInsert = "INSERT INTO comprobantes_arca 
                (fecha, tipo_comprobante, punto_venta, numero, cuit_emisor, nombre_emisor, cuit_receptor, importe_neto, importe_iva, importe_total, cae) 
                VALUES (:fecha, :tipo, :pv, :nro, :cuitE, :nomE, :cuitR, :neto, :iva, :total, :cae)";
        $stmtInsert = $pdo->prepare($sqlInsert);

        while (($data = fgetcsv($handle, 2000, $delimitador)) !== FALSE) {
            $fila++;
            
            // Saltar encabezados
            if (stripos($data[0], 'Fecha') !== false) continue;
            
            // Validar columnas mínimas
            if (count($data) < 10) continue;

            try {
                // --- PREPARACIÓN DE DATOS ---
                
                // Fecha
                $fechaRaw = trim($data[0]);
                if (strpos($fechaRaw, '/') !== false) {
                    $d = DateTime::createFromFormat('d/m/Y', $fechaRaw);
                    $fechaFinal = $d ? $d->format('Y-m-d') : null;
                } else {
                    $fechaFinal = $fechaRaw; 
                }

                if (!$fechaFinal) continue; 

                // Variables limpias para usar en Check e Insert
                $tipo_cbte = utf8_encode($data[1]);
                $pto_venta = (int)$data[2];
                $numero    = $data[3];
                $cuit_emi  = preg_replace('/[^0-9]/', '', $data[7]);
                $nom_emi   = utf8_encode($data[8]);
                $cuit_rec  = preg_replace('/[^0-9]/', '', $data[10]);
                $imp_neto  = limpiarMonto($data[24] ?? 0);
                $imp_iva   = limpiarMonto($data[28] ?? 0);
                $imp_total = limpiarMonto($data[29] ?? 0);
                $cae_val   = $data[5];

                // --- VERIFICACIÓN DE DUPLICADOS ---
                $stmtCheck->execute([
                    ':cuitE' => $cuit_emi,
                    ':tipo'  => $tipo_cbte,
                    ':pv'    => $pto_venta,
                    ':nro'   => $numero,
                    ':total' => $imp_total
                ]);

                if ($stmtCheck->fetchColumn() > 0) {
                    // Ya existe, saltamos esta iteración
                    $duplicados++;
                    continue; 
                }

                // --- INSERCIÓN ---
                $stmtInsert->execute([
                    ':fecha' => $fechaFinal,
                    ':tipo'  => $tipo_cbte,
                    ':pv'    => $pto_venta,
                    ':nro'   => $numero,
                    ':cuitE' => $cuit_emi,
                    ':nomE'  => $nom_emi,
                    ':cuitR' => $cuit_rec,
                    ':neto'  => $imp_neto,
                    ':iva'   => $imp_iva,
                    ':total' => $imp_total,
                    ':cae'   => $cae_val
                ]);
                $importados++;
                
            } catch (Exception $e) {
                $errores++;
            }
        }
        fclose($handle);
        
        // Mensaje final detallado
        $mensaje = "Proceso finalizado.<br>";
        $mensaje .= "<span class='text-success fw-bold'>$importados</span> nuevos comprobantes importados.<br>";
        if ($duplicados > 0) {
            $mensaje .= "<span class='text-warning fw-bold'>$duplicados</span> registros ya existían (omitidos).<br>";
        }
        if ($errores > 0) {
            $mensaje .= "<span class='text-danger'>$errores</span> errores de formato.";
        }
        
        $tipo_alerta = ($importados > 0) ? "success" : "warning";
        
    } else {
        $mensaje = "Error al subir el archivo.";
        $tipo_alerta = "danger";
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

<?php include __DIR__ . '/../../public/_header.php'; ?>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="bi bi-cloud-upload"></i> Importar "Mis Comprobantes" (ARCA)</h3>
        <a href="../../public/menu.php" class="btn btn-secondary">Volver</a>
    </div>
    
    <?php if($mensaje): ?>
        <div class="alert alert-<?= $tipo_alerta ?> shadow-sm">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-bold">Seleccione archivo CSV</label>
                    <input type="file" name="csv_arca" class="form-control" accept=".csv" required>
                    <div class="form-text">
                        Descargue el archivo desde AFIP "Mis Comprobantes Recibidos" (formato CSV).
                        El sistema verificará duplicados automáticamente.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-upload"></i> Subir e Importar
                </button>
            </form>
        </div>
    </div>
    
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-bold">Últimos comprobantes importados</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th>Emisor</th>
                            <th>Comprobante</th>
                            <th class="text-end">Neto</th>
                            <th class="text-end">IVA</th>
                            <th class="text-end">Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $ultimos = $pdo->query("SELECT * FROM comprobantes_arca ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                        if(!$ultimos): ?>
                            <tr><td colspan="7" class="text-center py-3 text-muted">No hay datos importados aún.</td></tr>
                        <?php endif;
                        foreach($ultimos as $c): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                            <td>
                                <div class="fw-bold small"><?= htmlspecialchars($c['nombre_emisor']) ?></div>
                                <div class="small text-muted"><?= $c['cuit_emisor'] ?></div>
                            </td>
                            <td><?= $c['tipo_comprobante'] ?> <br> N° <?= $c['numero'] ?></td>
                            
                            <td class="text-end text-muted">$ <?= number_format($c['importe_neto'], 2, ',', '.') ?></td>
                            <td class="text-end text-muted">$ <?= number_format($c['importe_iva'], 2, ',', '.') ?></td>
                            <td class="text-end fw-bold text-dark">$ <?= number_format($c['importe_total'], 2, ',', '.') ?></td>
                            
                            <td>
                                <?php if($c['estado_uso']=='DISPONIBLE'): ?>
                                    <span class="badge bg-success">Disponible</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Vinculado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

</body>
</html>
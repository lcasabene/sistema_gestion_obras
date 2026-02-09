<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$mensaje = "";

if (isset($_POST['importar'])) {
    $file = $_FILES['archivo_csv']['tmp_name'];
    $fecha_version = $_POST['fecha_version']; 
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        fgetcsv($handle, 2000, ";"); // Saltar cabecera
        
        $pdo->beginTransaction();
        try {
            // SQL para insertar
            $sql = "INSERT INTO presupuesto_ejecucion (
                ejer, fecha_listado, tipo_imp, juri, sa, unor, fina, func, subf, inci, 
                ppal, ppar, spar, fufi, ubge, monto_def, monto_comp, monto_ejec, 
                monto_disp, monto_sald, monto_reep, tiju, tisa, tide, tiuo, cpn1, 
                cpn2, cpn3, atn1, atn2, atn3, denominacion1, denominacion2, 
                denominacion3, imputacion, preventivos, desc_imputacion, fecha_carga
            ) VALUES (" . str_repeat('?,', 37) . "?)";
            
            $stmt = $pdo->prepare($sql);

            $limpiarNum = function($n) {
                if ($n == '-' || trim($n) == '' || $n == ' -   ') return 0;
                $n = str_replace('.', '', $n);
                $n = str_replace(',', '.', $n);
                return (float)$n;
            };

            while (($d = fgetcsv($handle, 2000, ";")) !== FALSE) {
                if (count($d) < 37) continue; 

                $params = [
                    $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9],
                    $d[10], $d[11], $d[12], $d[13], $d[14], 
                    $limpiarNum($d[15]), $limpiarNum($d[16]), $limpiarNum($d[17]), 
                    $limpiarNum($d[18]), $limpiarNum($d[19]), $limpiarNum($d[20]), 
                    $d[21], $d[22], $d[23], $d[24], $d[25],
                    $d[26], $d[27], $d[28], $d[29], $d[30],
                    $d[31], $d[32], $d[33], $d[34],
                    $limpiarNum($d[35]), $d[36],
                    $fecha_version 
                ];
                $stmt->execute($params);
            }
            $pdo->commit();
            $mensaje = "<div class='alert alert-success'>Versión del día $fecha_version importada correctamente.</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
        fclose($handle);
    }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="container mt-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div></div> <a href="../../public/menu.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left-circle me-1"></i> Volver al Menú
        </a>
    </div>

    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Importar Ejecución Presupuestaria</h5>
        </div>
        <div class="card-body">
            <?php echo $mensaje; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">1. Fecha de la Ejecución (Versión)</label>
                        <input type="date" name="fecha_version" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                        <small class="text-muted">Indica a qué día corresponde esta información.</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">2. Archivo CSV</label>
                        <input type="file" name="archivo_csv" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <a href="presupuesto.php" class="btn btn-outline-secondary">
                        <i class="bi bi-clock-history"></i> Ver Historial
                    </a>
                    <button type="submit" name="importar" class="btn btn-success">
                        <i class="bi bi-cloud-arrow-up"></i> Iniciar Importación
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
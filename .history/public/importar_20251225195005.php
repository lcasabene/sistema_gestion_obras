<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

$mensaje = "";

// ---------------------------------------------------------
// LÓGICA 1: ELIMINAR VERSIÓN (Borrar datos por fecha)
// ---------------------------------------------------------
if (isset($_POST['eliminar_version'])) {
    $fecha_borrar = $_POST['fecha_borrar'];
    
    if (!empty($fecha_borrar)) {
        try {
            $sql = "DELETE FROM presupuesto_ejecucion WHERE fecha_version = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$fecha_borrar]);
            
            $count = $stmt->rowCount();
            if ($count > 0) {
                $mensaje = "<div class='alert alert-warning'>Se eliminaron <b>$count</b> registros correspondientes a la versión del $fecha_borrar.</div>";
            } else {
                $mensaje = "<div class='alert alert-info'>No se encontraron registros para la fecha $fecha_borrar.</div>";
            }
        } catch (Exception $e) {
            $mensaje = "<div class='alert alert-danger'>Error al eliminar: " . $e->getMessage() . "</div>";
        }
    }
}

// ---------------------------------------------------------
// LÓGICA 2: IMPORTAR CSV (Con fecha automática)
// ---------------------------------------------------------
if (isset($_POST['importar'])) {
    $file = $_FILES['archivo_csv']['tmp_name'];
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        // Descartar cabecera
        fgets($handle); 
        
        $pdo->beginTransaction();
        try {
            $sql = "INSERT INTO presupuesto_ejecucion (
                ejer, fecha_listado, tipo_imp, juri, sa, unor, fina, func, subf, inci, 
                ppal, ppar, spar, fufi, ubge, monto_def, monto_comp, monto_ejec, 
                monto_disp, monto_sald, monto_reep, tiju, tisa, tide, tiuo, cpn1, 
                cpn2, cpn3, atn1, atn2, atn3, denominacion1, denominacion2, 
                denominacion3, imputacion, preventivos, desc_imputacion, fecha_carga, fecha_version
            ) VALUES (" . str_repeat('?,', 38) . "?)";
            // Nota: Agregué fecha_version al final del INSERT
            
            $stmt = $pdo->prepare($sql);

            // Función para limpiar números
            $limpiarNum = function($n) {
                if ($n == '-' || trim($n) == '' || $n == ' -   ') return 0;
                $n = str_replace('.', '', $n); // Quitar puntos de mil
                $n = str_replace(',', '.', $n); // Cambiar coma por punto
                $n = preg_replace('/[^0-9\.\-]/', '', $n); // Limpiar basura
                return (float)$n;
            };

            // Función para convertir fecha dd/mm/yyyy a Y-m-d
            $convertirFecha = function($f) {
                $dateObj = DateTime::createFromFormat('d/m/Y', trim($f));
                return $dateObj ? $dateObj->format('Y-m-d') : null;
            };

            $buffer = "";
            $filas_insertadas = 0;
            $fecha_version_detectada = null; // Aquí guardaremos la fecha del Excel

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                if ($buffer !== "") {
                    $buffer .= " " . $line;
                } else {
                    $buffer = $line;
                }

                $d = str_getcsv($buffer, ";");

                // Si la línea está rota (menos de 37 columnas), seguir leyendo
                if (count($d) < 37) {
                    continue; 
                }

                // --- DETECCIÓN DE FECHA ---
                // Tomamos la fecha de la columna 1 (la segunda columna) de la primera fila válida
                if ($fecha_version_detectada === null) {
                    // Columna [1] es "FECHA LISTADO" ej: 25/12/2025
                    $fecha_version_detectada = $convertirFecha($d[1]);
                    
                    if (!$fecha_version_detectada) {
                        throw new Exception("No se pudo leer una fecha válida en la columna 2 del CSV.");
                    }
                }

                // Ejecutamos inserción
                $params = [
                    $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9],
                    $d[10], $d[11], $d[12], $d[13], $d[14], 
                    $limpiarNum($d[15]), $limpiarNum($d[16]), $limpiarNum($d[17]), 
                    $limpiarNum($d[18]), $limpiarNum($d[19]), $limpiarNum($d[20]), 
                    $d[21], $d[22], $d[23], $d[24], $d[25],
                    $d[26], $d[27], $d[28], $d[29], $d[30],
                    $d[31], $d[32], $d[33], $d[34],
                    $limpiarNum($d[35]), $d[36],
                    date('Y-m-d'),           // fecha_carga (hoy)
                    $fecha_version_detectada // fecha_version (del excel)
                ];
                
                $stmt->execute($params);
                $filas_insertadas++;

                $buffer = ""; // Limpiar buffer
            }
            
            $pdo->commit();
            $mensaje = "<div class='alert alert-success'>Importación exitosa. <br>Versión detectada: <b>$fecha_version_detectada</b>.<br>Registros insertados: <b>$filas_insertadas</b>.</div>";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
        fclose($handle);
    } else {
        $mensaje = "<div class='alert alert-danger'>No se pudo abrir el archivo.</div>";
    }
}

include __DIR__ . '/_header.php';
?>

<div class="col-md-6 text-end">
    <div class="btn-group shadow-sm">
        <a href="menu.php" class="btn btn-primary btn-sm">Menú</a>
    </div>
</div>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-8">
            <div class="card shadow mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-file-earmark-spreadsheet"></i> Importar Ejecución</h5>
                </div>
                <div class="card-body">
                    <?php echo $mensaje; ?>
                    
                    <div class="alert alert-light border">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i> La <b>fecha de versión</b> se detectará automáticamente de la 2da columna del archivo ("FECHA LISTADO").
                        </small>
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Seleccionar Archivo CSV</label>
                            <input type="file" name="archivo_csv" class="form-control" accept=".csv" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="importar" class="btn btn-success btn-lg">
                                <i class="bi bi-cloud-arrow-up"></i> Subir e Importar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-trash"></i> Eliminar Versión</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Si subiste una versión con errores, puedes borrarla aquí seleccionando la fecha.</p>
                    
                    <form method="POST" onsubmit="return confirm('¿Estás seguro de eliminar todos los datos de esta fecha? No se puede deshacer.');">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Fecha a eliminar</label>
                            <input type="date" name="fecha_borrar" class="form-control" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="eliminar_version" class="btn btn-outline-danger">
                                Eliminar Datos
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
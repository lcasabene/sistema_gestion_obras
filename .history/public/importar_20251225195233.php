<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
require_once __DIR__ . '/../config/database.php';

$mensaje = "";

// ---------------------------------------------------------
// 1. ELIMINAR VERSIÓN (Borrar por fecha_listado)
// ---------------------------------------------------------
if (isset($_POST['eliminar_version'])) {
    $fecha_borrar = $_POST['fecha_borrar'];
    
    if (!empty($fecha_borrar)) {
        try {
            // CORREGIDO: Usamos fecha_listado en lugar de fecha_version
            $sql = "DELETE FROM presupuesto_ejecucion WHERE fecha_listado = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$fecha_borrar]);
            
            $count = $stmt->rowCount();
            if ($count > 0) {
                $mensaje = "<div class='alert alert-warning'>Se eliminaron <b>$count</b> registros con fecha de listado $fecha_borrar.</div>";
            } else {
                $mensaje = "<div class='alert alert-info'>No se encontraron registros para la fecha $fecha_borrar.</div>";
            }
        } catch (Exception $e) {
            $mensaje = "<div class='alert alert-danger'>Error al eliminar: " . $e->getMessage() . "</div>";
        }
    }
}

// ---------------------------------------------------------
// 2. IMPORTAR CSV (Detectando fecha del Excel)
// ---------------------------------------------------------
if (isset($_POST['importar'])) {
    $file = $_FILES['archivo_csv']['tmp_name'];
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        // Descartar cabecera
        fgets($handle); 
        
        $pdo->beginTransaction();
        try {
            // CORREGIDO: Quitamos 'fecha_version' del final. 
            // La fecha del excel irá en 'fecha_listado' (que ya está en la lista como el 2do campo).
            $sql = "INSERT INTO presupuesto_ejecucion (
                ejer, fecha_listado, tipo_imp, juri, sa, unor, fina, func, subf, inci, 
                ppal, ppar, spar, fufi, ubge, monto_def, monto_comp, monto_ejec, 
                monto_disp, monto_sald, monto_reep, tiju, tisa, tide, tiuo, cpn1, 
                cpn2, cpn3, atn1, atn2, atn3, denominacion1, denominacion2, 
                denominacion3, imputacion, preventivos, desc_imputacion, fecha_carga
            ) VALUES (" . str_repeat('?,', 37) . "?)"; 
            // 37 columnas del CSV + 1 columna fecha_carga (sistema)
            
            $stmt = $pdo->prepare($sql);

            $limpiarNum = function($n) {
                if ($n == '-' || trim($n) == '' || $n == ' -   ') return 0;
                $n = str_replace('.', '', $n); 
                $n = str_replace(',', '.', $n); 
                $n = preg_replace('/[^0-9\.\-]/', '', $n);
                return (float)$n;
            };

            // Función para convertir fecha Excel (d/m/Y) a MySQL (Y-m-d)
            $convertirFecha = function($f) {
                // Intenta crear fecha desde formato d/m/Y (ej: 25/12/2025)
                $dateObj = DateTime::createFromFormat('d/m/Y', trim($f));
                // Si falla, intenta Y-m-d por si acaso, o devuelve NULL
                return $dateObj ? $dateObj->format('Y-m-d') : (trim($f) ?: null);
            };

            $buffer = "";
            $filas_insertadas = 0;
            $fecha_detectada_para_mensaje = null;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                // Lógica de buffer para arreglar líneas rotas
                if ($buffer !== "") {
                    $buffer .= " " . $line;
                } else {
                    $buffer = $line;
                }

                $d = str_getcsv($buffer, ";");

                // Si tiene menos de 37 columnas, es una línea rota, seguimos leyendo
                if (count($d) < 37) {
                    continue; 
                }

                // --- PROCESAR FILA ---

                // 1. Convertir la fecha de la columna 2 (índice 1) para MySQL
                $fecha_excel_original = $d[1]; 
                $fecha_mysql = $convertirFecha($fecha_excel_original);

                // Guardamos la fecha para mostrarla en el mensaje de éxito al final
                if ($fecha_detectada_para_mensaje === null) {
                    $fecha_detectada_para_mensaje = $fecha_mysql;
                }

                // 2. Preparamos parámetros
                // Nota: $d[1] ahora se reemplaza por $fecha_mysql para que entre bien en 'fecha_listado'
                $params = [
                    $d[0], 
                    $fecha_mysql, // Insertamos en 'fecha_listado' la fecha formateada
                    $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9],
                    $d[10], $d[11], $d[12], $d[13], $d[14], 
                    $limpiarNum($d[15]), $limpiarNum($d[16]), $limpiarNum($d[17]), 
                    $limpiarNum($d[18]), $limpiarNum($d[19]), $limpiarNum($d[20]), 
                    $d[21], $d[22], $d[23], $d[24], $d[25],
                    $d[26], $d[27], $d[28], $d[29], $d[30],
                    $d[31], $d[32], $d[33], $d[34],
                    $limpiarNum($d[35]), $d[36],
                    date('Y-m-d H:i:s') // fecha_carga (timestamp actual del sistema)
                ];
                
                $stmt->execute($params);
                $filas_insertadas++;

                $buffer = ""; // Limpiar buffer para la siguiente fila
            }
            
            $pdo->commit();
            $mensaje = "<div class='alert alert-success'>Importación exitosa. <br>Fecha detectada (Listado): <b>$fecha_detectada_para_mensaje</b>.<br>Registros insertados: <b>$filas_insertadas</b>.</div>";
            
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
                            <i class="bi bi-info-circle"></i> La fecha se tomará automáticamente de la columna "FECHA LISTADO" del archivo Excel.
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
                    <h5 class="mb-0"><i class="bi bi-trash"></i> Eliminar Listado</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Si subiste una versión con errores, puedes borrarla aquí seleccionando la <b>Fecha de Listado</b>.</p>
                    
                    <form method="POST" onsubmit="return confirm('¿Estás seguro de eliminar todos los datos de esta fecha de listado? No se puede deshacer.');">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Fecha Listado a borrar</label>
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
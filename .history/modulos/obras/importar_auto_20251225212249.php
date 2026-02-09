<?php
// Configuración y Seguridad
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';;
require_login();

// Solo ADMIN o OBRAS pueden ejecutar esto
// if ($_SESSION['rol'] !== 'ADMIN' && $_SESSION['rol'] !== 'OBRAS') {
//     die("Acceso denegado.");
// }

include __DIR__ . '/../../public/_header.php';

$mensaje = "";
$registros_nuevos = 0;
$registros_existentes = 0;
$errores = 0;

// ---------------------------------------------------------
// LÓGICA DE IMPORTACIÓN
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    try {
        $pdo->beginTransaction();

        // 1. OBTENER IDs POR DEFECTO PARA TIPO Y ESTADO
        // Necesitamos claves foráneas válidas para insertar en 'obras'
        $stmtTipo = $pdo->query("SELECT id FROM tipos_obra WHERE activo=1 LIMIT 1");
        $tipoDefault = $stmtTipo->fetchColumn();

        $stmtEstado = $pdo->query("SELECT id FROM estados_obra WHERE activo=1 LIMIT 1");
        $estadoDefault = $stmtEstado->fetchColumn();

        if (!$tipoDefault || !$estadoDefault) {
            throw new Exception("Error: No se encontraron 'Tipos de Obra' o 'Estados de Obra' cargados en el sistema. Debes crear al menos uno de cada uno antes de importar.");
        }

        // 2. CONSULTAR LA TABLA ORIGEN (presupuesto_ejecucion)
        // Filtramos por UNOR 2 y 3, y aseguramos que tenga nombre (denominacion3)
        // Usamos GROUP BY imputacion para evitar duplicados si el CSV tiene varias filas de la misma obra
        $sqlFuente = "SELECT * FROM presupuesto_ejecucion 
                      WHERE unor IN (2, 3) 
                      AND denominacion1 IS NOT NULL AND denominacion1 != ''
                      AND denominacion2 IS NOT NULL AND denominacion2 != ''
                      AND denominacion3 IS NOT NULL AND denominacion3 != ''
                      GROUP BY imputacion"; 
        
        $stmtFuente = $pdo->query($sqlFuente);
        $candidatos = $stmtFuente->fetchAll(PDO::FETCH_ASSOC);

        // Preparamos sentencias de inserción
        // CORRECCIÓN: Cambiado 'ejercicio' por 'ejer' para coincidir con la estructura probable
        $sqlInsertPartida = "INSERT INTO obra_partida (
                                obra_id, juri, sa, unor, fina, func, subf, 
                                inci, ppal, ppar, spar, fufi, ubge, imputacion_codigo, 
                                denominacion1, denominacion2, denominacion3, activo
                             ) VALUES (?,  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?,  1)";
        $stmtInsertPartida = $pdo->prepare($sqlInsertPartida);
        

        foreach ($candidatos as $fila) {
            // Verificar si la obra ya existe (usando la imputación o el nombre como clave única)
            // Aquí usaremos 'imputacion' como 'codigo_interno' para verificar duplicados
            $imputacion = trim($fila['imputacion']);
            $nombreObra = trim($fila['denominacion3']);

            // Chequeo de duplicidad
            $stmtCheck = $pdo->prepare("SELECT id FROM obras WHERE codigo_interno = ? LIMIT 1");
            $stmtCheck->execute([$imputacion]);
            $existe = $stmtCheck->fetchColumn();

            if ($existe) {
                $registros_existentes++;
                continue; // Saltamos a la siguiente
            }

            // A. INSERTAR EN OBRAS
            // Usamos monto_def (crédito definitivo) como monto original inicial, o 0 si es nulo
            $monto = !empty($fila['monto_def']) ? $fila['monto_def'] : 0;
            
            $stmtInsertObra->execute([
                $imputacion,    // codigo_interno
                $nombreObra,    // denominacion
                $tipoDefault,   // tipo_obra_id
                $estadoDefault, // estado_obra_id
                $monto,         // monto_original
                $monto          // monto_actualizado
            ]);

            $idNuevaObra = $pdo->lastInsertId();

            // B. INSERTAR EN OBRA_PARTIDA
            // Mapeamos los campos de presupuesto_ejecucion a obra_partida
            $stmtInsertPartida->execute([
                $idNuevaObra,
                $fila['ejer'] ?? date('Y'), // ejercicio
                $fila['juri'],
                $fila['sa'],
                $fila['unor'],
                $fila['fina'],
                $fila['func'],
                $fila['subf'],
                $fila['inci'],
                $fila['ppal'],
                $fila['ppar'],
                $fila['spar'],
                $fila['fufi'],
                $fila['ubge'],
                $imputacion,                // imputacion_codigo
                $fila['denominacion1'],
                $fila['denominacion2'],
                $fila['denominacion3']
            ]);

            $registros_nuevos++;
        }

        $pdo->commit();
        $mensaje = "Proceso finalizado correctamente.";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errores++;
        $mensaje = "Error fatal: " . $e->getMessage();
    }
}
?>

<div class="container my-5">
    <div class="card shadow-lg border-primary">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="bi bi-magic"></i> Generación Automática de Obras</h4>
        </div>
        <div class="card-body">
            
            <div class="alert alert-info">
                <h5 class="alert-heading"><i class="bi bi-info-circle"></i> ¿Qué hace este proceso?</h5>
                <p class="mb-0">
                    Analiza la tabla de Ejecución Presupuestaria (`presupuesto_ejecucion`) buscando registros 
                    donde <strong>UNOR sea 2 o 3</strong> y que tengan la denominación completa.
                </p>
                <hr>
                <ul class="mb-0">
                    <li>Crea la Obra en el catálogo principal usando <code>denominacion3</code> como nombre.</li>
                    <li>Asigna el código presupuestario (imputación) como <code>Código Interno</code>.</li>
                    <li>Vincula automáticamente la partida presupuestaria.</li>
                </ul>
            </div>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <div class="mt-4">
                    <?php if ($errores > 0): ?>
                        <div class="alert alert-danger"><?= $mensaje ?></div>
                    <?php else: ?>
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="card bg-success text-white mb-3">
                                    <div class="card-body">
                                        <h1><?= $registros_nuevos ?></h1>
                                        <span>Obras Creadas</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-secondary text-white mb-3">
                                    <div class="card-body">
                                        <h1><?= $registros_existentes ?></h1>
                                        <span>Ya Existían (Omitidas)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i> ¡Importación completada con éxito!
                            <a href="obras_listado.php" class="alert-link">Ir al listado de obras</a>.
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                
                <div class="text-center mt-4">
                    <p class="text-muted">Presiona el botón para iniciar el análisis y creación.</p>
                    <form method="POST" onsubmit="return confirm('¿Estás seguro? Esto creará obras masivamente base a la tabla de presupuesto.');">
                        <button type="submit" class="btn btn-lg btn-primary px-5">
                            <i class="bi bi-play-circle-fill"></i> Iniciar Importación
                        </button>
                    </form>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
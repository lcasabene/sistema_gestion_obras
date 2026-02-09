<?php
// Configuración y Seguridad
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

// Validar permisos
// if ($_SESSION['rol'] !== 'ADMIN' && $_SESSION['rol'] !== 'OBRAS') {
//     die("Acceso denegado.");
// }

include __DIR__ . '/../../public/_header.php';

$mensaje = "";
$obras_creadas = 0;
$partidas_agregadas = 0;
$registros_omitidos = 0; // Duplicados exactos
$errores = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        set_time_limit(600); // 10 minutos por si acaso
        $pdo->beginTransaction();

        // 1. Validar configuraciones previas
        $tipoDefault = $pdo->query("SELECT id FROM tipos_obra WHERE activo=1 LIMIT 1")->fetchColumn();
        $estadoDefault = $pdo->query("SELECT id FROM estados_obra WHERE activo=1 LIMIT 1")->fetchColumn();

        if (!$tipoDefault || !$estadoDefault) {
            throw new Exception("Faltan 'Tipos de Obra' o 'Estados' en el sistema.");
        }

        // 2. CONSULTA SIN AGRUPAR (Trae los 126 registros)
        // Quitamos el GROUP BY para procesar obra por obra según su nombre
        $sqlFuente = "SELECT * FROM presupuesto_ejecucion 
                      WHERE unor IN (2, 3) 
                      AND denominacion3 IS NOT NULL 
                      AND denominacion3 != ''";
        
        $stmtFuente = $pdo->query($sqlFuente);
        $registros = $stmtFuente->fetchAll(PDO::FETCH_ASSOC);

        // PREPARAR CONSULTAS (Optimización)
        // Buscar obra por NOMBRE EXACTO
        $stmtBuscar = $pdo->prepare("SELECT id, monto_original FROM obras WHERE denominacion = ? LIMIT 1");
        
        // Insertar Obra
        $stmtInsertObra = $pdo->prepare("INSERT INTO obras (codigo_interno, denominacion, tipo_obra_id, estado_obra_id, monto_original, monto_actualizado, moneda, activo, created_at) VALUES (?, ?, ?, ?, ?, ?, 'ARS', 1, NOW())");

        // Actualizar Monto Obra
        $stmtUpdateMonto = $pdo->prepare("UPDATE obras SET monto_original = monto_original + ?, monto_actualizado = monto_actualizado + ? WHERE id = ?");

        // Buscar si la partida ya existe en esa obra (para no duplicar la misma imputación)
        $stmtCheckPartida = $pdo->prepare("SELECT id FROM obra_partida WHERE obra_id = ? AND imputacion_codigo = ?");

        // Insertar Partida (Con ejercicio y CPN)
        $stmtInsertPartida = $pdo->prepare("INSERT INTO obra_partida (
            obra_id, ejercicio, cpn1, cpn2, cpn3, juri, sa, unor, fina, func, subf, 
            inci, ppal, ppar, spar, fufi, ubge, imputacion_codigo, 
            denominacion1, denominacion2, denominacion3, activo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

        // 3. PROCESAR FILA POR FILA
        foreach ($registros as $fila) {
            $nombreObra = trim($fila['denominacion3']);
            $imputacion = trim($fila['imputacion']);
            $monto = !empty($fila['monto_def']) ? $fila['monto_def'] : 0;
            $ejercicio = $fila['ejer'] ?? date('Y');

            // A. ¿Ya existe la obra por nombre?
            $stmtBuscar->execute([$nombreObra]);
            $obraExistente = $stmtBuscar->fetch(PDO::FETCH_ASSOC);

            if ($obraExistente) {
                // --- OBRA EXISTENTE ---
                $idObra = $obraExistente['id'];

                // Verificamos si ya tiene ESTA imputación cargada
                $stmtCheckPartida->execute([$idObra, $imputacion]);
                $yaTienePartida = $stmtCheckPartida->fetchColumn();

                if (!$yaTienePartida) {
                    // Es la misma obra, pero una fuente de financiamiento nueva
                    // 1. Sumamos el monto
                    $stmtUpdateMonto->execute([$monto, $monto, $idObra]);
                    // 2. Agregamos la partida
                    insertarPartida($stmtInsertPartida, $idObra, $fila, $imputacion, $ejercicio);
                    $partidas_agregadas++;
                } else {
                    // Ya existe la obra y ya tiene esta imputación. Es un duplicado exacto del Excel.
                    $registros_omitidos++;
                }

            } else {
                // --- OBRA NUEVA ---
                $stmtInsertObra->execute([
                    $imputacion, // Usamos la imputación como código interno inicial
                    $nombreObra,
                    $tipoDefault,
                    $estadoDefault,
                    $monto,
                    $monto
                ]);
                $idObra = $pdo->lastInsertId();
                
                // Insertamos su partida
                insertarPartida($stmtInsertPartida, $idObra, $fila, $imputacion, $ejercicio);
                $obras_creadas++;
            }
        }

        $pdo->commit();
        $mensaje = "Proceso finalizado correctamente.";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errores++;
        $mensaje = "Error: " . $e->getMessage();
    }
}

// Función auxiliar
function insertarPartida($stmt, $idObra, $fila, $imputacion, $ejercicio) {
    $stmt->execute([
        $idObra,
        $ejercicio,
        $fila['cpn1'] ?? 0, $fila['cpn2'] ?? 0, $fila['cpn3'] ?? 0,
        $fila['juri'], $fila['sa'], $fila['unor'], $fila['fina'], $fila['func'], $fila['subf'],
        $fila['inci'], $fila['ppal'], $fila['ppar'], $fila['spar'], $fila['fufi'], $fila['ubge'],
        $imputacion,
        $fila['denominacion1'], $fila['denominacion2'], $fila['denominacion3']
    ]);
}
?>

<div class="container my-5">
    <div class="card shadow-lg border-success">
        <div class="card-header bg-success text-white">
            <h4 class="mb-0"><i class="bi bi-cloud-upload-fill"></i> Importador Final (126 Obras)</h4>
        </div>
        <div class="card-body">
            
            <div class="alert alert-warning border shadow-sm">
                <strong><i class="bi bi-exclamation-circle"></i> Importante:</strong>
                Este script procesa registro por registro basándose en el <strong>NOMBRE DE LA OBRA</strong>.
                <br>Si la obra ya existe, le agrega la partida financiera extra y suma el monto.
            </div>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <?php if ($errores > 0): ?>
                    <div class="alert alert-danger">
                        <h4>Error Fatal</h4>
                        <?= htmlspecialchars($mensaje) ?>
                    </div>
                <?php else: ?>
                    <div class="row text-center g-4 mb-4">
                        <div class="col-md-4">
                            <div class="p-4 bg-success text-white rounded shadow-sm">
                                <h1 class="fw-bold"><?= $obras_creadas ?></h1>
                                <p class="mb-0">Obras Nuevas Creadas</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-4 bg-primary text-white rounded shadow-sm">
                                <h1 class="fw-bold"><?= $partidas_agregadas ?></h1>
                                <p class="mb-0">Fuentes Agregadas (Multifuente)</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-4 bg-secondary text-white rounded shadow-sm">
                                <h1 class="fw-bold"><?= $registros_omitidos ?></h1>
                                <p class="mb-0">Repetidos (Omitidos)</p>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i> ¡Listo! Deberías tener todas tus obras cargadas.
                    </div>
                    <a href="obras_listado.php" class="btn btn-lg btn-outline-success w-100">Ver Listado de Obras</a>
                <?php endif; ?>
            <?php else: ?>
                
                <div class="text-center py-5">
                    <p class="lead">Se analizaron <strong>126 registros</strong> válidos en el simulador.</p>
                    <form method="POST">
                        <button type="submit" class="btn btn-lg btn-success px-5 shadow transform-scale" onclick="return confirm('¿Ejecutar carga masiva definitiva?');">
                            <i class="bi bi-rocket-takeoff-fill"></i> EJECUTAR IMPORTACIÓN
                        </button>
                    </form>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
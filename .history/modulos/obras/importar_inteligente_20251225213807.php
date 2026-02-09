<?php
// Configuración y Seguridad
require_once __DIR__ . '/../../auth/middleware.php';
// require_once __DIR__ . '/../../config/database.php';
require_login();

// Solo ADMIN o OBRAS
// if ($_SESSION['rol'] !== 'ADMIN' && $_SESSION['rol'] !== 'OBRAS') { die("Acceso denegado."); }

include __DIR__ . '/../../public/_header.php';

$mensaje = "";
$obras_creadas = 0;
$partidas_agregadas = 0; // Nuevas fuentes de financiamiento a obras existentes
$errores = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        set_time_limit(300);
        $pdo->beginTransaction();

        // 1. Validar requisitos previos
        $stmtTipo = $pdo->query("SELECT id FROM tipos_obra WHERE activo=1 LIMIT 1");
        $tipoDefault = $stmtTipo->fetchColumn();
        $stmtEstado = $pdo->query("SELECT id FROM estados_obra WHERE activo=1 LIMIT 1");
        $estadoDefault = $stmtEstado->fetchColumn();

        if (!$tipoDefault || !$estadoDefault) {
            throw new Exception("Error: Faltan tipos o estados de obra en el sistema.");
        }

        // 2. CONSULTAR ORIGEN
        // Traemos todas las imputaciones distintas con sus montos acumulados
        // NOTA: Sumamos monto_def para tener el total de esa fuente específica
        $sqlFuente = "SELECT imputacion, denominacion3, 
                             denominacion1, denominacion2,
                             ejer, cpn1, cpn2, cpn3, juri, sa, unor, fina, func, subf, 
                             inci, ppal, ppar, spar, fufi, ubge,
                             SUM(monto_def) as monto_total_fuente
                      FROM presupuesto_ejecucion 
                      WHERE unor IN (2, 3) 
                      AND denominacion3 IS NOT NULL AND denominacion3 != ''
                      GROUP BY imputacion"; // Agrupamos por imputación (clave financiera)
        
        $stmtFuente = $pdo->query($sqlFuente);
        $candidatos = $stmtFuente->fetchAll(PDO::FETCH_ASSOC);

        // 3. PREPARAR SENTENCIAS
        
        // Buscar si ya existe la obra por NOMBRE (para unificar fuentes)
        $stmtBuscar = $pdo->prepare("SELECT id, monto_original FROM obras WHERE denominacion = ? LIMIT 1");
        
        // Insertar Obra Nueva
        $sqlInsertObra = "INSERT INTO obras (
                            codigo_interno, denominacion, tipo_obra_id, estado_obra_id, 
                            monto_original, monto_actualizado, moneda, activo, created_at
                          ) VALUES (?, ?, ?, ?, ?, ?, 'ARS', 1, NOW())";
        $stmtInsertObra = $pdo->prepare($sqlInsertObra);

        // Actualizar monto de Obra existente (Sumar nueva fuente)
        $stmtUpdateMonto = $pdo->prepare("UPDATE obras SET monto_original = monto_original + ?, monto_actualizado = monto_actualizado + ? WHERE id = ?");

        // Insertar Partida (Detalle financiero)
        $sqlInsertPartida = "INSERT INTO obra_partida (
                                obra_id, ejercicio, cpn1, cpn2, cpn3,
                                juri, sa, unor, fina, func, subf, 
                                inci, ppal, ppar, spar, fufi, ubge, 
                                imputacion_codigo, 
                                denominacion1, denominacion2, denominacion3, activo
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmtInsertPartida = $pdo->prepare($sqlInsertPartida);

        // 4. PROCESAR
        foreach ($candidatos as $fila) {
            $nombreObra = trim($fila['denominacion3']);
            $imputacion = trim($fila['imputacion']);
            $montoFuente = !empty($fila['monto_total_fuente']) ? $fila['monto_total_fuente'] : 0;

            // Paso A: ¿Existe la obra física?
            $stmtBuscar->execute([$nombreObra]);
            $obraExistente = $stmtBuscar->fetch(PDO::FETCH_ASSOC);

            if ($obraExistente) {
                // --- ESCENARIO 1: LA OBRA YA EXISTE (Nueva Fuente) ---
                $idObra = $obraExistente['id'];
                
                // Actualizamos el monto total de la obra sumando esta nueva fuente
                $stmtUpdateMonto->execute([$montoFuente, $montoFuente, $idObra]);
                
                // Solo contamos como "partida agregada" si no es la misma imputación (para no duplicar partidas si corres el script 2 veces)
                // Verificamos si esa imputación ya está en esa obra
                $stmtCheckPartida = $pdo->prepare("SELECT id FROM obra_partida WHERE obra_id = ? AND imputacion_codigo = ?");
                $stmtCheckPartida->execute([$idObra, $imputacion]);
                
                if (!$stmtCheckPartida->fetchColumn()) {
                    $partidas_agregadas++;
                    // Insertamos la nueva partida abajo
                    insertarPartida($stmtInsertPartida, $idObra, $fila, $imputacion);
                }

            } else {
                // --- ESCENARIO 2: OBRA NUEVA ---
                $stmtInsertObra->execute([
                    $imputacion,    // Usamos la primera imputación como código interno
                    $nombreObra, 
                    $tipoDefault, 
                    $estadoDefault, 
                    $montoFuente, 
                    $montoFuente
                ]);
                $idObra = $pdo->lastInsertId();
                $obras_creadas++;

                // Insertamos su primera partida
                insertarPartida($stmtInsertPartida, $idObra, $fila, $imputacion);
            }
        }

        $pdo->commit();
        $mensaje = "Proceso finalizado con éxito.";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errores++;
        $mensaje = "Error: " . $e->getMessage();
    }
}

// Función auxiliar para no repetir código de inserción
function insertarPartida($stmt, $idObra, $fila, $imputacion) {
    $stmt->execute([
        $idObra,
        $fila['ejer'] ?? date('Y'),
        $fila['cpn1'] ?? 0, $fila['cpn2'] ?? 0, $fila['cpn3'] ?? 0,
        $fila['juri'], $fila['sa'], $fila['unor'], $fila['fina'], $fila['func'], $fila['subf'],
        $fila['inci'], $fila['ppal'], $fila['ppar'], $fila['spar'], $fila['fufi'], $fila['ubge'],
        $imputacion,
        $fila['denominacion1'], $fila['denominacion2'], $fila['denominacion3']
    ]);
}
?>

<div class="container my-5">
    <div class="card shadow border-success">
        <div class="card-header bg-success text-white">
            <h4 class="mb-0"><i class="bi bi-diagram-3-fill"></i> Importador Inteligente de Obras</h4>
        </div>
        <div class="card-body">
            
            <div class="alert alert-light border">
                <strong>Lógica de Unificación:</strong>
                Este script busca obras por su <u>Nombre (Denominación 3)</u>.
                <ul>
                    <li>Si el nombre <strong>no existe</strong>: Crea la Obra.</li>
                    <li>Si el nombre <strong>ya existe</strong>: Le agrega la nueva fuente de financiamiento (Partida) y suma el monto.</li>
                </ul>
            </div>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <?php if ($errores > 0): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($mensaje) ?></div>
                <?php else: ?>
                    <div class="row text-center g-3">
                        <div class="col-md-6">
                            <div class="card bg-success text-white h-100">
                                <div class="card-body">
                                    <h1 class="display-4"><?= $obras_creadas ?></h1>
                                    <p>Obras Nuevas Creadas</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-primary text-white h-100">
                                <div class="card-body">
                                    <h1 class="display-4"><?= $partidas_agregadas ?></h1>
                                    <p>Fuentes Extra Agregadas<br><small>(Misma obra, distinta financiación)</small></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 text-center">
                        <a href="obras_listado.php" class="btn btn-lg btn-outline-success">Ir al Listado de Obras</a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <form method="POST" class="text-center py-3">
                    <button type="submit" class="btn btn-success btn-lg px-5 shadow" onclick="return confirm('¿Iniciar fusión e importación?');">
                        <i class="bi bi-play-circle"></i> Procesar Presupuesto
                    </button>
                </form>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
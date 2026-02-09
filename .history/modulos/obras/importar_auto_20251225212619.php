<?php
// Configuración y Seguridad
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
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
        // Aumentar tiempo de ejecución por si son muchos datos
        set_time_limit(300); 
        $pdo->beginTransaction();

        // 1. OBTENER IDs POR DEFECTO PARA TIPO Y ESTADO
        // Es obligatorio que existan en la tabla obras
        $stmtTipo = $pdo->query("SELECT id FROM tipos_obra WHERE activo=1 LIMIT 1");
        $tipoDefault = $stmtTipo->fetchColumn();

        $stmtEstado = $pdo->query("SELECT id FROM estados_obra WHERE activo=1 LIMIT 1");
        $estadoDefault = $stmtEstado->fetchColumn();

        if (!$tipoDefault || !$estadoDefault) {
            throw new Exception("Error Previo: Debes tener al menos un 'Tipo de Obra' y un 'Estado' cargados en el sistema (Tablas tipos_obra y estados_obra).");
        }

        // 2. CONSULTAR LA TABLA ORIGEN (presupuesto_ejecucion)
        // Filtramos por UNOR 2 y 3, y que tenga nombre (denominacion3)
        // Agrupamos por imputacion para no duplicar
        $sqlFuente = "SELECT * FROM presupuesto_ejecucion 
                      WHERE unor IN (2, 3) 
                      AND denominacion3 IS NOT NULL AND denominacion3 != ''
                      GROUP BY imputacion"; 
        
        $stmtFuente = $pdo->query($sqlFuente);
        $candidatos = $stmtFuente->fetchAll(PDO::FETCH_ASSOC);

        // 3. PREPARAR INSERCIONES
        
        // A. Insertar en OBRAS
        $sqlInsertObra = "INSERT INTO obras (
                            codigo_interno, denominacion, tipo_obra_id, estado_obra_id, 
                            monto_original, monto_actualizado, moneda, activo, created_at
                          ) VALUES (?, ?, ?, ?, ?, ?, 'ARS', 1, NOW())";
        $stmtInsertObra = $pdo->prepare($sqlInsertObra);

        // B. Insertar en OBRA_PARTIDA (Sin columna ejercicio)
        // Incluimos cpn1, cpn2, cpn3 que vimos en tu formulario
        $sqlInsertPartida = "INSERT INTO obra_partida (
                                obra_id, cpn1, cpn2, cpn3, juri, sa, unor, fina, func, subf, 
                                inci, ppal, ppar, spar, fufi, ubge, imputacion_codigo, 
                                denominacion1, denominacion2, denominacion3, activo
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmtInsertPartida = $pdo->prepare($sqlInsertPartida);

        // Verificador de duplicados
        $stmtCheck = $pdo->prepare("SELECT id FROM obras WHERE codigo_interno = ? LIMIT 1");

        foreach ($candidatos as $fila) {
            // Usamos la imputación como código único para identificar la obra
            $imputacion = trim($fila['imputacion']);
            $nombreObra = trim($fila['denominacion3']);

            // Chequeo: ¿Ya existe esta obra?
            $stmtCheck->execute([$imputacion]);
            if ($stmtCheck->fetchColumn()) {
                $registros_existentes++;
                continue; // Saltamos al siguiente registro
            }

            // 1. Insertar OBRA
            $monto = !empty($fila['monto_def']) ? $fila['monto_def'] : 0;
            
            $stmtInsertObra->execute([
                $imputacion,    // codigo_interno
                $nombreObra,    // denominacion
                $tipoDefault,   // tipo_obra_id
                $estadoDefault, // estado_obra_id
                $monto,         // monto_original
                $monto          // monto_actualizado (inicialmente igual al original)
            ]);

            $idNuevaObra = $pdo->lastInsertId();

            // 2. Insertar PARTIDA (Detalle presupuestario)
            $stmtInsertPartida->execute([
                $idNuevaObra,
                $fila['cpn1'] ?? 0,
                $fila['cpn2'] ?? 0,
                $fila['cpn3'] ?? 0,
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
                $imputacion, // imputacion_codigo
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
            <h4 class="mb-0"><i class="bi bi-database-add"></i> Importar Obras desde Presupuesto</h4>
        </div>
        <div class="card-body">
            
            <div class="alert alert-light border shadow-sm">
                <h5 class="alert-heading text-primary"><i class="bi bi-info-circle"></i> Funcionamiento</h5>
                <ul class="mb-0 small text-muted">
                    <li>Lee la tabla <code>presupuesto_ejecucion</code>.</li>
                    <li>Filtra registros donde <strong>UNOR es 2 o 3</strong>.</li>
                    <li>Utiliza la columna <strong>denominacion3</strong> como nombre de la Obra.</li>
                    <li>Utiliza la <strong>imputación</strong> como Código Interno para evitar duplicados.</li>
                    <li>Crea automáticamente la relación en <code>obra_partida</code> con todos los códigos (JURI, SA, PPAL, etc.).</li>
                </ul>
            </div>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <div class="mt-4">
                    <?php if ($errores > 0): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($mensaje) ?>
                        </div>
                        <a href="importar_auto.php" class="btn btn-outline-secondary">Intentar de nuevo</a>
                    <?php else: ?>
                        <div class="row text-center g-3 mb-3">
                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-success-subtle text-success">
                                    <h2 class="mb-0 fw-bold"><?= $registros_nuevos ?></h2>
                                    <small>Obras Nuevas Creadas</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-secondary-subtle text-secondary">
                                    <h2 class="mb-0 fw-bold"><?= $registros_existentes ?></h2>
                                    <small>Ya Existían (Omitidas)</small>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i> Importación exitosa.
                        </div>
                        <a href="obras_listado.php" class="btn btn-primary w-100">Ir al Listado de Obras</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                
                <div class="text-center py-4">
                    <p class="lead text-muted">¿Deseas procesar la base de datos de presupuesto para dar de alta las obras automáticamente?</p>
                    <form method="POST">
                        <button type="submit" class="btn btn-lg btn-primary px-5 shadow" onclick="return confirm('¿Confirmar importación masiva?');">
                            <i class="bi bi-play-fill"></i> Iniciar Importación
                        </button>
                    </form>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
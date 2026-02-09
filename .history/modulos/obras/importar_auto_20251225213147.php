<?php
// Configuración y Seguridad
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

// Solo ADMIN o OBRAS pueden ejecutar esto
if ($_SESSION['rol'] !== 'ADMIN' && $_SESSION['rol'] !== 'OBRAS') {
    die("Acceso denegado.");
}

include __DIR__ . '/../../public/_header.php';

$mensaje = "";
$registros_nuevos = 0;
$registros_existentes = 0;
$errores = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    try {
        set_time_limit(300); // 5 minutos máx
        $pdo->beginTransaction();

        // 1. Validar requisitos previos
        $stmtTipo = $pdo->query("SELECT id FROM tipos_obra WHERE activo=1 LIMIT 1");
        $tipoDefault = $stmtTipo->fetchColumn();
        $stmtEstado = $pdo->query("SELECT id FROM estados_obra WHERE activo=1 LIMIT 1");
        $estadoDefault = $stmtEstado->fetchColumn();

        if (!$tipoDefault || !$estadoDefault) {
            throw new Exception("Error: Faltan tipos o estados de obra en el sistema.");
        }

        // 2. Consultar origen (presupuesto)
        // Agrupamos por imputación para evitar duplicados
        $sqlFuente = "SELECT * FROM presupuesto_ejecucion 
                      WHERE unor IN (2, 3) 
                      AND denominacion3 IS NOT NULL AND denominacion3 != ''
                      GROUP BY imputacion"; 
        
        $stmtFuente = $pdo->query($sqlFuente);
        $candidatos = $stmtFuente->fetchAll(PDO::FETCH_ASSOC);

        // 3. Preparar Inserciones
        
        // A. Insertar OBRA
        $sqlInsertObra = "INSERT INTO obras (
                            codigo_interno, denominacion, tipo_obra_id, estado_obra_id, 
                            monto_original, monto_actualizado, moneda, activo, created_at
                          ) VALUES (?, ?, ?, ?, ?, ?, 'ARS', 1, NOW())";
        $stmtInsertObra = $pdo->prepare($sqlInsertObra);

        // B. Insertar PARTIDA (AHORA SÍ COMPLETO)
        // Mapeamos 'ejer' del origen a 'ejercicio' del destino
        $sqlInsertPartida = "INSERT INTO obra_partida (
                                obra_id, ejercicio, cpn1, cpn2, cpn3,
                                juri, sa, unor, fina, func, subf, 
                                inci, ppal, ppar, spar, fufi, ubge, 
                                imputacion_codigo, 
                                denominacion1, denominacion2, denominacion3, activo
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmtInsertPartida = $pdo->prepare($sqlInsertPartida);

        // Verificador
        $stmtCheck = $pdo->prepare("SELECT id FROM obras WHERE codigo_interno = ? LIMIT 1");

        foreach ($candidatos as $fila) {
            $imputacion = trim($fila['imputacion']);
            
            // Chequeo duplicados
            $stmtCheck->execute([$imputacion]);
            if ($stmtCheck->fetchColumn()) {
                $registros_existentes++;
                continue;
            }

            // Insertar Obra
            $monto = !empty($fila['monto_def']) ? $fila['monto_def'] : 0;
            $nombreObra = trim($fila['denominacion3']);
            
            $stmtInsertObra->execute([
                $imputacion, $nombreObra, $tipoDefault, $estadoDefault, $monto, $monto
            ]);
            $idNuevaObra = $pdo->lastInsertId();

            // Insertar Partida Completa
            $stmtInsertPartida->execute([
                $idNuevaObra,
                $fila['ejer'] ?? date('Y'), // Mapeo: ejer -> ejercicio
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
                $imputacion, 
                $fila['denominacion1'],
                $fila['denominacion2'],
                $fila['denominacion3']
            ]);

            $registros_nuevos++;
        }

        $pdo->commit();
        $mensaje = "Importación completada con éxito.";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errores++;
        $mensaje = "Error: " . $e->getMessage();
    }
}
?>

<div class="container my-5">
    <div class="card shadow border-primary">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="bi bi-database-down"></i> Importar Obras Full</h4>
        </div>
        <div class="card-body">
            
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <?php if ($errores > 0): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($mensaje) ?></div>
                <?php else: ?>
                    <div class="alert alert-success">
                        <h4><i class="bi bi-check-lg"></i> Resultado:</h4>
                        <ul>
                            <li><strong><?= $registros_nuevos ?></strong> obras nuevas creadas.</li>
                            <li><strong><?= $registros_existentes ?></strong> obras ya existían (se saltaron).</li>
                        </ul>
                        <a href="obras_listado.php" class="btn btn-success mt-2">Ver Obras</a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Este proceso importará obras desde la ejecución presupuestaria, 
                    llenando automáticamente los campos de <strong>Ejercicio, CPN y Clasificación Presupuestaria</strong>.
                </div>
                <form method="POST">
                    <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('¿Iniciar importación?');">
                        Procesar Importación
                    </button>
                </form>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
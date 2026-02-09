<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
include __DIR__ . '/../../public/_header.php';

// CONSULTA AMPLIA: Traemos todo sin agrupar para ver dato por dato
// Solo filtramos UNOR para no traer basura, pero mostramos si falta nombre
$sql = "SELECT * FROM presupuesto_ejecucion WHERE unor IN (2, 3)";
$stmt = $pdo->query($sql);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($filas);
?>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="bi bi-bug"></i> Simulador de Importación</h3>
        <span class="badge bg-primary fs-5">Total filas UNOR 2/3: <?= $total ?></span>
    </div>

    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> Esto es solo una simulación. No se están guardando datos.
        Sirve para detectar por qué faltan registros.
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm text-nowrap" style="font-size: 0.85rem;">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>UNOR</th>
                        <th>Imputación</th>
                        <th>Denominación 3 (Nombre Obra)</th>
                        <th>Monto</th>
                        <th>Estado / Acción que tomaría el sistema</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $contador_aprobados = 0;
                    foreach ($filas as $i => $f): 
                        $nombre = trim($f['denominacion3'] ?? '');
                        $imputacion = trim($f['imputacion'] ?? '');
                        $monto = number_format((float)$f['monto_def'], 2, ',', '.');
                        
                        // LÓGICA DE VALIDACIÓN
                        $estado = "";
                        $clase = "";
                        $icono = "";

                        if (empty($nombre)) {
                            $estado = "RECHAZADO: Falta Nombre (Denominación 3 está vacía)";
                            $clase = "table-danger";
                            $icono = "<i class='bi bi-x-circle-fill text-danger'></i>";
                        } elseif (empty($imputacion)) {
                            $estado = "RECHAZADO: Falta Imputación";
                            $clase = "table-danger";
                            $icono = "<i class='bi bi-x-circle-fill text-danger'></i>";
                        } else {
                            // Simulamos búsqueda de duplicado por nombre
                            // Esto es solo visual, no consultamos BD por cada fila para no colgar el server en la vista
                            $estado = "APROBADO: Se procesaría correctamente";
                            $clase = "table-success";
                            $icono = "<i class='bi bi-check-circle-fill text-success'></i>";
                            $contador_aprobados++;
                        }
                    ?>
                    <tr class="<?= $clase ?>">
                        <td><?= $i + 1 ?></td>
                        <td><?= $f['unor'] ?></td>
                        <td><?= $f['imputacion'] ?></td>
                        <td>
                            <?php if(empty($nombre)): ?>
                                <span class="badge bg-danger">VACÍO</span>
                            <?php else: ?>
                                <?= substr($nombre, 0, 40) ?>...
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= $monto ?></td>
                        <td class="fw-bold"><?= $icono ?> <?= $estado ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="card mt-3 border-success">
        <div class="card-body text-center">
            <h4>Resultado del análisis</h4>
            <p>De <strong><?= $total ?></strong> registros encontrados con UNOR 2 o 3:</p>
            <h2 class="text-success"><?= $contador_aprobados ?> pasarían el filtro</h2>
            <h2 class="text-danger"><?= $total - $contador_aprobados ?> serían rechazados</h2>
            <p class="text-muted small mt-2">
                * Si la mayoría son rechazados por "Nombre Vacío", significa que la <code>denominacion3</code> no tiene el dato. 
                Revisa si el nombre está en <code>denominacion1</code> o <code>denominacion2</code>.
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
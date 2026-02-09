<?php
// obra_eventos.php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$obraId = $_GET['obra_id'] ?? 0;
$mensaje = '';

// 1. Obtener Datos de la Obra
$stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
$stmt->execute([$obraId]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$obra) die("Obra no encontrada.");

// 2. PROCESAR FORMULARIO (Alta / Baja)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['accion']) && $_POST['accion'] === 'borrar') {
            // BORRAR EVENTO
            $stmtDel = $pdo->prepare("DELETE FROM obra_eventos WHERE id = ? AND obra_id = ?");
            $stmtDel->execute([$_POST['evento_id'], $obraId]);
            $mensaje = "Evento eliminado correctamente.";
        } else {
            // AGREGAR EVENTO
            $tipo = $_POST['tipo_evento'];
            $fecha = $_POST['fecha'];
            // Fecha fin: si viene vacía la dejamos NULL
            $fechaFin = !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null;
            $dias = !empty($_POST['dias_plazo']) ? (int)$_POST['dias_plazo'] : 0;
            $monto = !empty($_POST['monto']) ? str_replace(',', '.', $_POST['monto']) : 0;
            
            $sqlIns = "INSERT INTO obras_eventos (obra_id, tipo_evento, fecha, fecha_fin, dias_plazo, monto, acto_admin, observacion, activo) 
                       VALUES (:oid, :tipo, :fec, :ffin, :dias, :monto, :acto, :obs, 1)";
            $stmtIns = $pdo->prepare($sqlIns);
            $stmtIns->execute([
                ':oid' => $obraId,
                ':tipo' => $tipo,
                ':fec' => $fecha,
                ':ffin' => $fechaFin,
                ':dias' => $dias,
                ':monto' => $monto,
                ':acto' => $_POST['acto_admin'],
                ':obs' => $_POST['observacion']
            ]);
            $mensaje = "Evento registrado correctamente.";
        }
    } catch (Exception $e) {
        $mensaje = "Error: " . $e->getMessage();
    }
}

// 3. Obtener Eventos Existentes
$sqlEvt = "SELECT * FROM obras_eventos WHERE obra_id = ? ORDER BY fecha ASC";
$stmtEvt = $pdo->prepare($sqlEvt);
$stmtEvt->execute([$obraId]);
$eventos = $stmtEvt->fetchAll(PDO::FETCH_ASSOC);

// Helpers
function fmtMonto($val) { return number_format((float)$val, 2, ',', '.'); }
function fmtFecha($f) { return date('d/m/Y', strtotime($f)); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Eventos de Obra</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<div class="container my-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="text-primary fw-bold mb-0">
                <i class="bi bi-calendar-event"></i> Historial de Eventos
            </h4>
            <div class="text-muted"><?= htmlspecialchars($obra['denominacion']) ?></div>
        </div>
        <a href="curva_listado.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver al Listado
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <?= $mensaje ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-primary text-white fw-bold">Nuevo Evento</div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Tipo de Evento</label>
                            <select name="tipo_evento" id="tipoSelect" class="form-select" required onchange="ajustarFormulario()">
                                <option value="AMPLIACION_PLAZO">Ampliación de Plazo</option>
                                <option value="ADICIONAL">Adicional de Obra ($)</option>
                                <option value="DEDUCTIVO">Deductivo de Obra ($)</option>
                                <option value="VEDA_INVERNAL">Veda Invernal (Suspensión)</option>
                                <option value="PARALIZACION">Paralización / Neutralización</option>
                                <option value="REDETERMINACION">Redeterminación de Precios</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Fecha Inicio / Acto</label>
                            <input type="date" name="fecha" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="mb-3" id="divFechaFin" style="display:none;">
                            <label class="form-label small fw-bold text-muted">Fecha Fin (Estimada)</label>
                            <input type="date" name="fecha_fin" class="form-control">
                        </div>

                        <div class="mb-3" id="divDias">
                            <label class="form-label small fw-bold text-muted">Días de Ampliación</label>
                            <input type="number" name="dias_plazo" class="form-control" placeholder="0">
                        </div>

                        <div class="mb-3" id="divMonto" style="display:none;">
                            <label class="form-label small fw-bold text-muted">Monto ($)</label>
                            <input type="text" name="monto" class="form-control" placeholder="0.00">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Norma Legal / Detalle</label>
                            <input type="text" name="acto_admin" class="form-control" placeholder="Ej: Res. 450/25" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Observación</label>
                            <textarea name="observacion" class="form-control" rows="2"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-bold">
                            <i class="bi bi-plus-lg"></i> Registrar Evento
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Evento / Norma</th>
                                    <th>Impacto</th>
                                    <th class="text-end">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($eventos)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">No hay eventos registrados.</td></tr>
                                <?php endif; ?>

                                <?php foreach($eventos as $e): 
                                    // Determinar ícono y color según tipo
                                    $ico = 'bi-file-earmark-text'; $color = 'text-secondary';
                                    if($e['tipo_evento'] == 'AMPLIACION_PLAZO') { $ico='bi-clock-history'; $color='text-success'; }
                                    if($e['tipo_evento'] == 'ADICIONAL') { $ico='bi-cash-coin'; $color='text-primary'; }
                                    if($e['tipo_evento'] == 'VEDA_INVERNAL') { $ico='bi-snow'; $color='text-info'; }
                                    if($e['tipo_evento'] == 'PARALIZACION') { $ico='bi-pause-circle-fill'; $color='text-danger'; }
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= fmtFecha($e['fecha']) ?></div>
                                        <?php if($e['fecha_fin']): ?>
                                            <small class="text-muted">al <?= fmtFecha($e['fecha_fin']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="<?= $color ?> fw-bold small"><i class="bi <?= $ico ?>"></i> <?= str_replace('_', ' ', $e['tipo_evento']) ?></div>
                                        <div class="small text-dark"><?= htmlspecialchars($e['acto_admin']) ?></div>
                                        <?php if($e['observacion']): ?>
                                            <div class="small text-muted fst-italic"><?= htmlspecialchars($e['observacion']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($e['dias_plazo'] > 0) echo '<span class="badge bg-success">+'.$e['dias_plazo'].' días</span>'; ?>
                                        <?php if($e['monto'] > 0) echo '<div class="fw-bold text-primary">$ '.fmtMonto($e['monto']).'</div>'; ?>
                                        <?php if($e['monto'] < 0) echo '<div class="fw-bold text-danger">-$ '.fmtMonto(abs($e['monto'])).'</div>'; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" onsubmit="return confirm('¿Eliminar este evento?');">
                                            <input type="hidden" name="accion" value="borrar">
                                            <input type="hidden" name="evento_id" value="<?= $e['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm border-0"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function ajustarFormulario() {
        const tipo = document.getElementById('tipoSelect').value;
        const divMonto = document.getElementById('divMonto');
        const divDias = document.getElementById('divDias');
        const divFechaFin = document.getElementById('divFechaFin');

        // Reset visual
        divMonto.style.display = 'none';
        divDias.style.display = 'none';
        divFechaFin.style.display = 'none';

        if (tipo === 'AMPLIACION_PLAZO') {
            divDias.style.display = 'block';
        } else if (tipo === 'ADICIONAL' || tipo === 'DEDUCTIVO' || tipo === 'REDETERMINACION') {
            divMonto.style.display = 'block';
        } else if (tipo === 'VEDA_INVERNAL' || tipo === 'PARALIZACION' || tipo === 'NEUTRALIZACION') {
            divFechaFin.style.display = 'block';
        }
    }
    // Ejecutar al inicio
    ajustarFormulario();
</script>

</body>
</html>
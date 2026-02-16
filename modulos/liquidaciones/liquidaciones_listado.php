<?php
// modulos/liquidaciones/liquidaciones_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$mensaje = '';
$tipo_alerta = '';

// Filtros
$filtro_estado = $_GET['estado'] ?? '';
$filtro_empresa = $_GET['empresa_id'] ?? '';
$filtro_desde = $_GET['desde'] ?? date('Y-m-01');
$filtro_hasta = $_GET['hasta'] ?? date('Y-m-d');

// Consulta
$where = "WHERE l.fecha_pago BETWEEN :desde AND :hasta";
$params = [':desde' => $filtro_desde, ':hasta' => $filtro_hasta];

if ($filtro_estado) {
    $where .= " AND l.estado = :estado";
    $params[':estado'] = $filtro_estado;
}
if ($filtro_empresa) {
    $where .= " AND l.empresa_id = :emp";
    $params[':emp'] = $filtro_empresa;
}

$sql = "SELECT l.*, e.razon_social, e.cuit, o.denominacion AS obra_denominacion,
               u.nombre AS usuario_nombre
        FROM liquidaciones l
        JOIN empresas e ON e.id = l.empresa_id
        JOIN obras o ON o.id = l.obra_id
        JOIN usuarios u ON u.id = l.usuario_id
        $where
        ORDER BY l.fecha_pago DESC, l.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$liquidaciones = $stmt->fetchAll();

$empresas = $pdo->query("SELECT id, razon_social, cuit FROM empresas WHERE activo=1 ORDER BY razon_social")->fetchAll();

function fmt($v) { return number_format((float)$v, 2, ',', '.'); }
function badgeEstado($e) {
    $map = [
        'BORRADOR'      => 'bg-secondary',
        'PRELIQUIDADO'  => 'bg-warning text-dark',
        'CONFIRMADO'    => 'bg-success',
        'ANULADO'       => 'bg-danger',
    ];
    return '<span class="badge ' . ($map[$e] ?? 'bg-secondary') . '">' . $e . '</span>';
}
?>

<div class="container-fluid px-4 my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="text-primary fw-bold mb-0"><i class="bi bi-cash-stack"></i> Liquidaciones – Determinación Impositiva</h3>
            <p class="text-muted small mb-0">Gestión de retenciones RG 830 / SICORE / SIRE</p>
        </div>
        <div class="d-flex gap-2">
            <a href="liquidacion_form.php" class="btn btn-primary btn-sm shadow-sm"><i class="bi bi-plus-lg"></i> Nueva Liquidación</a>
            <a href="config/rg830_config.php" class="btn btn-outline-warning btn-sm"><i class="bi bi-sliders"></i> Config RG 830</a>
            <a href="exportar_sicore.php" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-arrow-down"></i> SICORE</a>
            <a href="exportar_sire.php" class="btn btn-outline-info btn-sm"><i class="bi bi-file-earmark-arrow-down"></i> SIRE</a>
            <a href="../../public/menu.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Menú</a>
        </div>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show py-2">
        <?= $mensaje ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- FILTROS -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-0">Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm" value="<?= $filtro_desde ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-0">Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm" value="<?= $filtro_hasta ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-0">Estado</label>
                    <select name="estado" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="BORRADOR" <?= $filtro_estado==='BORRADOR' ? 'selected' : '' ?>>Borrador</option>
                        <option value="PRELIQUIDADO" <?= $filtro_estado==='PRELIQUIDADO' ? 'selected' : '' ?>>Preliquidado</option>
                        <option value="CONFIRMADO" <?= $filtro_estado==='CONFIRMADO' ? 'selected' : '' ?>>Confirmado</option>
                        <option value="ANULADO" <?= $filtro_estado==='ANULADO' ? 'selected' : '' ?>>Anulado</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-0">Empresa</label>
                    <select name="empresa_id" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        <?php foreach ($empresas as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $filtro_empresa==$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['cuit'] . ' - ' . $e['razon_social']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLA -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Fecha Pago</th>
                            <th>Empresa</th>
                            <th>Obra</th>
                            <th>Comprobante</th>
                            <th class="text-end">Importe</th>
                            <th class="text-end">Retenciones</th>
                            <th class="text-end">Neto</th>
                            <th>Estado</th>
                            <th>Cert. Ret.</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($liquidaciones)): ?>
                        <tr><td colspan="11" class="text-center py-4 text-muted">No hay liquidaciones en el período seleccionado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($liquidaciones as $l): ?>
                        <tr>
                            <td class="fw-bold">#<?= $l['id'] ?></td>
                            <td><?= date('d/m/Y', strtotime($l['fecha_pago'])) ?></td>
                            <td>
                                <div class="fw-bold small"><?= htmlspecialchars($l['razon_social']) ?></div>
                                <div class="text-muted small"><?= $l['cuit'] ?></div>
                            </td>
                            <td class="small"><?= htmlspecialchars($l['obra_denominacion']) ?></td>
                            <td class="small">
                                <span class="badge bg-light text-dark"><?= $l['tipo_comprobante_origen'] ?></span>
                                <?= htmlspecialchars($l['comprobante_numero']) ?>
                            </td>
                            <td class="text-end">$ <?= fmt($l['comprobante_importe_total']) ?></td>
                            <td class="text-end fw-bold text-danger">$ <?= fmt($l['total_retenciones']) ?></td>
                            <td class="text-end fw-bold text-success">$ <?= fmt($l['neto_a_pagar']) ?></td>
                            <td><?= badgeEstado($l['estado']) ?></td>
                            <td class="small"><?= $l['nro_certificado_retencion'] ?? '-' ?></td>
                            <td class="text-end">
                                <?php if ($l['estado'] === 'PRELIQUIDADO' || $l['estado'] === 'BORRADOR'): ?>
                                <a href="liquidacion_form.php?id=<?= $l['id'] ?>" class="btn btn-outline-primary btn-sm" title="Editar"><i class="bi bi-pencil"></i></a>
                                <a href="liquidacion_eliminar.php?id=<?= $l['id'] ?>" class="btn btn-outline-danger btn-sm" title="Eliminar"><i class="bi bi-trash"></i></a>
                                <?php endif; ?>
                                <?php if ($l['estado'] === 'CONFIRMADO'): ?>
                                <a href="certificado_retencion.php?id=<?= $l['id'] ?>" class="btn btn-outline-success btn-sm" title="Certificado" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a>
                                <a href="liquidacion_anular.php?id=<?= $l['id'] ?>" class="btn btn-outline-danger btn-sm" title="Anular" onclick="return confirm('¿Anular esta liquidación?')"><i class="bi bi-x-circle"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white text-muted small">
            <?= count($liquidaciones) ?> liquidación(es) encontrada(s)
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

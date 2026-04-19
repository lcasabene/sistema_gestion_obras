<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id          = (int)($_GET['id'] ?? 0);
$programa_id = (int)($_GET['programa_id'] ?? $_POST['programa_id'] ?? 0);
$msg = '';
$rec = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $programa_id = (int)$_POST['programa_id'];
    $obra_id     = (int)$_POST['obra_id'] ?: null;
    $fecha               = $_POST['fecha'];
    $importe_usd         = (float)str_replace(',', '.', $_POST['importe_usd'] ?? 0);
    $importe_pesos       = (float)str_replace(',', '.', $_POST['importe_pesos'] ?? 0);
    $total_fuente        = (float)str_replace(',', '.', $_POST['total_fuente_externa'] ?? 0);
    $total_contraparte   = (float)str_replace(',', '.', $_POST['total_contraparte'] ?? 0);
    $observaciones       = trim($_POST['observaciones'] ?? '');
    if (!$programa_id) {
        $msg = 'danger|Debe seleccionar un programa.';
    } elseif (!$fecha) {
        $msg = 'danger|La fecha es obligatoria.';
    } else {
        if ($id) {
            $pdo->prepare("UPDATE programa_rendiciones SET obra_id=?,fecha=?,importe_usd=?,importe_pesos=?,total_fuente_externa=?,total_contraparte=?,observaciones=? WHERE id=?")
                ->execute([$obra_id,$fecha,$importe_usd,$importe_pesos,$total_fuente,$total_contraparte,$observaciones,$id]);
        } else {
            $pdo->prepare("INSERT INTO programa_rendiciones (programa_id,obra_id,fecha,importe_usd,importe_pesos,total_fuente_externa,total_contraparte,observaciones,usuario_id) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$programa_id,$obra_id,$fecha,$importe_usd,$importe_pesos,$total_fuente,$total_contraparte,$observaciones,$_SESSION['user_id']]);
        }
        header("Location: programa_ver.php?id=$programa_id#tabRendiciones");
        exit;
    }
}

if ($id) {
    $s = $pdo->prepare("SELECT * FROM programa_rendiciones WHERE id=?");
    $s->execute([$id]);
    $rec = $s->fetch() ?: [];
    $programa_id = $programa_id ?: ($rec['programa_id'] ?? 0);
}

$prog = null;
$obras = [];
$programas_lista = [];
if ($programa_id) {
    $s = $pdo->prepare("SELECT p.*, o.nombre_organismo FROM programas p JOIN organismos_financiadores o ON o.id=p.organismo_id WHERE p.id=?");
    $s->execute([$programa_id]);
    $prog = $s->fetch();
} else {
    $programas_lista = $pdo->query("SELECT p.id, p.codigo, p.nombre, o.nombre_organismo FROM programas p JOIN organismos_financiadores o ON o.id=p.organismo_id WHERE p.activo=1 ORDER BY o.nombre_organismo, p.nombre")->fetchAll();
}
$obras = $pdo->query("SELECT id, CONCAT(COALESCE(codigo_interno,''), ' – ', denominacion) AS label FROM obras WHERE activo=1 ORDER BY denominacion")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4" style="max-width:700px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-clipboard-check me-2 text-warning"></i>
            <?= $id ? 'Editar Rendición' : 'Nueva Rendición' ?>
        </h5>
        <a href="programa_ver.php?id=<?= $programa_id ?>#tabRendiciones" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Volver
        </a>
    </div>

    <?php if ($prog): ?>
    <div class="alert alert-light border mb-3 py-2">
        <small class="text-muted">Programa:</small>
        <strong><?= htmlspecialchars($prog['nombre']) ?></strong>
        <span class="badge bg-success ms-1"><?= $prog['codigo'] ?></span>
        <small class="text-muted ms-2"><?= htmlspecialchars($prog['nombre_organismo']) ?></small>
    </div>
    <?php endif; ?>

    <?php if ($msg): [$t,$m] = explode('|',$msg,2); ?>
    <div class="alert alert-<?= $t ?>"><?= htmlspecialchars($m) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST">
                <?php if (!$programa_id): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Programa <span class="text-danger">*</span></label>
                    <select name="programa_id" class="form-select" required>
                        <option value="">-- Seleccione un programa --</option>
                        <?php foreach ($programas_lista as $pl): ?>
                        <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['nombre_organismo'] . ' – ' . $pl['codigo'] . ' ' . $pl['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" name="programa_id" value="<?= $programa_id ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Obra asociada <small class="text-muted">(opcional)</small></label>
                    <select name="obra_id" class="form-select">
                        <option value="">-- Sin obra específica --</option>
                        <?php foreach ($obras as $o): ?>
                        <option value="<?= $o['id'] ?>" <?= ($rec['obra_id'] ?? null) == $o['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Fecha de Rendición <span class="text-danger">*</span></label>
                    <input type="date" name="fecha" class="form-control" required
                           value="<?= $rec['fecha'] ?? date('Y-m-d') ?>" style="max-width:220px">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Importe en USD</label>
                        <div class="input-group">
                            <span class="input-group-text">USD</span>
                            <input type="number" name="importe_usd" class="form-control" step="0.01" min="0"
                                   value="<?= $rec['importe_usd'] ?? '0' ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Importe en Pesos</label>
                        <div class="input-group">
                            <span class="input-group-text">ARS</span>
                            <input type="number" name="importe_pesos" class="form-control" step="0.01" min="0"
                                   value="<?= $rec['importe_pesos'] ?? '0' ?>">
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Total Fuente Externa</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="total_fuente_externa" class="form-control" step="0.01" min="0"
                                   value="<?= $rec['total_fuente_externa'] ?? '0' ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Total Contraparte</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="total_contraparte" class="form-control" step="0.01" min="0"
                                   value="<?= $rec['total_contraparte'] ?? '0' ?>">
                        </div>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="3"><?= htmlspecialchars($rec['observaciones'] ?? '') ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-lg me-1"></i><?= $id ? 'Guardar Cambios' : 'Registrar Rendición' ?>
                    </button>
                    <a href="programa_ver.php?id=<?= $programa_id ?>#tabRendiciones" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

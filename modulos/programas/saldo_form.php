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
    $fecha       = $_POST['fecha'];
    $banco       = trim($_POST['banco'] ?? '');
    $cuenta      = trim($_POST['cuenta'] ?? '');
    $saldo_ext   = (float)str_replace(',', '.', $_POST['saldo_moneda_extranjera'] ?? 0);
    $moneda_ext  = $_POST['moneda_extranjera'] ?: 'USD';
    $saldo_nac   = (float)str_replace(',', '.', $_POST['saldo_moneda_nacional'] ?? 0);
    $observaciones = trim($_POST['observaciones'] ?? '');
    if (!$programa_id) {
        $msg = 'danger|Debe seleccionar un programa.';
    } elseif (!$fecha) {
        $msg = 'danger|La fecha es obligatoria.';
    } else {
        if ($id) {
            $pdo->prepare("UPDATE programa_saldos SET obra_id=?,fecha=?,banco=?,cuenta=?,saldo_moneda_extranjera=?,moneda_extranjera=?,saldo_moneda_nacional=?,observaciones=? WHERE id=?")
                ->execute([$obra_id,$fecha,$banco,$cuenta,$saldo_ext,$moneda_ext,$saldo_nac,$observaciones,$id]);
        } else {
            $pdo->prepare("INSERT INTO programa_saldos (programa_id,obra_id,fecha,banco,cuenta,saldo_moneda_extranjera,moneda_extranjera,saldo_moneda_nacional,observaciones,usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$programa_id,$obra_id,$fecha,$banco,$cuenta,$saldo_ext,$moneda_ext,$saldo_nac,$observaciones,$_SESSION['user_id']]);
        }
        header("Location: programa_ver.php?id=$programa_id#tabSaldos");
        exit;
    }
}

if ($id) {
    $s = $pdo->prepare("SELECT * FROM programa_saldos WHERE id=?");
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
            <i class="bi bi-bank me-2 text-primary"></i>
            <?= $id ? 'Editar Saldo Bancario' : 'Nuevo Saldo Bancario' ?>
        </h5>
        <a href="programa_ver.php?id=<?= $programa_id ?>#tabSaldos" class="btn btn-outline-secondary btn-sm">
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
                    <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
                    <input type="date" name="fecha" class="form-control" required
                           value="<?= $rec['fecha'] ?? date('Y-m-d') ?>" style="max-width:220px">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">Banco</label>
                        <input type="text" name="banco" class="form-control" maxlength="150"
                               value="<?= htmlspecialchars($rec['banco'] ?? '') ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Nro. Cuenta</label>
                        <input type="text" name="cuenta" class="form-control" maxlength="100"
                               value="<?= htmlspecialchars($rec['cuenta'] ?? '') ?>">
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Saldo Moneda Extranjera</label>
                        <div class="input-group">
                            <span class="input-group-text text-info fw-bold">EXT</span>
                            <input type="number" name="saldo_moneda_extranjera" class="form-control" step="0.01" min="0"
                                   value="<?= $rec['saldo_moneda_extranjera'] ?? '0' ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Moneda</label>
                        <select name="moneda_extranjera" class="form-select">
                            <?php foreach (['USD','EUR','BRL'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($rec['moneda_extranjera'] ?? 'USD') === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Saldo en Pesos (ARS)</label>
                    <div class="input-group" style="max-width:300px">
                        <span class="input-group-text text-success fw-bold">ARS</span>
                        <input type="number" name="saldo_moneda_nacional" class="form-control" step="0.01" min="0"
                               value="<?= $rec['saldo_moneda_nacional'] ?? '0' ?>">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="3"><?= htmlspecialchars($rec['observaciones'] ?? '') ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $id ? 'Guardar Cambios' : 'Registrar Saldo' ?>
                    </button>
                    <a href="programa_ver.php?id=<?= $programa_id ?>#tabSaldos" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

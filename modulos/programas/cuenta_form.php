<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_can_edit();
require_once __DIR__ . '/../../config/database.php';

$id          = (int)($_GET['id'] ?? 0);
$programa_id = (int)($_GET['programa_id'] ?? $_POST['programa_id'] ?? 0);
$msg = '';
$rec = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $programa_id            = (int)$_POST['programa_id'];
    $banco                  = trim($_POST['banco'] ?? '');
    $cbu                    = trim($_POST['cbu'] ?? '') ?: null;
    $alias                  = trim($_POST['alias'] ?? '') ?: null;
    $nro_cuenta             = trim($_POST['nro_cuenta'] ?? '') ?: null;
    $servicio_administrativo= trim($_POST['servicio_administrativo'] ?? '') ?: null;
    $denominacion           = trim($_POST['denominacion'] ?? '') ?: null;
    $moneda                 = $_POST['moneda'] ?: 'ARS';
    $activa                 = isset($_POST['activa']) ? 1 : 0;
    $observaciones          = trim($_POST['observaciones'] ?? '') ?: null;

    if (!$programa_id) {
        $msg = 'danger|Debe seleccionar un programa.';
    } elseif ($banco === '') {
        $msg = 'danger|El banco es obligatorio.';
    } else {
        if ($id) {
            $pdo->prepare("UPDATE programa_cuentas SET banco=?,cbu=?,alias=?,nro_cuenta=?,servicio_administrativo=?,denominacion=?,moneda=?,activa=?,observaciones=? WHERE id=?")
                ->execute([$banco,$cbu,$alias,$nro_cuenta,$servicio_administrativo,$denominacion,$moneda,$activa,$observaciones,$id]);
        } else {
            $pdo->prepare("INSERT INTO programa_cuentas (programa_id,banco,cbu,alias,nro_cuenta,servicio_administrativo,denominacion,moneda,activa,observaciones,usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$programa_id,$banco,$cbu,$alias,$nro_cuenta,$servicio_administrativo,$denominacion,$moneda,$activa,$observaciones,$_SESSION['user_id']]);
        }
        header("Location: programa_ver.php?id=$programa_id#tabCuentas");
        exit;
    }
}

if ($id) {
    $s = $pdo->prepare("SELECT * FROM programa_cuentas WHERE id=?");
    $s->execute([$id]);
    $rec = $s->fetch() ?: [];
    $programa_id = $programa_id ?: ($rec['programa_id'] ?? 0);
}

$prog = null;
if ($programa_id) {
    $s = $pdo->prepare("SELECT p.*, o.nombre_organismo FROM programas p JOIN organismos_financiadores o ON o.id=p.organismo_id WHERE p.id=?");
    $s->execute([$programa_id]);
    $prog = $s->fetch();
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4" style="max-width:700px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-credit-card-2-front me-2 text-primary"></i>
            <?= $id ? 'Editar Cuenta Bancaria' : 'Nueva Cuenta Bancaria' ?>
        </h5>
        <a href="programa_ver.php?id=<?= $programa_id ?>#tabCuentas" class="btn btn-outline-secondary btn-sm">
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
                <input type="hidden" name="programa_id" value="<?= $programa_id ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Banco <span class="text-danger">*</span></label>
                        <input type="text" name="banco" class="form-control" maxlength="150" required
                               value="<?= htmlspecialchars($rec['banco'] ?? '') ?>"
                               placeholder="Ej: Banco Provincia del Neuquén">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Moneda</label>
                        <select name="moneda" class="form-select">
                            <?php foreach (['ARS','USD','EUR','BRL'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($rec['moneda'] ?? 'ARS') === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Denominación de la cuenta</label>
                    <input type="text" name="denominacion" class="form-control" maxlength="200"
                           value="<?= htmlspecialchars($rec['denominacion'] ?? '') ?>"
                           placeholder="Ej: Cuenta Corriente Especial Programa X">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nro. de Cuenta</label>
                        <input type="text" name="nro_cuenta" class="form-control" maxlength="60"
                               value="<?= htmlspecialchars($rec['nro_cuenta'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Alias</label>
                        <input type="text" name="alias" class="form-control" maxlength="80"
                               value="<?= htmlspecialchars($rec['alias'] ?? '') ?>"
                               placeholder="Ej: PROG.NQN.BID">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">CBU</label>
                    <input type="text" name="cbu" class="form-control font-monospace" maxlength="30"
                           pattern="\d{22}" title="22 dígitos numéricos"
                           value="<?= htmlspecialchars($rec['cbu'] ?? '') ?>"
                           placeholder="22 dígitos">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Servicio Administrativo (titular)</label>
                    <input type="text" name="servicio_administrativo" class="form-control" maxlength="150"
                           value="<?= htmlspecialchars($rec['servicio_administrativo'] ?? '') ?>"
                           placeholder="Ej: Ministerio de Obras Públicas de Neuquén">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="2"><?= htmlspecialchars($rec['observaciones'] ?? '') ?></textarea>
                </div>

                <div class="form-check mb-4">
                    <input type="checkbox" name="activa" id="chkActiva" class="form-check-input"
                           <?= (!$id || ($rec['activa'] ?? 1)) ? 'checked' : '' ?>>
                    <label for="chkActiva" class="form-check-label">Cuenta activa</label>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= $id ? 'Guardar Cambios' : 'Registrar Cuenta' ?>
                    </button>
                    <a href="programa_ver.php?id=<?= $programa_id ?>#tabCuentas" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

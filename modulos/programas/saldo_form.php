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
    $obra_id     = null;
    $cuenta_id   = (int)($_POST['cuenta_id'] ?? 0) ?: null;
    $fecha       = $_POST['fecha'];
    $banco       = trim($_POST['banco'] ?? '');
    $cuenta      = trim($_POST['cuenta'] ?? '');
    $saldo_ext   = (float)str_replace(',', '.', $_POST['saldo_moneda_extranjera'] ?? 0);
    $moneda_ext  = $_POST['moneda_extranjera'] ?: 'USD';
    $saldo_nac   = (float)str_replace(',', '.', $_POST['saldo_moneda_nacional'] ?? 0);
    $numero_extracto = trim($_POST['numero_extracto'] ?? '') ?: null;
    $observaciones = trim($_POST['observaciones'] ?? '');
    if (!$programa_id) {
        $msg = 'danger|Debe seleccionar un programa.';
    } elseif (!$fecha) {
        $msg = 'danger|La fecha es obligatoria.';
    } else {
        if ($id) {
            $pdo->prepare("UPDATE programa_saldos SET cuenta_id=?,obra_id=?,fecha=?,banco=?,cuenta=?,saldo_moneda_extranjera=?,moneda_extranjera=?,saldo_moneda_nacional=?,numero_extracto=?,observaciones=? WHERE id=?")
                ->execute([$cuenta_id,$obra_id,$fecha,$banco,$cuenta,$saldo_ext,$moneda_ext,$saldo_nac,$numero_extracto,$observaciones,$id]);
            $saldo_id = $id;
        } else {
            $pdo->prepare("INSERT INTO programa_saldos (programa_id,cuenta_id,obra_id,fecha,banco,cuenta,saldo_moneda_extranjera,moneda_extranjera,saldo_moneda_nacional,numero_extracto,observaciones,usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$programa_id,$cuenta_id,$obra_id,$fecha,$banco,$cuenta,$saldo_ext,$moneda_ext,$saldo_nac,$numero_extracto,$observaciones,$_SESSION['user_id']]);
            $saldo_id = (int)$pdo->lastInsertId();
        }

        // Adjuntar archivo (extracto bancario, opcional)
        if (!empty($_FILES['archivo']['name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['archivo'];
            $upDir   = __DIR__ . '/../../uploads/programas/';
            if (!is_dir($upDir)) mkdir($upDir, 0755, true);
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip','rar','txt','csv','odt','ods'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed) && $file['size'] <= 10 * 1024 * 1024) {
                $nombreGuardado = uniqid('sal_') . '_' . preg_replace('/[^a-zA-Z0-9._\-]/', '_', $file['name']);
                if (move_uploaded_file($file['tmp_name'], $upDir . $nombreGuardado)) {
                    $pdo->prepare("INSERT INTO programa_archivos (programa_id,entidad_tipo,entidad_id,nombre_original,nombre_guardado,mime_type,tamanio,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$programa_id, 'SALDO', $saldo_id, $file['name'], $nombreGuardado, $file['type'], $file['size'], $_SESSION['user_id']]);
                }
            }
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

$cuentas = [];
if ($programa_id) {
    $s = $pdo->prepare("SELECT * FROM programa_cuentas WHERE programa_id=? AND activa=1 ORDER BY banco, denominacion");
    $s->execute([$programa_id]);
    $cuentas = $s->fetchAll();
}

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
            <form method="POST" enctype="multipart/form-data">
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
                    <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
                    <input type="date" name="fecha" class="form-control" required
                           value="<?= $rec['fecha'] ?? date('Y-m-d') ?>" style="max-width:220px">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Cuenta Bancaria</label>
                    <?php if ($cuentas): ?>
                    <select name="cuenta_id" class="form-select">
                        <option value="">-- Sin cuenta específica (cargar banco/nro abajo) --</option>
                        <?php foreach ($cuentas as $c):
                            $label = $c['banco'];
                            if ($c['denominacion']) $label .= ' — ' . $c['denominacion'];
                            if ($c['nro_cuenta']) $label .= ' (' . $c['nro_cuenta'] . ')';
                            $label .= ' [' . $c['moneda'] . ']';
                        ?>
                        <option value="<?= $c['id'] ?>"
                                data-banco="<?= htmlspecialchars($c['banco']) ?>"
                                data-cuenta="<?= htmlspecialchars($c['nro_cuenta'] ?? '') ?>"
                                data-moneda="<?= htmlspecialchars($c['moneda']) ?>"
                                <?= ($rec['cuenta_id'] ?? null) == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        Elegí una cuenta ya registrada, o
                        <a href="cuenta_form.php?programa_id=<?= $programa_id ?>" target="_blank">crear una nueva</a>.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info py-2 small mb-2">
                        Este programa aún no tiene cuentas cargadas.
                        <?php if ($programa_id): ?>
                        <a href="cuenta_form.php?programa_id=<?= $programa_id ?>" class="alert-link">Crear una cuenta</a>
                        o completá banco / nro. de cuenta manualmente abajo.
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="row g-3 mb-3" id="bancoManualRow" <?= $cuentas && ($rec['cuenta_id'] ?? null) ? 'style="display:none"' : '' ?>>
                    <div class="col-md-7">
                        <label class="form-label fw-semibold small text-muted">Banco (manual)</label>
                        <input type="text" name="banco" class="form-control" maxlength="150"
                               value="<?= htmlspecialchars($rec['banco'] ?? '') ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold small text-muted">Nro. Cuenta (manual)</label>
                        <input type="text" name="cuenta" class="form-control" maxlength="100"
                               value="<?= htmlspecialchars($rec['cuenta'] ?? '') ?>">
                    </div>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function(){
                    var sel = document.querySelector('select[name="cuenta_id"]');
                    var row = document.getElementById('bancoManualRow');
                    if (!sel || !row) return;
                    function toggle(){ row.style.display = sel.value ? 'none' : ''; }
                    sel.addEventListener('change', toggle);
                    toggle();
                });
                </script>
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
                <div class="mb-3">
                    <label class="form-label fw-semibold">Número de Extracto <small class="text-muted">(opcional)</small></label>
                    <input type="text" name="numero_extracto" class="form-control" maxlength="80"
                           value="<?= htmlspecialchars($rec['numero_extracto'] ?? '') ?>"
                           placeholder="Ej: EXT-2025-07 / N° extracto banco">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Extracto Bancario (archivo) <small class="text-muted">(opcional)</small></label>
                    <input type="file" name="archivo" class="form-control"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.rar,.txt,.csv,.odt,.ods">
                    <div class="form-text">PDF, Word, Excel, imágenes, ZIP. Máx 10 MB.</div>
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

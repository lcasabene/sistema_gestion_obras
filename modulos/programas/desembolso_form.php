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
    $data = [
        'programa_id'     => $programa_id,
        'obra_id'         => $obra_id,
        'fecha'           => $_POST['fecha'],
        'importe'         => (float)str_replace(',', '.', $_POST['importe']),
        'moneda'          => $_POST['moneda'] ?: 'USD',
        'numero_documento'=> trim($_POST['numero_documento'] ?? '') ?: null,
        'observaciones'   => trim($_POST['observaciones'] ?? ''),
        'usuario_id'      => $_SESSION['user_id'],
    ];
    if (!$programa_id) {
        $msg = 'danger|Debe seleccionar un programa.';
    } elseif (!$data['fecha'] || $data['importe'] <= 0) {
        $msg = 'danger|Fecha e importe son obligatorios.';
    } else {
        if ($id) {
            $pdo->prepare("UPDATE programa_desembolsos SET obra_id=?,fecha=?,importe=?,moneda=?,numero_documento=?,observaciones=? WHERE id=?")
                ->execute([$obra_id, $data['fecha'], $data['importe'], $data['moneda'], $data['numero_documento'], $data['observaciones'], $id]);
            $desembolso_id = $id;
        } else {
            $pdo->prepare("INSERT INTO programa_desembolsos (programa_id,obra_id,fecha,importe,moneda,numero_documento,observaciones,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$programa_id, $obra_id, $data['fecha'], $data['importe'], $data['moneda'], $data['numero_documento'], $data['observaciones'], $data['usuario_id']]);
            $desembolso_id = (int)$pdo->lastInsertId();
        }

        // Adjuntar archivo (opcional)
        if (!empty($_FILES['archivo']['name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['archivo'];
            $upDir   = __DIR__ . '/../../uploads/programas/';
            if (!is_dir($upDir)) mkdir($upDir, 0755, true);
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip','rar','txt','csv','odt','ods'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed) && $file['size'] <= 10 * 1024 * 1024) {
                $nombreGuardado = uniqid('des_') . '_' . preg_replace('/[^a-zA-Z0-9._\-]/', '_', $file['name']);
                if (move_uploaded_file($file['tmp_name'], $upDir . $nombreGuardado)) {
                    $pdo->prepare("INSERT INTO programa_archivos (programa_id,entidad_tipo,entidad_id,nombre_original,nombre_guardado,mime_type,tamanio,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$programa_id, 'DESEMBOLSO', $desembolso_id, $file['name'], $nombreGuardado, $file['type'], $file['size'], $_SESSION['user_id']]);
                }
            }
        }

        header("Location: programa_ver.php?id=$programa_id#tabDesembolsos");
        exit;
    }
}

if ($id) {
    $s = $pdo->prepare("SELECT * FROM programa_desembolsos WHERE id=?");
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

<div class="container my-4" style="max-width:600px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-arrow-down-circle me-2 text-info"></i>
            <?= $id ? 'Editar Desembolso' : 'Nuevo Desembolso' ?>
        </h5>
        <a href="programa_ver.php?id=<?= $programa_id ?>#tabDesembolsos" class="btn btn-outline-secondary btn-sm">
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
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
                        <input type="date" name="fecha" class="form-control" required
                               value="<?= $rec['fecha'] ?? date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Moneda</label>
                        <select name="moneda" class="form-select">
                            <?php foreach (['USD','EUR','ARS','BRL'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($rec['moneda'] ?? 'USD') === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Importe <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="importe" class="form-control" step="0.01" min="0.01" required
                               value="<?= $rec['importe'] ?? '' ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Número de Documento <small class="text-muted">(opcional)</small></label>
                    <input type="text" name="numero_documento" class="form-control" maxlength="80"
                           value="<?= htmlspecialchars($rec['numero_documento'] ?? '') ?>"
                           placeholder="Ej: TRF-2025-0123 / CH-4567">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Archivo adjunto <small class="text-muted">(opcional)</small></label>
                    <input type="file" name="archivo" class="form-control"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.rar,.txt,.csv,.odt,.ods">
                    <div class="form-text">PDF, Word, Excel, imágenes, ZIP. Máx 10 MB.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="3"><?= htmlspecialchars($rec['observaciones'] ?? '') ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-info text-white">
                        <i class="bi bi-check-lg me-1"></i><?= $id ? 'Guardar Cambios' : 'Registrar Desembolso' ?>
                    </button>
                    <a href="programa_ver.php?id=<?= $programa_id ?>#tabDesembolsos" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

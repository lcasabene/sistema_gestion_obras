<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = (int)($_GET['id'] ?? 0);
$msg = '';
$programa = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'organismo_id' => (int)$_POST['organismo_id'],
        'codigo'       => trim($_POST['codigo']),
        'nombre'       => trim($_POST['nombre']),
        'descripcion'  => trim($_POST['descripcion'] ?? ''),
        'fecha_inicio' => $_POST['fecha_inicio'] ?: null,
        'fecha_fin'    => $_POST['fecha_fin'] ?: null,
        'monto_total'  => (float)str_replace(',', '.', $_POST['monto_total'] ?? 0),
        'moneda'       => $_POST['moneda'] ?: 'USD',
    ];
    if (!$data['organismo_id'] || !$data['codigo'] || !$data['nombre']) {
        $msg = 'danger|Organismo, Código y Nombre son obligatorios.';
    } else {
        if ($id) {
            $pdo->prepare("UPDATE programas SET organismo_id=?,codigo=?,nombre=?,descripcion=?,fecha_inicio=?,fecha_fin=?,monto_total=?,moneda=? WHERE id=?")
                ->execute(array_values($data) + [8 => $id]);
            $msg = 'success|Programa actualizado.';
        } else {
            $pdo->prepare("INSERT INTO programas (organismo_id,codigo,nombre,descripcion,fecha_inicio,fecha_fin,monto_total,moneda) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(array_values($data));
            $id = $pdo->lastInsertId();
            header("Location: programa_ver.php?id=$id&msg=creado");
            exit;
        }
    }
}

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM programas WHERE id=?");
    $stmt->execute([$id]);
    $programa = $stmt->fetch() ?: [];
}

$organismos = $pdo->query("SELECT id, nombre_organismo FROM organismos_financiadores WHERE activo=1 ORDER BY nombre_organismo")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4" style="max-width:700px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">
            <i class="bi bi-diagram-3 me-2 text-success"></i>
            <?= $id ? 'Editar Programa' : 'Nuevo Programa' ?>
        </h4>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    </div>

    <?php if ($msg): [$tipo,$texto] = explode('|',$msg,2); ?>
    <div class="alert alert-<?= $tipo ?>"><?= htmlspecialchars($texto) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Organismo Financiador <span class="text-danger">*</span></label>
                    <select name="organismo_id" class="form-select" required>
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($organismos as $o): ?>
                        <option value="<?= $o['id'] ?>" <?= ($programa['organismo_id'] ?? 0) == $o['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['nombre_organismo']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Código <span class="text-danger">*</span></label>
                        <input type="text" name="codigo" class="form-control" required maxlength="60"
                               value="<?= htmlspecialchars($programa['codigo'] ?? '') ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control" required maxlength="255"
                               value="<?= htmlspecialchars($programa['nombre'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Descripción</label>
                    <textarea name="descripcion" class="form-control" rows="3"><?= htmlspecialchars($programa['descripcion'] ?? '') ?></textarea>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Fecha Inicio</label>
                        <input type="date" name="fecha_inicio" class="form-control"
                               value="<?= $programa['fecha_inicio'] ?? '' ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Fecha Fin</label>
                        <input type="date" name="fecha_fin" class="form-control"
                               value="<?= $programa['fecha_fin'] ?? '' ?>">
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Monto Total</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="monto_total" class="form-control" step="0.01" min="0"
                                   value="<?= $programa['monto_total'] ?? '0' ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Moneda</label>
                        <select name="moneda" class="form-select">
                            <?php foreach (['USD','EUR','ARS','BRL'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($programa['moneda'] ?? 'USD') === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i><?= $id ? 'Guardar Cambios' : 'Crear Programa' ?>
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

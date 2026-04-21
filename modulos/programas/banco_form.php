<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
require_can_edit();

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$msg = '';
$banco = [
    'nombre_organismo'   => '',
    'sigla'              => '',
    'pais'               => '',
    'sitio_web'          => '',
    'descripcion_programa' => '',
    'activo'             => 1,
];

if ($id) {
    $s = $pdo->prepare("SELECT * FROM organismos_financiadores WHERE id=?");
    $s->execute([$id]);
    $banco = $s->fetch() ?: $banco;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre_organismo'] ?? '');
    if ($nombre === '') {
        $msg = 'danger|El nombre del banco es obligatorio.';
    } else {
        $data = [
            trim($_POST['nombre_organismo']),
            trim($_POST['sigla']              ?? ''),
            trim($_POST['pais']               ?? ''),
            trim($_POST['sitio_web']          ?? ''),
            trim($_POST['descripcion_programa'] ?? ''),
            isset($_POST['activo']) ? 1 : 0,
        ];
        if ($id) {
            $data[] = $id;
            $pdo->prepare("UPDATE organismos_financiadores
                SET nombre_organismo=?, sigla=?, pais=?, sitio_web=?, descripcion_programa=?, activo=?
                WHERE id=?")
                ->execute($data);
            $msg = 'success|Banco actualizado correctamente.';
            $banco = array_combine(
                ['nombre_organismo','sigla','pais','sitio_web','descripcion_programa','activo'],
                array_slice($data, 0, 6)
            );
        } else {
            $pdo->prepare("INSERT INTO organismos_financiadores
                (nombre_organismo, sigla, pais, sitio_web, descripcion_programa, activo)
                VALUES (?,?,?,?,?,?)")
                ->execute($data);
            $id = (int)$pdo->lastInsertId();
            header("Location: bancos_listado.php?msg=creado");
            exit;
        }
    }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4" style="max-width:680px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">
            <i class="bi bi-bank2 me-2 text-success"></i>
            <?= $id ? 'Editar Banco Financiador' : 'Nuevo Banco Financiador' ?>
        </h4>
        <div class="d-flex gap-2">
            <a href="bancos_listado.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
            <a href="../../public/menu.php" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-house me-1"></i>Menú
            </a>
        </div>
    </div>

    <?php if ($msg): [$t,$m] = explode('|',$msg,2); ?>
    <div class="alert alert-<?= $t ?>"><?= htmlspecialchars($m) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST">
                <?php if ($id): ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Nombre del Banco / Organismo <span class="text-danger">*</span></label>
                        <input type="text" name="nombre_organismo" class="form-control" required maxlength="120"
                               value="<?= htmlspecialchars($banco['nombre_organismo']) ?>"
                               placeholder="Ej: Banco Interamericano de Desarrollo">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Sigla</label>
                        <input type="text" name="sigla" class="form-control" maxlength="20"
                               value="<?= htmlspecialchars($banco['sigla'] ?? '') ?>"
                               placeholder="Ej: BID, BM, CAF">
                        <div class="form-text">Abreviatura oficial</div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">País de origen</label>
                        <input type="text" name="pais" class="form-control" maxlength="80"
                               value="<?= htmlspecialchars($banco['pais'] ?? '') ?>"
                               placeholder="Ej: Estados Unidos">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Sitio Web</label>
                        <input type="url" name="sitio_web" class="form-control" maxlength="255"
                               value="<?= htmlspecialchars($banco['sitio_web'] ?? '') ?>"
                               placeholder="https://www.iadb.org">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Descripción / Notas</label>
                    <textarea name="descripcion_programa" class="form-control" rows="3"
                              placeholder="Descripción general del banco o sus condiciones de financiamiento"><?= htmlspecialchars($banco['descripcion_programa'] ?? '') ?></textarea>
                </div>

                <div class="mb-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="activo" id="activo"
                               <?= ($banco['activo'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="activo">Banco activo</label>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i><?= $id ? 'Guardar cambios' : 'Crear banco' ?>
                    </button>
                    <a href="bancos_listado.php" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

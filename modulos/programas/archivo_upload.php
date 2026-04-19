<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
require_can_edit();

$tipo        = $_GET['tipo'] ?? $_POST['tipo'] ?? '';
$entidad_id  = (int)($_GET['entidad_id'] ?? $_POST['entidad_id'] ?? 0);
$programa_id = (int)($_GET['programa_id'] ?? $_POST['programa_id'] ?? 0);

$tipos_validos = ['DESEMBOLSO','RENDICION','SALDO','PROGRAMA','PAGO'];
if (!in_array($tipo, $tipos_validos) || !$entidad_id || !$programa_id) {
    echo "Parámetros inválidos."; exit;
}

$tabHash = [
    'DESEMBOLSO' => '#tabDesembolsos',
    'RENDICION'  => '#tabRendiciones',
    'SALDO'      => '#tabSaldos',
    'PROGRAMA'   => '',
    'PAGO'       => '#tabPagos',
];
$back = "programa_ver.php?id=$programa_id" . ($tabHash[$tipo] ?? '');

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $file  = $_FILES['archivo'];
    $upDir = __DIR__ . '/../../uploads/programas/';
    if (!is_dir($upDir)) mkdir($upDir, 0755, true);

    $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip','rar','txt','csv','odt','ods'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $maxSize = 10 * 1024 * 1024; // 10 MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = 'danger|Error al subir el archivo (código ' . $file['error'] . ').';
    } elseif (!in_array($ext, $allowed)) {
        $msg = 'danger|Extensión no permitida: ' . htmlspecialchars($ext);
    } elseif ($file['size'] > $maxSize) {
        $msg = 'danger|El archivo supera el límite de 10 MB.';
    } else {
        $nombreGuardado = uniqid('prg_') . '_' . preg_replace('/[^a-zA-Z0-9._\-]/', '_', $file['name']);
        if (move_uploaded_file($file['tmp_name'], $upDir . $nombreGuardado)) {
            $pdo->prepare("INSERT INTO programa_archivos (programa_id,entidad_tipo,entidad_id,nombre_original,nombre_guardado,mime_type,tamanio,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$programa_id, $tipo, $entidad_id, $file['name'], $nombreGuardado, $file['type'], $file['size'], $_SESSION['user_id']]);
            header("Location: $back");
            exit;
        } else {
            $msg = 'danger|No se pudo guardar el archivo. Verifique permisos de la carpeta uploads/programas/';
        }
    }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4" style="max-width:560px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-paperclip me-2 text-secondary"></i>
            Adjuntar Archivo
        </h5>
        <a href="<?= htmlspecialchars($back) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Volver
        </a>
    </div>

    <?php if ($msg): [$t,$m] = explode('|',$msg,2); ?>
    <div class="alert alert-<?= $t ?>"><?= htmlspecialchars($m) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="alert alert-light border mb-3 py-2 small">
                <strong>Tipo:</strong> <?= $tipo ?> &nbsp;|&nbsp;
                <strong>ID:</strong> <?= $entidad_id ?>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tipo" value="<?= $tipo ?>">
                <input type="hidden" name="entidad_id" value="<?= $entidad_id ?>">
                <input type="hidden" name="programa_id" value="<?= $programa_id ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Seleccionar archivo</label>
                    <input type="file" name="archivo" class="form-control" required
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.rar,.txt,.csv,.odt,.ods">
                    <div class="form-text">Formatos permitidos: PDF, Word, Excel, imágenes, ZIP. Máx 10 MB.</div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-upload me-1"></i>Subir Archivo
                    </button>
                    <a href="<?= htmlspecialchars($back) ?>" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

<?php
// modulos/liquidaciones/liquidacion_anular.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: liquidaciones_listado.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM liquidaciones WHERE id = ? AND estado = 'CONFIRMADO'");
$stmt->execute([$id]);
$liq = $stmt->fetch();

if (!$liq) {
    header("Location: liquidaciones_listado.php?err=not_found");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $motivo = trim($_POST['motivo'] ?? '');
    if (empty($motivo)) {
        header("Location: liquidacion_anular.php?id=$id&err=motivo");
        exit;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE liquidaciones SET estado='ANULADO', fecha_anulacion=NOW(), usuario_anulacion_id=?, motivo_anulacion=? WHERE id=?")
            ->execute([$_SESSION['user_id'], $motivo, $id]);

        $pdo->prepare("INSERT INTO liquidacion_logs (liquidacion_id, usuario_id, accion, motivo) VALUES (?,?,?,?)")
            ->execute([$id, $_SESSION['user_id'], 'ANULAR', $motivo]);

        $pdo->commit();
        header("Location: liquidaciones_listado.php?ok=anulado");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4" style="max-width:600px;">
    <div class="card shadow-sm border-danger">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-x-circle"></i> Anular Liquidación #<?= $id ?>
        </div>
        <div class="card-body">
            <?php if (!empty($_GET['err'])): ?>
            <div class="alert alert-warning py-2">Debe indicar un motivo de anulación.</div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <p>Certificado: <strong><?= htmlspecialchars($liq['nro_certificado_retencion']) ?></strong></p>
            <p>Importe: <strong>$ <?= number_format($liq['comprobante_importe_total'], 2, ',', '.') ?></strong></p>
            <p class="text-danger fw-bold">Esta acción no puede deshacerse.</p>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold">Motivo de anulación *</label>
                    <textarea name="motivo" class="form-control" rows="3" required placeholder="Ingrese el motivo..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <a href="liquidaciones_listado.php" class="btn btn-secondary flex-fill">Cancelar</a>
                    <button type="submit" class="btn btn-danger flex-fill" onclick="return confirm('¿Confirma la anulación?')">
                        <i class="bi bi-x-circle"></i> Anular Liquidación
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

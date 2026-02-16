<?php
// modulos/liquidaciones/liquidacion_eliminar.php
// Elimina una preliquidación (BORRADOR o PRELIQUIDADO) y libera la factura ARCA
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: liquidaciones_listado.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM liquidaciones WHERE id = ? AND estado IN ('BORRADOR','PRELIQUIDADO')");
$stmt->execute([$id]);
$liq = $stmt->fetch();

if (!$liq) {
    header("Location: liquidaciones_listado.php?err=no_eliminar");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Eliminar registros hijos antes de la liquidación
        $pdo->prepare("DELETE FROM liquidacion_logs WHERE liquidacion_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM liquidacion_items WHERE liquidacion_id = ?")->execute([$id]);

        // Eliminar liquidación
        $pdo->prepare("DELETE FROM liquidaciones WHERE id = ? AND estado IN ('BORRADOR','PRELIQUIDADO')")->execute([$id]);

        $pdo->commit();

        header("Location: liquidaciones_listado.php?ok=eliminado");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

include __DIR__ . '/../../public/_header.php';

function fmt($v) { return number_format((float)$v, 2, ',', '.'); }

// Obtener datos para mostrar
$stmtE = $pdo->prepare("SELECT razon_social, cuit FROM empresas WHERE id = ?");
$stmtE->execute([$liq['empresa_id']]);
$emp = $stmtE->fetch();
?>

<div class="container my-4" style="max-width:600px;">
    <div class="card shadow-sm border-danger">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-trash"></i> Eliminar Preliquidación #<?= $id ?>
        </div>
        <div class="card-body">
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="mb-3">
                <p class="mb-1"><strong>Estado:</strong> <span class="badge bg-warning text-dark"><?= $liq['estado'] ?></span></p>
                <p class="mb-1"><strong>Empresa:</strong> <?= htmlspecialchars($emp['cuit'] . ' - ' . $emp['razon_social']) ?></p>
                <p class="mb-1"><strong>Comprobante:</strong> <?= htmlspecialchars($liq['comprobante_tipo'] . ' ' . $liq['comprobante_numero']) ?></p>
                <p class="mb-1"><strong>Importe:</strong> $ <?= fmt($liq['comprobante_importe_total']) ?></p>
                <p class="mb-1"><strong>Fecha Pago:</strong> <?= date('d/m/Y', strtotime($liq['fecha_pago'])) ?></p>
                <?php if ($liq['comprobante_arca_id']): ?>
                <p class="mb-1"><strong>Factura ARCA ID:</strong> #<?= $liq['comprobante_arca_id'] ?> <span class="badge bg-success">Se liberará</span></p>
                <?php endif; ?>
            </div>

            <div class="alert alert-warning py-2">
                <i class="bi bi-exclamation-triangle"></i>
                Se eliminará esta preliquidación y sus ítems de retención.
                <?php if ($liq['comprobante_arca_id']): ?>
                <br>La factura ARCA asociada quedará <strong>disponible</strong> para una nueva liquidación.
                <?php endif; ?>
            </div>

            <form method="POST">
                <div class="d-flex gap-2">
                    <a href="liquidaciones_listado.php" class="btn btn-secondary flex-fill">Cancelar</a>
                    <button type="submit" class="btn btn-danger flex-fill" onclick="return confirm('¿Confirma eliminar esta preliquidación?')">
                        <i class="bi bi-trash"></i> Eliminar Preliquidación
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

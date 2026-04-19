<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

$rows = $pdo->query("
    SELECT s.id, s.fecha, s.banco, s.cuenta, s.saldo_moneda_extranjera, s.moneda_extranjera, s.saldo_moneda_nacional, s.observaciones,
           p.nombre AS programa_nombre, p.codigo AS programa_codigo, p.id AS programa_id,
           o.nombre_organismo,
           ob.denominacion AS obra_nombre, ob.codigo_interno AS obra_codigo
    FROM programa_saldos s
    JOIN programas p ON p.id = s.programa_id
    JOIN organismos_financiadores o ON o.id = p.organismo_id
    LEFT JOIN obras ob ON ob.id = s.obra_id
    ORDER BY s.fecha DESC
")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-bank me-2 text-primary"></i>Saldos Bancarios por Programa
        </h5>
        <div class="d-flex gap-2">
            <?php if (can_edit()): ?>
            <a href="saldo_form.php" class="btn btn-success btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Nuevo Saldo
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-grid me-1"></i>Ver Programas
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table id="tblSaldos" class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Organismo</th>
                        <th>Programa</th>
                        <th>Obra asociada</th>
                        <th>Banco / Cuenta</th>
                        <th class="text-end">Saldo Ext.</th>
                        <th>Moneda</th>
                        <th class="text-end">Saldo Pesos</th>
                        <th>Observaciones</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $s): ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($s['fecha']) ?></td>
                        <td class="small"><?= htmlspecialchars($s['nombre_organismo']) ?></td>
                        <td class="small">
                            <a href="programa_ver.php?id=<?= $s['programa_id'] ?>#tabSaldos" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($s['programa_codigo']) ?> – <?= htmlspecialchars($s['programa_nombre']) ?>
                            </a>
                        </td>
                        <td class="small">
                            <?php if ($s['obra_nombre']): ?>
                            <span class="text-primary"><?= htmlspecialchars($s['obra_nombre']) ?></span>
                            <?php if ($s['obra_codigo']): ?><br><small class="text-muted"><?= htmlspecialchars($s['obra_codigo']) ?></small><?php endif; ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="small">
                            <?= htmlspecialchars($s['banco'] ?? '') ?>
                            <?php if ($s['cuenta']): ?><br><span class="text-muted"><?= htmlspecialchars($s['cuenta']) ?></span><?php endif; ?>
                        </td>
                        <td class="text-end font-monospace fw-bold text-info"><?= number_format($s['saldo_moneda_extranjera'], 2, ',', '.') ?></td>
                        <td><span class="badge bg-secondary"><?= $s['moneda_extranjera'] ?></span></td>
                        <td class="text-end font-monospace fw-bold text-success"><?= number_format($s['saldo_moneda_nacional'], 2, ',', '.') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($s['observaciones'] ?? '') ?></td>
                        <td class="text-center text-nowrap">
                            <a href="programa_ver.php?id=<?= $s['programa_id'] ?>#tabSaldos"
                               class="btn btn-outline-primary btn-sm py-0 px-1" title="Ver en programa"><i class="bi bi-eye"></i></a>
                            <?php if (can_edit()): ?>
                            <a href="saldo_form.php?id=<?= $s['id'] ?>&programa_id=<?= $s['programa_id'] ?>"
                               class="btn btn-outline-primary btn-sm py-0 px-1" title="Editar"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (can_delete()): ?>
                            <a href="saldo_eliminar.php?id=<?= $s['id'] ?>&programa_id=<?= $s['programa_id'] ?>"
                               class="btn btn-outline-danger btn-sm py-0 px-1" title="Eliminar"
                               onclick="return confirm('¿Eliminar saldo?')"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(function(){
    $('#tblSaldos').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        order: [[0,'desc']],
        pageLength: 25
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

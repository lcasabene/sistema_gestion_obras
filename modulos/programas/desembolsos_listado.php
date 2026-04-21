<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

$rows = $pdo->query("
    SELECT d.id, d.fecha, d.importe, d.moneda, d.observaciones,
           p.nombre AS programa_nombre, p.codigo AS programa_codigo, p.id AS programa_id,
           o.nombre_organismo,
           ob.denominacion AS obra_nombre, ob.codigo_interno AS obra_codigo
    FROM programa_desembolsos d
    JOIN programas p ON p.id = d.programa_id
    JOIN organismos_financiadores o ON o.id = p.organismo_id
    LEFT JOIN obras ob ON ob.id = d.obra_id
    ORDER BY d.fecha DESC
")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-arrow-down-circle me-2 text-info"></i>Desembolsos por Programa
        </h5>
        <div class="d-flex gap-2">
            <?php if (can_edit()): ?>
            <a href="desembolso_form.php" class="btn btn-success btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Nuevo Desembolso
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-grid me-1"></i>Ver Programas
            </a>
            <a href="../../public/menu.php" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-house me-1"></i>Menú
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table id="tblDesemb" class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Banco Financiador</th>
                        <th>Programa</th>
                        <th>Obra asociada</th>
                        <th class="text-end">Importe</th>
                        <th>Moneda</th>
                        <th>Observaciones</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $d): ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($d['fecha']) ?></td>
                        <td class="small"><?= htmlspecialchars($d['nombre_organismo']) ?></td>
                        <td class="small">
                            <a href="programa_ver.php?id=<?= $d['programa_id'] ?>#tabDesembolsos" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($d['programa_codigo']) ?> – <?= htmlspecialchars($d['programa_nombre']) ?>
                            </a>
                        </td>
                        <td class="small">
                            <?php if ($d['obra_nombre']): ?>
                            <span class="text-primary"><?= htmlspecialchars($d['obra_nombre']) ?></span>
                            <?php if ($d['obra_codigo']): ?><br><small class="text-muted"><?= htmlspecialchars($d['obra_codigo']) ?></small><?php endif; ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-end font-monospace fw-bold"><?= number_format($d['importe'], 2, ',', '.') ?></td>
                        <td><span class="badge bg-secondary"><?= $d['moneda'] ?></span></td>
                        <td class="small text-muted"><?= htmlspecialchars($d['observaciones'] ?? '') ?></td>
                        <td class="text-center text-nowrap">
                            <a href="programa_ver.php?id=<?= $d['programa_id'] ?>#tabDesembolsos"
                               class="btn btn-outline-info btn-sm py-0 px-1" title="Ver en programa"><i class="bi bi-eye"></i></a>
                            <?php if (can_edit()): ?>
                            <a href="desembolso_form.php?id=<?= $d['id'] ?>&programa_id=<?= $d['programa_id'] ?>"
                               class="btn btn-outline-primary btn-sm py-0 px-1" title="Editar"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (can_delete()): ?>
                            <a href="desembolso_eliminar.php?id=<?= $d['id'] ?>&programa_id=<?= $d['programa_id'] ?>"
                               class="btn btn-outline-danger btn-sm py-0 px-1" title="Eliminar"
                               onclick="return confirm('¿Eliminar desembolso?')"><i class="bi bi-trash"></i></a>
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
    $('#tblDesemb').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        order: [[0,'desc']],
        pageLength: 25
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

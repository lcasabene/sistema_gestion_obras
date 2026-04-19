<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

$rows = $pdo->query("
    SELECT r.id, r.fecha, r.importe_usd, r.importe_pesos, r.total_fuente_externa, r.total_contraparte, r.observaciones,
           p.nombre AS programa_nombre, p.codigo AS programa_codigo, p.id AS programa_id,
           o.nombre_organismo,
           ob.denominacion AS obra_nombre, ob.codigo_interno AS obra_codigo
    FROM programa_rendiciones r
    JOIN programas p ON p.id = r.programa_id
    JOIN organismos_financiadores o ON o.id = p.organismo_id
    LEFT JOIN obras ob ON ob.id = r.obra_id
    ORDER BY r.fecha DESC
")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<div class="container-fluid my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-clipboard-check me-2 text-warning"></i>Rendiciones por Programa
        </h5>
        <div class="d-flex gap-2">
            <?php if (can_edit()): ?>
            <a href="rendicion_form.php" class="btn btn-success btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Nueva Rendición
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-grid me-1"></i>Ver Programas
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table id="tblRend" class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Organismo</th>
                        <th>Programa</th>
                        <th>Obra asociada</th>
                        <th class="text-end">Imp. USD</th>
                        <th class="text-end">Imp. Pesos</th>
                        <th class="text-end">Fuente Externa</th>
                        <th class="text-end">Contraparte</th>
                        <th>Observaciones</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($r['fecha']) ?></td>
                        <td class="small"><?= htmlspecialchars($r['nombre_organismo']) ?></td>
                        <td class="small">
                            <a href="programa_ver.php?id=<?= $r['programa_id'] ?>#tabRendiciones" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($r['programa_codigo']) ?> – <?= htmlspecialchars($r['programa_nombre']) ?>
                            </a>
                        </td>
                        <td class="small">
                            <?php if ($r['obra_nombre']): ?>
                            <span class="text-primary"><?= htmlspecialchars($r['obra_nombre']) ?></span>
                            <?php if ($r['obra_codigo']): ?><br><small class="text-muted"><?= htmlspecialchars($r['obra_codigo']) ?></small><?php endif; ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-end font-monospace"><?= number_format($r['importe_usd'], 2, ',', '.') ?></td>
                        <td class="text-end font-monospace"><?= number_format($r['importe_pesos'], 2, ',', '.') ?></td>
                        <td class="text-end font-monospace text-primary"><?= number_format($r['total_fuente_externa'], 2, ',', '.') ?></td>
                        <td class="text-end font-monospace text-secondary"><?= number_format($r['total_contraparte'], 2, ',', '.') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($r['observaciones'] ?? '') ?></td>
                        <td class="text-center text-nowrap">
                            <a href="programa_ver.php?id=<?= $r['programa_id'] ?>#tabRendiciones"
                               class="btn btn-outline-warning btn-sm py-0 px-1" title="Ver en programa"><i class="bi bi-eye"></i></a>
                            <?php if (can_edit()): ?>
                            <a href="rendicion_form.php?id=<?= $r['id'] ?>&programa_id=<?= $r['programa_id'] ?>"
                               class="btn btn-outline-primary btn-sm py-0 px-1" title="Editar"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (can_delete()): ?>
                            <a href="rendicion_eliminar.php?id=<?= $r['id'] ?>&programa_id=<?= $r['programa_id'] ?>"
                               class="btn btn-outline-danger btn-sm py-0 px-1" title="Eliminar"
                               onclick="return confirm('¿Eliminar rendición?')"><i class="bi bi-trash"></i></a>
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
    $('#tblRend').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        order: [[0,'desc']],
        pageLength: 25
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

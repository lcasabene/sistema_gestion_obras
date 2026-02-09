<?php
// organismos_lista.php
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

$listado = $pdo->query("SELECT * FROM organismos_financiadores WHERE activo=1")->fetchAll();
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Administración de Organismos y Programas</h4>
        <a href="organismos_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo Organismo</a>
    </div>
    <div class="card shadow-sm">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Identificación (Sigla)</th>
                    <th>Descripción del Programa</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($listado as $l): ?>
                <tr>
                    <td><?= $l['id'] ?></td>
                    <td class="fw-bold"><?= htmlspecialchars($l['nombre_organismo']) ?></td>
                    <td><?= htmlspecialchars($l['descripcion_programa']) ?></td>
                    <td class="text-center">
                        <a href="organismos_form.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
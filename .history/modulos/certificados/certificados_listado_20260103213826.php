<?php
// modulos/certificados/certificados_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// Consulta inteligente
$sql = "SELECT c.*, o.denominacion as obra, e.razon_social as empresa 
        FROM certificados c
        JOIN obras o ON c.obra_id = o.id
        LEFT JOIN empresas e ON c.empresa_id = e.id
        WHERE o.activo = 1 ORDER BY c.id DESC";
$certs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="bi bi-file-earmark-text"></i> Gestión de Certificados</h3>
        <div>
            <a href="../../public/menu.php" class="btn btn-secondary me-2">
                <i class="bi bi-arrow-left-circle me-1"></i> Volver al Menú
            </a>
            <a href="certificados_form.php" class="btn btn-success fw-bold">
                <i class="bi bi-plus-lg"></i> Nuevo Certificado
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nº / Periodo</th>
                        <th>Obra / Empresa</th>
                        <th class="text-center">% Físico</th>
                        <th class="text-end">Monto Neto</th>
                        <th class="text-center">Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($certs as $c): ?>
                    <tr>
                        <td>
                            <div class="fw-bold fs-5">Nº <?= $c['nro_certificado'] ?></div>
                            <span class="badge bg-light text-dark border"><?= $c['periodo'] ?></span>
                        </td>
                        <td>
                            <div class="fw-bold text-primary"><?= htmlspecialchars($c['obra']) ?></div>
                            <small class="text-muted"><i class="bi bi-building"></i> <?= htmlspecialchars($c['empresa'] ?? 'Sin Empresa') ?></small>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info text-dark"><?= number_format($c['avance_fisico_mensual'],2) ?>%</span>
                        </td>
                        <td class="text-end fw-bold text-success">
                            $ <?= number_format($c['monto_neto_pagar'], 2, ',', '.') ?>
                        </td>
                        <td class="text-center">
                            <?php 
                                $cls = 'secondary';
                                if($c['estado']=='APROBADO') $cls='primary';
                                if($c['estado']=='PAGADO') $cls='success';
                            ?>
                            <span class="badge bg-<?= $cls ?>"><?= $c['estado'] ?></span>
                        </td>
                        <td class="text-end">
                            <a href="certificados_form.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
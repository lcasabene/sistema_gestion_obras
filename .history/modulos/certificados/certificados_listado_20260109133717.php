<?php
// modulos/certificados/certificados_listado.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
include __DIR__ . '/../../public/_header.php';

// --- LOGICA DE ELIMINACIÓN ---
if (isset($_GET['delete_id'])) {
    $id_borrar = (int)$_GET['delete_id'];
    try {
        $pdo->beginTransaction();

        // 1. Eliminar vinculaciones con Facturas
        $pdo->prepare("DELETE FROM certificados_facturas WHERE certificado_id = ?")->execute([$id_borrar]);
        
        // 2. Eliminar vinculaciones con OPs
        $pdo->prepare("DELETE FROM certificados_ops WHERE certificado_id = ?")->execute([$id_borrar]);
        
        // 3. Eliminar el certificado
        $pdo->prepare("DELETE FROM certificados WHERE id = ?")->execute([$id_borrar]);

        $pdo->commit();
        $msg = "Certificado eliminado correctamente.";
        $msg_type = "success";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "Error al eliminar: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// --- CONSULTA LISTADO ---
// Traemos datos básicos + conteo de vinculaciones para mostrar iconos informativos
$sql = "SELECT 
            c.*, 
            o.denominacion as obra, 
            e.razon_social as empresa,
            (SELECT COUNT(*) FROM certificados_facturas WHERE certificado_id = c.id) as cant_fac,
            (SELECT COUNT(*) FROM certificados_ops WHERE certificado_id = c.id) as cant_ops
        FROM certificados c
        JOIN obras o ON c.obra_id = o.id
        LEFT JOIN empresas e ON c.empresa_id = e.id
        WHERE o.activo = 1 
        ORDER BY c.id DESC";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">

<div class="container-fluid my-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">Certificados de Obra</h2>
            <p class="text-muted small mb-0">Gestión y control de pagos</p>
        </div>
        <div>
            <a href="trazabilidad.php" class="btn btn-info text-white me-2 shadow-sm">
                <i class="bi bi-diagram-3-fill"></i> Ver Trazabilidad
            </a>
            
            <a href="certificados_form.php" class="btn btn-primary shadow-sm">
                <i class="bi bi-plus-lg"></i> Nuevo Certificado
            </a>
            <a href="../../public/menu.php" class="btn btn-secondary shadow-sm">Volver</a>
        </div>
    </div>

    <?php if(isset($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if(isset($_GET['status']) && $_GET['status']=='success'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            Certificado guardado exitosamente.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaCertificados" class="table table-hover align-middle" style="font-size: 0.95rem;">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Obra / Empresa</th>
                            <th>Certificado</th>
                            <th>Periodo</th>
                            <th class="text-end">Monto Neto</th>
                            <th>Estado / Venc.</th>
                            <th class="text-center">Info</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($rows as $r): 
                            // Lógica de Vencimiento Visual
                            $hoy = date('Y-m-d');
                            $venc = $r['fecha_vencimiento'];
                            $estadoBadge = '<span class="badge bg-secondary">Sin Fecha</span>';
                            
                            if($venc) {
                                if($venc < $hoy) {
                                    $estadoBadge = '<span class="badge bg-danger">Vencido</span>';
                                } elseif($venc < date('Y-m-d', strtotime('+15 days'))) {
                                    $estadoBadge = '<span class="badge bg-warning text-dark">Vence pronto</span>';
                                } else {
                                    $estadoBadge = '<span class="badge bg-success">En fecha</span>';
                                }
                            }
                        ?>
                        <tr>
                            <td><?= $r['id'] ?></td>
                            
                            <td>
                                <div class="fw-bold text-primary"><?= mb_substr($r['obra'], 0, 50) ?></div>
                                <small class="text-muted"><?= mb_substr($r['empresa'], 0, 30) ?></small>
                            </td>

                            <td>
                                <span class="fw-bold">N° <?= $r['nro_certificado'] ?></span><br>
                                <span class="badge bg-light text-dark border"><?= $r['tipo'] ?></span>
                            </td>

                            <td><?= date('m/Y', strtotime($r['periodo'].'-01')) ?></td>

                            <td class="text-end fw-bold">
                                $ <?= number_format($r['monto_neto'], 2, ',', '.') ?>
                            </td>

                            <td>
                                <?= $estadoBadge ?><br>
                                <small class="text-muted"><?= $venc ? date('d/m/Y', strtotime($venc)) : '-' ?></small>
                            </td>

                            <td class="text-center">
                                <?php if($r['cant_fac'] > 0): ?>
                                    <span class="badge bg-info text-dark" title="Tiene Facturas"><i class="bi bi-receipt"></i></span>
                                <?php endif; ?>
                                <?php if($r['cant_ops'] > 0): ?>
                                    <span class="badge bg-success" title="Tiene OPs vinculadas"><i class="bi bi-cash"></i></span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="certificados_form.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                    <a href="certificados_listado.php?delete_id=<?= $r['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       title="Eliminar"
                                       onclick="return confirm('¿Está seguro de eliminar este certificado? Se borrarán también las vinculaciones de facturas y OPs.');">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function () {
        $('#tablaCertificados').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
            pageLength: 25,
            order: [[0, 'desc']], // Ordenar por ID descendente (lo más nuevo primero)
            columnDefs: [
                { orderable: false, targets: 7 } // No ordenar columna de acciones
            ]
        });
    });
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
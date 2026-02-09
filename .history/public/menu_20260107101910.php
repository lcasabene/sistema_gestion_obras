<?php
require_once __DIR__ . '/../auth/middleware.php';
require_login();
include __DIR__ . '/_header.php';

$rol_usuario = $_SESSION['rol'] ?? 'ADMIN'; 

function tiene_acceso($modulo, $rol_actual) {
    if ($rol_actual === 'ADMIN') return true;
    return $rol_actual === $modulo;
}
?>
<div class="container-fluid my-4">
  
        <div>
            <a class="btn btn-secondary shadow-sm" href="menu.php"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h2>Panel de Gestión</h2>
        <span class="badge bg-secondary"><?= htmlspecialchars($rol_usuario) ?></span>
    </div>

    <div class="row g-4">

        <?php if (tiene_acceso('PRESUPUESTO', $rol_usuario)): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-success text-white fw-bold"><i class="bi bi-cash-stack"></i> Presupuesto</div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/presupuesto/presupuesto.php" class="list-group-item list-group-item-action">
                        Partidas y Créditos
                    </a>
                    <a href="../modulos/presupuesto/importar.php" class="list-group-item list-group-item-action">
                        Importar Ejec.
                    </a>
                    <a href="../modulos/presupuesto/reporte_ejecucion.php" class="list-group-item list-group-item-action">
                        Reporte Ejecución
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (tiene_acceso('OBRAS', $rol_usuario)): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-primary text-white fw-bold"><i class="bi bi-building-gear"></i> Gestión de Obras</div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/obras/obras_listado.php" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <div><i class="bi bi-list-check me-2 text-primary"></i> Listado y Avance</div>
                        <span class="badge bg-light text-dark border">Principal</span>
                    </a>
                    <a href="../modulos/certificados/certificados_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark-text me-2 text-success"></i> Certificados y Pagos
                    </a>
                    <a href="../modulos/empresas/empresas_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-briefcase me-2 text-secondary"></i> Empresas Contratistas
                    </a>
                    <a href="../modulos/curva/curva_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-briefcase me-2 text-secondary"></i> Curvas
                    </a>
                    <a href="../modulos/vedas/vedas_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-snow2 me-2 text-info"></i> Vedas y Paralizaciones
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-md-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-secondary text-white fw-bold"><i class="bi bi-sliders"></i> Configuración</div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/fuentes/fuentes_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-bank me-2"></i> Fuentes Financiamiento
                    </a>
                    <a href="../modulos/arca/arca_import.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-cloud-upload me-2 text-primary"></i> Importar ARCA
                    </a>
                    <a href="../modulos/arca/facturas_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-receipt me-2 text-primary"></i> Facturas ARCA
                    </a>

                    <?php if ($rol_usuario === 'ADMIN'): ?>
                    <a href="../modulos/admin/usuarios.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-people me-2"></i> Usuarios y Roles
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
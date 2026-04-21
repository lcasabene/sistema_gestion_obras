<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/database.php';
require_login();
include __DIR__ . '/_header.php';

$roles_usuario = $_SESSION['user_roles'] ?? [];
$es_admin = is_admin();

function tiene_acceso_modulo($clave) {
    return can_access_module($clave);
}
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
        <div>
            <h2 class="mb-0 fw-bold">Panel de Gestión</h2>
            <small class="text-muted"><?= htmlspecialchars($_SESSION['user_nombre'] ?? '') ?></small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php foreach ($roles_usuario as $r): ?>
                <span class="badge fs-6 <?= match(strtolower($r)) {
                    'admin'    => 'bg-dark',
                    'editor'   => 'bg-primary',
                    'consulta' => 'bg-secondary',
                    default    => 'bg-secondary'
                } ?>"><?= htmlspecialchars($r) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- FILA 1: Módulos principales -->
    <div class="row g-4 mb-4">

        <?php if (tiene_acceso_modulo('obras')): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="bi bi-building-gear me-2"></i>Gestión de Obras
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/obras/obras_listado.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-check me-2 text-primary"></i>Listado y Avance</span>
                        <span class="badge bg-light text-dark border">Principal</span>
                    </a>
                    <a href="../modulos/certificados/certificados_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark-check me-2 text-success"></i>Certificados y Pagos
                    </a>
                    <a href="../modulos/empresas/empresas_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-briefcase me-2 text-secondary"></i>Empresas Contratistas
                    </a>
                    <a href="../modulos/curva/curva_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-graph-up me-2 text-warning"></i>Curvas de Inversión
                    </a>
                    <a href="../modulos/vedas/vedas_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-snow2 me-2 text-info"></i>Vedas y Paralizaciones
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (tiene_acceso_modulo('liquidaciones')): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-danger text-white fw-bold">
                    <i class="bi bi-calculator me-2"></i>Liquidaciones – Impositiva
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/liquidaciones/liquidaciones_listado.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-cash-stack me-2 text-danger"></i>Listado de Liquidaciones</span>
                        <span class="badge bg-light text-dark border">Principal</span>
                    </a>
                    <a href="../modulos/liquidaciones/liquidacion_form.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-plus-circle me-2 text-primary"></i>Nueva Liquidación
                    </a>
                    <a href="../modulos/liquidaciones/exportar_sicore.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark-arrow-down me-2 text-success"></i>Exportar SICORE
                    </a>
                    <a href="../modulos/liquidaciones/exportar_sire.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark-arrow-down me-2 text-info"></i>Exportar SIRE F2004
                    </a>
                    <?php if ($es_admin): ?>
                    <a href="../modulos/liquidaciones/config/rg830_config.php" class="list-group-item list-group-item-action bg-warning-subtle">
                        <i class="bi bi-sliders me-2 text-warning"></i><strong>Config RG 830</strong>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (tiene_acceso_modulo('presupuesto')): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-success text-white fw-bold">
                    <i class="bi bi-cash-stack me-2"></i>Presupuesto
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/presupuesto/presupuesto.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-layout-text-window me-2 text-success"></i>Partidas y Créditos
                    </a>
                    <a href="../modulos/presupuesto/importar.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-upload me-2 text-primary"></i>Importar Ejecución
                    </a>
                    <a href="../modulos/presupuesto/reporte_ejecucion.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-bar-chart me-2 text-warning"></i>Reporte Ejecución
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- FILA 2: Programas / Desembolsos / Rendiciones -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold text-white" style="background:#198754">
                    <i class="bi bi-diagram-3 me-2"></i>Programas – Desembolsos / Rendiciones / Saldos
                </div>
                <div class="row g-0">
                    <div class="col-md-4">
                        <div class="list-group list-group-flush border-end">
                            <a href="../modulos/programas/dashboard.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-bar-chart-line me-2 text-warning"></i>Dashboard / Resumen</span>
                                <span class="badge bg-warning text-dark">Dashboard</span>
                            </a>
                            <a href="../modulos/programas/bancos_listado.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-bank2 me-2 text-info"></i>Bancos Financiadores</span>
                                <span class="badge bg-info text-dark">BID / BM / CAF</span>
                            </a>
                            <a href="../modulos/programas/index.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-grid-3x3-gap me-2 text-success"></i>Listado de Programas</span>
                                <span class="badge bg-light text-dark border">Principal</span>
                            </a>
                            <?php if (can_edit()): ?>
                            <a href="../modulos/programas/programa_form.php" class="list-group-item list-group-item-action">
                                <i class="bi bi-plus-circle me-2 text-primary"></i>Nuevo Programa
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="list-group list-group-flush border-end">
                            <div class="list-group-item bg-light py-1 px-3">
                                <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                                    <i class="bi bi-arrow-down-circle me-1"></i>Desembolsos / Rendiciones
                                </small>
                            </div>
                            <a href="../modulos/programas/desembolsos_listado.php" class="list-group-item list-group-item-action">
                                <i class="bi bi-cash-coin me-2 text-info"></i>Ver Desembolsos por Programa
                            </a>
                            <?php if (can_edit()): ?>
                            <a href="../modulos/programas/desembolso_form.php" class="list-group-item list-group-item-action">
                                <i class="bi bi-plus-circle me-2 text-info"></i>Nuevo Desembolso
                            </a>
                            <?php endif; ?>
                            <a href="../modulos/programas/rendiciones_listado.php" class="list-group-item list-group-item-action">
                                <i class="bi bi-clipboard-check me-2 text-warning"></i>Ver Rendiciones por Programa
                            </a>
                            <?php if (can_edit()): ?>
                            <a href="../modulos/programas/rendicion_form.php" class="list-group-item list-group-item-action">
                                <i class="bi bi-plus-circle me-2 text-warning"></i>Nueva Rendición
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item bg-light py-1 px-3">
                                <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                                    <i class="bi bi-bank me-1"></i>Saldos / Pagos
                                </small>
                            </div>
                            <a href="../modulos/programas/saldos_listado.php" class="list-group-item list-group-item-action">
                                <i class="bi bi-bank me-2 text-primary"></i>Ver Saldos Bancarios
                            </a>
                            <?php if (can_edit()): ?>
                            <a href="../modulos/programas/saldo_form.php" class="list-group-item list-group-item-action">
                                <i class="bi bi-plus-circle me-2 text-primary"></i>Nuevo Saldo Bancario
                            </a>
                            <?php endif; ?>
                            <a href="../modulos/programas/pagos_importar.php" class="list-group-item list-group-item-action">
                                <i class="bi bi-upload me-2 text-secondary"></i>Importar Pagos (Excel/CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILA 3: ARCA / SICOPRO / Configuración -->
    <div class="row g-4">

        <?php if (tiene_acceso_modulo('arca')): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header fw-bold text-white" style="background:#6610f2">
                    <i class="bi bi-cloud-download me-2"></i>ARCA / AFIP
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/arca/facturas_listado.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-file-earmark-spreadsheet me-2 text-purple"></i>Comprobantes</span>
                        <span class="badge bg-light text-dark border">Principal</span>
                    </a>
                    <a href="../modulos/arca/arca_import.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-upload me-2 text-primary"></i>Importar CSV
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (tiene_acceso_modulo('sicopro')): ?>
        <div class="col-md-6 col-lg-5">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-info text-white fw-bold">
                    <i class="bi bi-database-fill-gear me-2"></i>SICOPRO
                </div>

                <!-- Sub-sección: Anticipos -->
                <div class="list-group-item bg-light border-0 py-1 px-3">
                    <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                        <i class="bi bi-clock-history me-1"></i>Anticipos
                    </small>
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/sicopro/listado_anticipos.php?tipo=TOTAL_ANTICIPADO" class="list-group-item list-group-item-action">
                        <i class="bi bi-table me-2 text-info"></i>Total Anticipado
                    </a>
                    <a href="../modulos/sicopro/listado_anticipos.php?tipo=SOLICITADO" class="list-group-item list-group-item-action">
                        <i class="bi bi-table me-2 text-info"></i>Solicitado No Anticipado
                    </a>
                    <a href="../modulos/sicopro/listado_anticipos.php?tipo=SIN_PAGO" class="list-group-item list-group-item-action">
                        <i class="bi bi-table me-2 text-info"></i>Anticipado Sin Pago Prov.
                    </a>
                </div>

                <!-- Sub-sección: Consultas -->
                <div class="list-group-item bg-light border-0 py-1 px-3">
                    <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                        <i class="bi bi-search me-1"></i>Consultas
                    </small>
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/sicopro/listado_sicopro.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-database me-2 text-secondary"></i>Base SICOPRO
                    </a>
                    <a href="../modulos/sicopro/listado_liquidaciones.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark-text me-2 text-secondary"></i>Listado Liquidaciones
                    </a>
                    <a href="../modulos/sicopro/listado_sigue.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark-text me-2 text-secondary"></i>Listado Sigue
                    </a>
                </div>

                <!-- Sub-sección: Contable -->
                <div class="list-group-item bg-light border-0 py-1 px-3">
                    <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                        <i class="bi bi-journal-check me-1"></i>Contable
                    </small>
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/sicopro/mayor.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-book me-2 text-dark"></i>Mayor Contable
                    </a>
                    <a href="../modulos/sicopro/importar.php" class="list-group-item list-group-item-action bg-warning-subtle">
                        <i class="bi bi-cloud-upload-fill me-2 text-primary"></i><strong>Importar Archivos</strong>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-secondary text-white fw-bold">
                    <i class="bi bi-gear-fill me-2"></i>Configuración
                </div>

                <!-- Sub-sección: Datos maestros -->
                <div class="list-group-item bg-light border-0 py-1 px-3">
                    <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                        <i class="bi bi-collection me-1"></i>Datos Maestros
                    </small>
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/obras/organismos_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-bank2 me-2 text-warning"></i>Organismos Financiadores
                    </a>
                    <a href="../modulos/obras/regiones_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-geo-alt me-2 text-success"></i>Regiones
                    </a>
                    <a href="../modulos/fuentes/fuentes_listado.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-bank me-2 text-primary"></i>Fuentes de Financiamiento
                    </a>
                </div>

                <?php if ($es_admin): ?>
                <!-- Sub-sección: Administración (solo Admin) -->
                <div class="list-group-item bg-light border-0 py-1 px-3">
                    <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                        <i class="bi bi-shield-lock me-1"></i>Administración
                    </small>
                </div>
                <div class="list-group list-group-flush">
                    <a href="../modulos/admin/usuarios.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-people me-2 text-dark"></i>Usuarios y Roles
                    </a>
                    <a href="../modulos/admin/permisos_roles.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-shield-lock me-2 text-danger"></i>Permisos por Módulo
                    </a>
                    <a href="../modulos/admin/modulos_admin.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-grid-3x3-gap me-2 text-primary"></i>Gestionar Módulos
                    </a>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>

<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("SELECT p.*, o.nombre_organismo FROM programas p JOIN organismos_financiadores o ON o.id=p.organismo_id WHERE p.id=?");
$stmt->execute([$id]);
$prog = $stmt->fetch();
if (!$prog) { echo "Programa no encontrado."; exit; }

// Cargar registros
$desembolsos = $pdo->prepare("SELECT d.*, u.nombre AS usuario_nombre, o.denominacion AS obra_nombre, o.codigo_interno AS obra_codigo FROM programa_desembolsos d LEFT JOIN usuarios u ON u.id=d.usuario_id LEFT JOIN obras o ON o.id=d.obra_id WHERE d.programa_id=? ORDER BY d.fecha DESC");
$desembolsos->execute([$id]);
$desembolsos = $desembolsos->fetchAll();

$rendiciones = $pdo->prepare("SELECT r.*, u.nombre AS usuario_nombre, o.denominacion AS obra_nombre, o.codigo_interno AS obra_codigo FROM programa_rendiciones r LEFT JOIN usuarios u ON u.id=r.usuario_id LEFT JOIN obras o ON o.id=r.obra_id WHERE r.programa_id=? ORDER BY r.fecha DESC");
$rendiciones->execute([$id]);
$rendiciones = $rendiciones->fetchAll();

$saldos = $pdo->prepare("SELECT s.*, u.nombre AS usuario_nombre, o.denominacion AS obra_nombre, o.codigo_interno AS obra_codigo,
    c.banco AS cuenta_banco, c.nro_cuenta AS cuenta_nro, c.denominacion AS cuenta_denom, c.alias AS cuenta_alias
    FROM programa_saldos s
    LEFT JOIN usuarios u ON u.id=s.usuario_id
    LEFT JOIN obras o ON o.id=s.obra_id
    LEFT JOIN programa_cuentas c ON c.id=s.cuenta_id
    WHERE s.programa_id=? ORDER BY s.fecha DESC");
$saldos->execute([$id]);
$saldos = $saldos->fetchAll();

$cuentas = $pdo->prepare("SELECT * FROM programa_cuentas WHERE programa_id=? ORDER BY activa DESC, banco, denominacion");
$cuentas->execute([$id]);
$cuentas = $cuentas->fetchAll();

// Cargar archivos agrupados por entidad
$archivos = $pdo->prepare("SELECT * FROM programa_archivos WHERE programa_id=? ORDER BY entidad_tipo, entidad_id, created_at");
$archivos->execute([$id]);
$archivos_all = $archivos->fetchAll();
$archivos_map = [];
foreach ($archivos_all as $a) {
    $archivos_map[$a['entidad_tipo']][$a['entidad_id']][] = $a;
}

function archivos_badge($map, $tipo, $eid) {
    $list = $map[$tipo][$eid] ?? [];
    if (!$list) return '';
    $count = count($list);
    $html = '<span class="badge bg-secondary ms-1" title="'. $count .' archivo(s) adjunto(s)"><i class="bi bi-paperclip"></i> '.$count.'</span>';
    return $html;
}

function fmtM($v) { return number_format((float)$v, 2, ',', '.'); }
function fmtF($f) { return $f ? date('d/m/Y', strtotime($f)) : '-'; }

$msg = $_GET['msg'] ?? '';
include __DIR__ . '/../../public/_header.php';
?>

<div class="container-fluid my-4">

    <!-- Encabezado -->
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1">
                <i class="bi bi-diagram-3 me-2 text-success"></i>
                <?= htmlspecialchars($prog['nombre']) ?>
                <span class="badge bg-success ms-2"><?= htmlspecialchars($prog['codigo']) ?></span>
            </h4>
            <small class="text-muted">
                <i class="bi bi-bank2 me-1"></i><strong>Banco:</strong> <?= htmlspecialchars($prog['nombre_organismo']) ?>
                &nbsp;|&nbsp;
                <i class="bi bi-calendar me-1"></i>
                <?= fmtF($prog['fecha_inicio']) ?> – <?= fmtF($prog['fecha_fin']) ?>
                <?php if ($prog['monto_total'] > 0): ?>
                &nbsp;|&nbsp;
                <strong>Monto: <?= $prog['moneda'] ?> <?= fmtM($prog['monto_total']) ?></strong>
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (can_edit()): ?>
            <a href="programa_form.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil me-1"></i>Editar Programa
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
            <a href="../../public/menu.php" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-house me-1"></i>Menú
            </a>
        </div>
    </div>

    <?php if ($msg === 'creado'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Programa creado correctamente.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="tabPrograma">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#tabDesembolsos">
                <i class="bi bi-arrow-down-circle me-1 text-info"></i>Desembolsos
                <span class="badge bg-info text-dark ms-1"><?= count($desembolsos) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tabRendiciones">
                <i class="bi bi-clipboard-check me-1 text-warning"></i>Rendiciones
                <span class="badge bg-warning text-dark ms-1"><?= count($rendiciones) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tabCuentas">
                <i class="bi bi-credit-card-2-front me-1 text-success"></i>Cuentas
                <span class="badge bg-success ms-1"><?= count($cuentas) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tabSaldos">
                <i class="bi bi-bank me-1 text-primary"></i>Saldos Bancarios
                <span class="badge bg-primary ms-1"><?= count($saldos) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tabPagos">
                <i class="bi bi-file-earmark-spreadsheet me-1 text-secondary"></i>Pagos Importados
            </a>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ===== TAB DESEMBOLSOS ===== -->
        <div class="tab-pane fade show active" id="tabDesembolsos">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Registro de Desembolsos</h6>
                <?php if (can_edit()): ?>
                <a href="desembolso_form.php?programa_id=<?= $id ?>" class="btn btn-info btn-sm text-white">
                    <i class="bi bi-plus-lg me-1"></i>Nuevo Desembolso
                </a>
                <?php endif; ?>
            </div>
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table id="tblDesemb" class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Obra</th>
                                <th class="text-end">Importe</th>
                                <th>Moneda</th>
                                <th>Nro. Doc.</th>
                                <th>Observaciones</th>
                                <th>Archivos</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $totalDesemb = 0; foreach ($desembolsos as $d): $totalDesemb += $d['importe']; ?>
                            <tr>
                                <td><?= fmtF($d['fecha']) ?></td>
                                <td class="small">
                                    <?php if ($d['obra_nombre']): ?>
                                    <span class="text-primary fw-semibold"><?= htmlspecialchars($d['obra_nombre']) ?></span>
                                    <?php if ($d['obra_codigo']): ?><br><small class="text-muted"><?= htmlspecialchars($d['obra_codigo']) ?></small><?php endif; ?>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td class="text-end font-monospace fw-bold"><?= fmtM($d['importe']) ?></td>
                                <td><span class="badge bg-secondary"><?= $d['moneda'] ?></span></td>
                                <td class="small font-monospace"><?= htmlspecialchars($d['numero_documento'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($d['observaciones'] ?? '') ?></td>
                                <td>
                                    <?= archivos_badge($archivos_map, 'DESEMBOLSO', $d['id']) ?>
                                    <?php if (can_edit()): ?>
                                    <a href="archivo_upload.php?tipo=DESEMBOLSO&entidad_id=<?= $d['id'] ?>&programa_id=<?= $id ?>"
                                       class="btn btn-outline-secondary btn-sm py-0 px-1" title="Subir archivo">
                                        <i class="bi bi-upload" style="font-size:.75rem"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php foreach ($archivos_map['DESEMBOLSO'][$d['id']] ?? [] as $a): ?>
                                    <a href="../../uploads/programas/<?= $a['nombre_guardado'] ?>"
                                       class="badge bg-light text-dark border text-decoration-none" target="_blank"
                                       title="<?= htmlspecialchars($a['nombre_original']) ?>">
                                        <i class="bi bi-file-earmark"></i> <?= htmlspecialchars(mb_strimwidth($a['nombre_original'], 0, 20, '…')) ?>
                                    </a>
                                    <?php if (can_delete()): ?>
                                    <a href="archivo_eliminar.php?id=<?= $a['id'] ?>&back=<?= urlencode("programa_ver.php?id=$id#tabDesembolsos") ?>"
                                       onclick="return confirm('¿Eliminar archivo?')" class="text-danger ms-1" style="font-size:.75rem">✕</a>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (can_edit()): ?>
                                    <a href="desembolso_form.php?id=<?= $d['id'] ?>&programa_id=<?= $id ?>" class="btn btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <?php if (can_delete()): ?>
                                    <a href="desembolso_eliminar.php?id=<?= $d['id'] ?>&programa_id=<?= $id ?>"
                                       class="btn btn-outline-danger btn-sm py-0 px-1"
                                       onclick="return confirm('¿Eliminar desembolso?')"><i class="bi bi-trash"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($desembolsos): ?>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2">TOTAL</td>
                                <td class="text-end font-monospace text-success"><?= fmtM($totalDesemb) ?></td>
                                <td colspan="5"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===== TAB RENDICIONES ===== -->
        <div class="tab-pane fade" id="tabRendiciones">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Registro de Rendiciones</h6>
                <?php if (can_edit()): ?>
                <a href="rendicion_form.php?programa_id=<?= $id ?>" class="btn btn-warning btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Nueva Rendición
                </a>
                <?php endif; ?>
            </div>
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table id="tblRend" class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Obra</th>
                                <th class="text-end">Imp. USD</th>
                                <th class="text-end">Imp. Pesos</th>
                                <th class="text-end">Fuente Externa</th>
                                <th class="text-end">Contraparte</th>
                                <th>Nro. Doc.</th>
                                <th>Observaciones</th>
                                <th>Archivos</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $tUsd=0;$tPesos=0;$tFE=0;$tCP=0;
                            foreach ($rendiciones as $r):
                                $tUsd+=$r['importe_usd'];$tPesos+=$r['importe_pesos'];
                                $tFE+=$r['total_fuente_externa'];$tCP+=$r['total_contraparte'];
                            ?>
                            <tr>
                                <td><?= fmtF($r['fecha']) ?></td>
                                <td class="small">
                                    <?php if ($r['obra_nombre']): ?>
                                    <span class="text-primary fw-semibold"><?= htmlspecialchars($r['obra_nombre']) ?></span>
                                    <?php if ($r['obra_codigo']): ?><br><small class="text-muted"><?= htmlspecialchars($r['obra_codigo']) ?></small><?php endif; ?>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td class="text-end font-monospace"><?= fmtM($r['importe_usd']) ?></td>
                                <td class="text-end font-monospace"><?= fmtM($r['importe_pesos']) ?></td>
                                <td class="text-end font-monospace text-primary"><?= fmtM($r['total_fuente_externa']) ?></td>
                                <td class="text-end font-monospace text-secondary"><?= fmtM($r['total_contraparte']) ?></td>
                                <td class="small font-monospace"><?= htmlspecialchars($r['numero_documento'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($r['observaciones'] ?? '') ?></td>
                                <td>
                                    <?php if (can_edit()): ?>
                                    <a href="archivo_upload.php?tipo=RENDICION&entidad_id=<?= $r['id'] ?>&programa_id=<?= $id ?>"
                                       class="btn btn-outline-secondary btn-sm py-0 px-1" title="Subir archivo">
                                        <i class="bi bi-upload" style="font-size:.75rem"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php foreach ($archivos_map['RENDICION'][$r['id']] ?? [] as $a): ?>
                                    <a href="../../uploads/programas/<?= $a['nombre_guardado'] ?>"
                                       class="badge bg-light text-dark border text-decoration-none" target="_blank"
                                       title="<?= htmlspecialchars($a['nombre_original']) ?>">
                                        <i class="bi bi-file-earmark"></i> <?= htmlspecialchars(mb_strimwidth($a['nombre_original'], 0, 20, '…')) ?>
                                    </a>
                                    <?php if (can_delete()): ?>
                                    <a href="archivo_eliminar.php?id=<?= $a['id'] ?>&back=<?= urlencode("programa_ver.php?id=$id#tabRendiciones") ?>"
                                       onclick="return confirm('¿Eliminar archivo?')" class="text-danger ms-1" style="font-size:.75rem">✕</a>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (can_edit()): ?>
                                    <a href="rendicion_form.php?id=<?= $r['id'] ?>&programa_id=<?= $id ?>" class="btn btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <?php if (can_delete()): ?>
                                    <a href="rendicion_eliminar.php?id=<?= $r['id'] ?>&programa_id=<?= $id ?>"
                                       class="btn btn-outline-danger btn-sm py-0 px-1"
                                       onclick="return confirm('¿Eliminar rendición?')"><i class="bi bi-trash"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($rendiciones): ?>
                        <tfoot class="table-light fw-bold small">
                            <tr>
                                <td colspan="2">TOTALES</td>
                                <td class="text-end font-monospace"><?= fmtM($tUsd) ?></td>
                                <td class="text-end font-monospace"><?= fmtM($tPesos) ?></td>
                                <td class="text-end font-monospace text-primary"><?= fmtM($tFE) ?></td>
                                <td class="text-end font-monospace text-secondary"><?= fmtM($tCP) ?></td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===== TAB CUENTAS BANCARIAS ===== -->
        <div class="tab-pane fade" id="tabCuentas">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Cuentas Bancarias del Programa</h6>
                <?php if (can_edit()): ?>
                <a href="cuenta_form.php?programa_id=<?= $id ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Nueva Cuenta
                </a>
                <?php endif; ?>
            </div>
            <?php if (($_GET['err'] ?? '') === 'tiene_saldos'): ?>
            <div class="alert alert-warning py-2 small">No se puede eliminar una cuenta que tiene saldos cargados.</div>
            <?php endif; ?>
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table id="tblCuentas" class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Banco</th>
                                <th>Denominación</th>
                                <th>Nro. Cuenta</th>
                                <th>CBU</th>
                                <th>Alias</th>
                                <th>Servicio Administrativo</th>
                                <th>Moneda</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cuentas as $c): ?>
                            <tr class="<?= $c['activa'] ? '' : 'text-muted' ?>">
                                <td class="fw-semibold"><?= htmlspecialchars($c['banco']) ?></td>
                                <td class="small"><?= htmlspecialchars($c['denominacion'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars($c['nro_cuenta'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars($c['cbu'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="small"><?= htmlspecialchars($c['alias'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="small"><?= htmlspecialchars($c['servicio_administrativo'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td><span class="badge bg-secondary"><?= $c['moneda'] ?></span></td>
                                <td class="text-center">
                                    <?php if ($c['activa']): ?>
                                    <span class="badge bg-success">Activa</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (can_edit()): ?>
                                    <a href="cuenta_form.php?id=<?= $c['id'] ?>&programa_id=<?= $id ?>" class="btn btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <?php if (can_delete()): ?>
                                    <a href="cuenta_eliminar.php?id=<?= $c['id'] ?>&programa_id=<?= $id ?>"
                                       class="btn btn-outline-danger btn-sm py-0 px-1"
                                       onclick="return confirm('¿Eliminar cuenta?')"><i class="bi bi-trash"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$cuentas): ?>
                            <tr><td colspan="9" class="text-center text-muted py-3">
                                No hay cuentas registradas para este programa.
                                <?php if (can_edit()): ?>
                                <a href="cuenta_form.php?programa_id=<?= $id ?>" class="ms-2">Crear la primera</a>
                                <?php endif; ?>
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===== TAB SALDOS BANCARIOS ===== -->
        <div class="tab-pane fade" id="tabSaldos">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Saldos Bancarios Periódicos</h6>
                <?php if (can_edit()): ?>
                <a href="saldo_form.php?programa_id=<?= $id ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Nuevo Saldo
                </a>
                <?php endif; ?>
            </div>
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table id="tblSaldos" class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Banco / Cuenta</th>
                                <th class="text-end">Saldo Moneda Ext.</th>
                                <th>Moneda</th>
                                <th class="text-end">Saldo Pesos</th>
                                <th>Nro. Extracto</th>
                                <th>Observaciones</th>
                                <th>Archivos</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($saldos as $s): ?>
                            <tr>
                                <td><?= fmtF($s['fecha']) ?></td>
                                <td class="small">
                                    <?php
                                    $bancoMostrar = $s['cuenta_banco'] ?? $s['banco'] ?? '';
                                    $cuentaMostrar = $s['cuenta_nro'] ?? $s['cuenta'] ?? '';
                                    $denomMostrar = $s['cuenta_denom'] ?? '';
                                    ?>
                                    <?= htmlspecialchars($bancoMostrar) ?>
                                    <?php if ($denomMostrar): ?><br><span class="text-muted"><?= htmlspecialchars($denomMostrar) ?></span><?php endif; ?>
                                    <?php if ($cuentaMostrar): ?><br><span class="text-muted font-monospace"><?= htmlspecialchars($cuentaMostrar) ?></span><?php endif; ?>
                                </td>
                                <td class="text-end font-monospace fw-bold text-info"><?= fmtM($s['saldo_moneda_extranjera']) ?></td>
                                <td><span class="badge bg-secondary"><?= $s['moneda_extranjera'] ?></span></td>
                                <td class="text-end font-monospace fw-bold text-success"><?= fmtM($s['saldo_moneda_nacional']) ?></td>
                                <td class="small font-monospace"><?= htmlspecialchars($s['numero_extracto'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($s['observaciones'] ?? '') ?></td>
                                <td>
                                    <?php if (can_edit()): ?>
                                    <a href="archivo_upload.php?tipo=SALDO&entidad_id=<?= $s['id'] ?>&programa_id=<?= $id ?>"
                                       class="btn btn-outline-secondary btn-sm py-0 px-1" title="Subir archivo">
                                        <i class="bi bi-upload" style="font-size:.75rem"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php foreach ($archivos_map['SALDO'][$s['id']] ?? [] as $a): ?>
                                    <a href="../../uploads/programas/<?= $a['nombre_guardado'] ?>"
                                       class="badge bg-light text-dark border text-decoration-none" target="_blank"
                                       title="<?= htmlspecialchars($a['nombre_original']) ?>">
                                        <i class="bi bi-file-earmark"></i> <?= htmlspecialchars(mb_strimwidth($a['nombre_original'], 0, 20, '…')) ?>
                                    </a>
                                    <?php if (can_delete()): ?>
                                    <a href="archivo_eliminar.php?id=<?= $a['id'] ?>&back=<?= urlencode("programa_ver.php?id=$id#tabSaldos") ?>"
                                       onclick="return confirm('¿Eliminar archivo?')" class="text-danger ms-1" style="font-size:.75rem">✕</a>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (can_edit()): ?>
                                    <a href="saldo_form.php?id=<?= $s['id'] ?>&programa_id=<?= $id ?>" class="btn btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <?php if (can_delete()): ?>
                                    <a href="saldo_eliminar.php?id=<?= $s['id'] ?>&programa_id=<?= $id ?>"
                                       class="btn btn-outline-danger btn-sm py-0 px-1"
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

        <!-- ===== TAB PAGOS IMPORTADOS ===== -->
        <div class="tab-pane fade" id="tabPagos">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Pagos Importados desde Excel/CSV</h6>
                <?php if (can_edit()): ?>
                <a href="pagos_importar.php?programa_id=<?= $id ?>" class="btn btn-secondary btn-sm">
                    <i class="bi bi-upload me-1"></i>Importar Archivo
                </a>
                <?php endif; ?>
            </div>

            <?php
            // Lotes importados
            $lotes = $pdo->prepare("SELECT lote_id, MIN(import_fecha) AS import_fecha, COUNT(*) AS total FROM programa_pagos_importados WHERE programa_id=? GROUP BY lote_id ORDER BY import_fecha DESC");
            $lotes->execute([$id]);
            $lotes = $lotes->fetchAll();
            if ($lotes):
            ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php foreach ($lotes as $lt): ?>
                <div class="border rounded px-3 py-2 bg-light d-flex align-items-center gap-2 small">
                    <i class="bi bi-file-earmark-spreadsheet text-secondary"></i>
                    <span>Lote <code><?= substr($lt['lote_id'], 5, 8) ?></code></span>
                    <span class="text-muted"><?= date('d/m/Y H:i', strtotime($lt['import_fecha'])) ?></span>
                    <span class="badge bg-secondary"><?= $lt['total'] ?> filas</span>
                    <?php if (can_delete()): ?>
                    <a href="pagos_lote_eliminar.php?lote_id=<?= urlencode($lt['lote_id']) ?>&programa_id=<?= $id ?>"
                       class="btn btn-outline-danger btn-sm py-0 px-1" title="Eliminar este lote"
                       onclick="return confirm('¿Eliminar las <?= $lt['total'] ?> filas de este lote?')">
                        <i class="bi bi-trash" style="font-size:.75rem"></i> Eliminar lote
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
            $pagos = $pdo->prepare("SELECT id, lote_id, col_fecha, col_concepto, col_importe, col_moneda, col_referencia, datos_extra, import_fecha FROM programa_pagos_importados WHERE programa_id=? ORDER BY lote_id, fila");
            $pagos->execute([$id]);
            $pagos = $pagos->fetchAll();

            // Helpers compartidos (tambien usados en la tabla)
            // Normaliza: minusculas + quita acentos + deja solo a-z 0-9 (elimina espacios, _, ., -, NBSP, BOM, etc)
            $normKey = function(string $k): string {
                $k = strtolower(trim($k));
                $k = strtr($k, [
                    'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                    'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n',
                ]);
                return preg_replace('/[^a-z0-9]+/', '', $k);
            };
            $parseImp = function(string $v): ?float {
                $s = trim($v);
                if ($s === '') return null;
                if (preg_match('/,\d{1,2}$/', $s)) {
                    $s = str_replace('.', '', $s);
                    $s = str_replace(',', '.', $s);
                } else {
                    $s = str_replace(',', '', $s);
                }
                return is_numeric($s) ? (float)$s : null;
            };
            $parseFechaExcel = function(string $v): ?int {
                $v = trim($v);
                if ($v === '') return null;
                if (preg_match('/^\d{4,6}(\.\d+)?$/', $v)) {
                    $n = (float)$v;
                    if ($n >= 18000 && $n <= 80000) return (int)(($n - 25569) * 86400);
                }
                $ts = strtotime($v);
                return $ts ?: null;
            };

            // Calcular totales, última AP, desglose por fuente y por AP/fufi
            $totalDivisa = 0.0;
            $ultimaAP = null;
            $ultimaAPFecha = null;
            $porFuente = [];       // [fuente => total_divisa]
            $porAP = [];           // [ap_desc => ['fufis' => [fufi => total], 'total' => total]]
            foreach ($pagos as $p) {
                $ex = json_decode($p['datos_extra'] ?? '{}', true) ?: [];
                $idx = [];
                foreach ($ex as $k => $v) { $idx[$normKey($k)] = (string)$v; }

                $imp = $parseImp($idx[$normKey('Importe_Divisa')] ?? '');
                if ($imp !== null) $totalDivisa += $imp;

                // Desglose por Fuente
                if ($imp !== null) {
                    $fuente = trim($idx[$normKey('Fuente')] ?? '') ?: '(sin fuente)';
                    $porFuente[$fuente] = ($porFuente[$fuente] ?? 0) + $imp;
                }

                // Desglose por AP Descripción + Fuente
                if ($imp !== null) {
                    $apDesc   = trim($idx[$normKey('AP_Descripcion')] ?? '') ?: '(sin AP)';
                    $fuenteAP = trim($idx[$normKey('Fuente')] ?? '') ?: '(sin fuente)';
                    if (!isset($porAP[$apDesc])) $porAP[$apDesc] = ['fuentes' => [], 'total' => 0.0];
                    $porAP[$apDesc]['fuentes'][$fuenteAP] = ($porAP[$apDesc]['fuentes'][$fuenteAP] ?? 0) + $imp;
                    $porAP[$apDesc]['total'] += $imp;
                }

                // Última AP (por Codigo_Habilitado)
                $ap = trim($idx[$normKey('Codigo_Habilitado')] ?? '');
                $fechaRaw = $idx[$normKey('Fecha_Retiro_Pago')] ?? ($idx[$normKey('Fecha_de_Pesificacion')] ?? ($p['col_fecha'] ?? ''));
                $ts = $parseFechaExcel((string)$fechaRaw);
                if ($ap !== '' && $ts !== null && ($ultimaAPFecha === null || $ts > $ultimaAPFecha)) {
                    $ultimaAPFecha = $ts;
                    $ultimaAP = $ap;
                }
            }
            // Ordenar desgloses por total descendente
            arsort($porFuente);
            uasort($porAP, fn($a, $b) => $b['total'] <=> $a['total']);
            ?>

            <!-- Resumen de pagos -->
            <div class="row g-2 mb-3">
                <div class="col-md-5">
                    <div class="card shadow-sm border-start border-success border-4 h-100">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="text-muted small text-uppercase fw-bold">Total Importe Divisa</div>
                                    <div class="fw-bold fs-5 text-success font-monospace">
                                        <?= number_format($totalDivisa, 2, ',', '.') ?>
                                    </div>
                                </div>
                                <small class="text-muted"><?= count($pagos) ?> pagos</small>
                            </div>
                            <?php if (!empty($porFuente)): ?>
                            <hr class="my-2">
                            <div class="small">
                                <div class="text-muted text-uppercase fw-bold mb-1" style="font-size:.65rem">Desglose por fuente</div>
                                <?php foreach ($porFuente as $fuente => $tot): ?>
                                <div class="d-flex justify-content-between">
                                    <span class="text-truncate" style="max-width:60%" title="<?= htmlspecialchars($fuente) ?>"><?= htmlspecialchars($fuente) ?></span>
                                    <span class="font-monospace fw-semibold"><?= number_format($tot, 2, ',', '.') ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-start border-primary border-4 h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small text-uppercase fw-bold">Última AP</div>
                            <div class="fw-bold text-primary" style="word-break:break-word">
                                <?= $ultimaAP ? htmlspecialchars($ultimaAP) : '<span class="text-muted fw-normal">Sin datos</span>' ?>
                            </div>
                            <?php if ($ultimaAPFecha): ?>
                            <small class="text-muted"><i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y', $ultimaAPFecha) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card shadow-sm border-start border-warning border-4 h-100">
                        <div class="card-body py-2 px-3 d-flex flex-column justify-content-center">
                            <div class="text-muted small text-uppercase fw-bold mb-2">Totales por AP</div>
                            <button type="button" class="btn btn-warning btn-sm fw-semibold"
                                    data-bs-toggle="modal" data-bs-target="#modalTotalesAP">
                                <i class="bi bi-table me-1"></i>Ver totales AP / Fuente
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: Totales por AP Descripción separados por FUFI -->
            <div class="modal fade" id="modalTotalesAP" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title fw-bold">
                                <i class="bi bi-calculator me-2"></i>Totales por AP Descripción / Fuente (Importe Divisa)
                            </h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-2">
                            <table class="table table-sm table-bordered small mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>AP Descripción</th>
                                        <th>Fuente</th>
                                        <th class="text-end">Importe Divisa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($porAP as $apDesc => $info): ?>
                                        <?php $rowspan = count($info['fuentes']); $first = true; ?>
                                        <?php foreach ($info['fuentes'] as $fuenteAP => $tot): ?>
                                        <tr>
                                            <?php if ($first): ?>
                                            <td rowspan="<?= $rowspan ?>" class="align-middle fw-semibold" style="word-break:break-word">
                                                <?= htmlspecialchars($apDesc) ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="small"><?= htmlspecialchars($fuenteAP) ?></td>
                                            <td class="text-end font-monospace"><?= number_format($tot, 2, ',', '.') ?></td>
                                        </tr>
                                        <?php $first = false; endforeach; ?>
                                        <tr class="table-light">
                                            <td colspan="2" class="text-end fw-bold">Subtotal <?= htmlspecialchars($apDesc) ?></td>
                                            <td class="text-end font-monospace fw-bold text-primary"><?= number_format($info['total'], 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-success">
                                        <td colspan="2" class="text-end fw-bold">TOTAL GENERAL</td>
                                        <td class="text-end font-monospace fw-bold fs-6"><?= number_format($totalDivisa, 2, ',', '.') ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm" style="max-width:100%;overflow:hidden">
                <div class="card-body p-0" style="max-width:100%;overflow-x:auto">
                    <table id="tblPagos" class="table table-hover table-sm mb-0" style="font-size:.8rem;width:100%">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-nowrap">Fecha</th>
                                <th class="text-nowrap">Cod.Hab.</th>
                                <th class="text-nowrap">Nombre</th>
                                <th class="text-nowrap">CUIT</th>
                                <th class="text-nowrap text-end">Imp.Pagado</th>
                                <th class="text-nowrap">AP Descripción</th>
                                <th class="text-nowrap">Descripción</th>
                                <th class="text-nowrap">Tema</th>
                                <th class="text-nowrap">Insumo</th>
                                <th class="text-nowrap text-end">Imp.Divisa</th>
                                <th class="text-nowrap text-end">Imp.PNUD</th>
                                <th class="text-nowrap">F.Pesificación</th>
                                <th class="text-nowrap">F.Retiro</th>
                                <th class="text-nowrap text-center">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // $normKey ya esta definido arriba (helpers compartidos)
                            // Formatea fechas: soporta serial de Excel (ej: 45658 → 01/01/2025) y strings de fecha
                            $fmtFecha = function(string $v): string {
                                $v = trim($v);
                                if ($v === '') return '';
                                // Serial numérico de Excel: rango razonable para fechas (1950-2100)
                                if (preg_match('/^\d{4,6}(\.\d+)?$/', $v)) {
                                    $n = (float)$v;
                                    if ($n >= 18000 && $n <= 80000) {
                                        // Excel cuenta días desde 1900-01-01 con bug del bisiesto. 25569 = epoch UNIX
                                        $ts = ($n - 25569) * 86400;
                                        return date('d/m/Y', (int)$ts);
                                    }
                                }
                                // Si ya es fecha con formato, devolver tal cual (escapado)
                                return htmlspecialchars($v);
                            };
                            // Formatea importe al estilo argentino: miles con "." y decimales con ","
                            $fmtImp = function(string $v): string {
                                if ($v === '' || $v === null) return '';
                                // Limpiar: quitar separadores de miles y unificar decimal
                                $s = trim($v);
                                // Si tiene coma como decimal (formato AR): quitar puntos y cambiar coma por punto
                                if (preg_match('/,\d{1,2}$/', $s)) {
                                    $s = str_replace('.', '', $s);
                                    $s = str_replace(',', '.', $s);
                                } else {
                                    // Formato con punto decimal o sin decimales: solo quitar comas de miles
                                    $s = str_replace(',', '', $s);
                                }
                                if (!is_numeric($s)) return htmlspecialchars($v);
                                return number_format((float)$s, 2, ',', '.');
                            };
                            ?>
                            <?php foreach ($pagos as $p):
                                $ex = json_decode($p['datos_extra'] ?? '{}', true) ?: [];
                                $exIdx = [];
                                foreach ($ex as $k => $v) { $exIdx[$normKey($k)] = (string)$v; }
                                $esc = fn($k) => htmlspecialchars($exIdx[$normKey($k)] ?? '');
                                $escImp = fn($k) => $fmtImp($exIdx[$normKey($k)] ?? '');
                                $escFecha = fn($k) => $fmtFecha($exIdx[$normKey($k)] ?? '');
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= htmlspecialchars($p['col_fecha'] ?? '') ?></td>
                                <td class="text-nowrap"><?= $esc('Codigo_Habilitado') ?></td>
                                <td><?= $esc('Nombre') ?></td>
                                <td class="text-nowrap font-monospace small"><?= $esc('Cuit') ?></td>
                                <td class="text-end font-monospace fw-bold"><?= $escImp('Importe_Pagado') ?></td>
                                <td class="small"><?= $esc('AP_Descripcion') ?></td>
                                <td class="small"><?= $esc('Descripcion') ?></td>
                                <td class="small"><?= $esc('Tema') ?></td>
                                <td class="small"><?= $esc('Insumo_Descripcion') ?: $esc('Insumo') ?></td>
                                <td class="text-end font-monospace"><?= $escImp('Importe_Divisa') ?></td>
                                <td class="text-end font-monospace"><?= $escImp('ImportePNUD') ?></td>
                                <td class="text-nowrap"><?= $escFecha('Fecha_de_Pesificacion') ?></td>
                                <td class="text-nowrap"><?= $escFecha('Fecha_Retiro_Pago') ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-info btn-sm py-0 px-1 btn-detalle"
                                            data-extra="<?= htmlspecialchars($p['datos_extra'] ?? '{}') ?>"
                                            title="Ver todas las columnas">
                                        <i class="bi bi-table"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal detalle pago -->
        <div class="modal fade" id="modalDetallePago" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title fw-bold"><i class="bi bi-table me-2"></i>Detalle completo del pago</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-2">
                        <table class="table table-sm table-bordered small mb-0" id="tblModalDetalle">
                            <thead class="table-dark"><tr><th style="width:35%">Campo</th><th>Valor</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script>
$(function(){
    var dtOpts = {
        language: { url: '//cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
        pageLength: 25,
        dom: '<"d-flex justify-content-between"lf>t<"d-flex justify-content-between"ip>'
    };
    $('#tblDesemb').DataTable(Object.assign({}, dtOpts, { order:[[0,'desc']], columnDefs:[{orderable:false,targets:[5,6]}] }));
    $('#tblRend').DataTable(Object.assign({}, dtOpts, { order:[[0,'desc']], columnDefs:[{orderable:false,targets:[7,8]}] }));
    $('#tblSaldos').DataTable(Object.assign({}, dtOpts, { order:[[0,'desc']], columnDefs:[{orderable:false,targets:[6,7]}] }));
    var dtPagos = $('#tblPagos').DataTable(Object.assign({}, dtOpts, {
        order:[[0,'desc']],
        scrollX: true,
        columnDefs:[{orderable:false, targets:[13]}],
        buttons:[{
            extend: 'excelHtml5',
            text: '<i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel',
            className: 'btn btn-success btn-sm',
            title: 'Pagos Importados',
            exportOptions: { columns: ':not(:last-child)' }
        }],
        dom: '<"d-flex justify-content-between align-items-center mb-2"Bf>t<"d-flex justify-content-between"ip>'
    }));

    // Recalcular anchos de la tabla de Pagos al mostrar su tab (evita desalineación con scrollX)
    $('a[href="#tabPagos"]').on('shown.bs.tab', function(){
        dtPagos.columns.adjust();
    });
    // Si se carga directo con #tabPagos en el hash, ajustar también
    if (window.location.hash === '#tabPagos') {
        setTimeout(function(){ dtPagos.columns.adjust(); }, 100);
    }

    // Helpers de formato para el modal de detalle
    function normalizeKey(k){
        return (k || '').toString().toLowerCase()
            .replace(/[áä]/g,'a').replace(/[éë]/g,'e').replace(/[íï]/g,'i')
            .replace(/[óö]/g,'o').replace(/[úü]/g,'u').replace(/ñ/g,'n')
            .replace(/[^a-z0-9]+/g,'');
    }
    function fmtImporteAR(v){
        var s = (v == null ? '' : String(v)).trim();
        if (s === '') return '';
        if (/,\d{1,2}$/.test(s)) { s = s.replace(/\./g,'').replace(',', '.'); }
        else { s = s.replace(/,/g,''); }
        var n = parseFloat(s);
        if (isNaN(n)) return null;
        return n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtFechaExcel(v){
        var s = (v == null ? '' : String(v)).trim();
        if (s === '') return '';
        if (/^\d{4,6}(\.\d+)?$/.test(s)) {
            var n = parseFloat(s);
            if (n >= 18000 && n <= 80000) {
                var ms = (n - 25569) * 86400 * 1000;
                var d = new Date(ms);
                var dd = String(d.getUTCDate()).padStart(2,'0');
                var mm = String(d.getUTCMonth()+1).padStart(2,'0');
                var yy = d.getUTCFullYear();
                return dd + '/' + mm + '/' + yy;
            }
        }
        return null;
    }
    // Claves que se tratan como importe/fecha
    var KEYS_IMPORTE = ['importepagado','importedivisa','importepnud','importe'];
    var KEYS_FECHA   = ['fechadepesificacion','fecharetiropago','fecha','fechapago','docrfecha'];

    // Modal detalle pago
    $(document).on('click', '.btn-detalle', function(){
        var raw = $(this).attr('data-extra') || '{}';
        var extra = {};
        try { extra = JSON.parse(raw); } catch(e){ console.error('JSON parse error', e, raw.substring(0,200)); }
        var tbody = $('#tblModalDetalle tbody').empty();
        var count = 0;
        $.each(extra, function(k, v){
            count++;
            var isEmpty = (v === '' || v === null);
            var display = v;
            var cellClass = 'small';
            if (!isEmpty) {
                var nk = normalizeKey(k);
                // Intentar formato de fecha si la clave sugiere fecha
                if (KEYS_FECHA.indexOf(nk) !== -1 || nk.indexOf('fecha') !== -1) {
                    var f = fmtFechaExcel(v);
                    if (f !== null) { display = f; cellClass += ' text-nowrap'; }
                }
                // Intentar formato de importe si la clave sugiere importe
                else if (KEYS_IMPORTE.indexOf(nk) !== -1 || nk.indexOf('importe') !== -1) {
                    var imp = fmtImporteAR(v);
                    if (imp !== null) { display = imp; cellClass += ' text-end font-monospace'; }
                }
            }
            tbody.append(
                '<tr class="' + (isEmpty ? 'text-muted' : '') + '">' +
                '<td class="fw-semibold text-nowrap small">' + $('<span>').text(k).html() + '</td>' +
                '<td class="' + cellClass + '">' + (isEmpty ? '<em>vacío</em>' : $('<span>').text(display).html()) + '</td>' +
                '</tr>'
            );
        });
        if (!count) tbody.append('<tr><td colspan="2" class="text-danger fw-bold">datos_extra vacío o NULL — reimportar el archivo</td></tr>');
        new bootstrap.Modal(document.getElementById('modalDetallePago')).show();
    });

    // Activar tab desde hash URL
    var hash = window.location.hash;
    if (hash) {
        $('a[href="' + hash + '"]').tab('show');
    }
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

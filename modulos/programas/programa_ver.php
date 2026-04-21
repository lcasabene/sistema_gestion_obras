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

$saldos = $pdo->prepare("SELECT s.*, u.nombre AS usuario_nombre, o.denominacion AS obra_nombre, o.codigo_interno AS obra_codigo FROM programa_saldos s LEFT JOIN usuarios u ON u.id=s.usuario_id LEFT JOIN obras o ON o.id=s.obra_id WHERE s.programa_id=? ORDER BY s.fecha DESC");
$saldos->execute([$id]);
$saldos = $saldos->fetchAll();

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
                                <td colspan="4"></td>
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
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
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
                                <th>Obra</th>
                                <th>Banco / Cuenta</th>
                                <th class="text-end">Saldo Moneda Ext.</th>
                                <th>Moneda</th>
                                <th class="text-end">Saldo Pesos</th>
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
                                    <?php if ($s['obra_nombre']): ?>
                                    <span class="text-primary fw-semibold"><?= htmlspecialchars($s['obra_nombre']) ?></span>
                                    <?php if ($s['obra_codigo']): ?><br><small class="text-muted"><?= htmlspecialchars($s['obra_codigo']) ?></small><?php endif; ?>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td class="small">
                                    <?= htmlspecialchars($s['banco'] ?? '') ?>
                                    <?php if ($s['cuenta']): ?><br><span class="text-muted"><?= htmlspecialchars($s['cuenta']) ?></span><?php endif; ?>
                                </td>
                                <td class="text-end font-monospace fw-bold text-info"><?= fmtM($s['saldo_moneda_extranjera']) ?></td>
                                <td><span class="badge bg-secondary"><?= $s['moneda_extranjera'] ?></span></td>
                                <td class="text-end font-monospace fw-bold text-success"><?= fmtM($s['saldo_moneda_nacional']) ?></td>
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

            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <?php
                    $pagos = $pdo->prepare("SELECT id, lote_id, col_fecha, col_concepto, col_importe, col_moneda, col_referencia, datos_extra, import_fecha FROM programa_pagos_importados WHERE programa_id=? ORDER BY lote_id, fila");
                    $pagos->execute([$id]);
                    $pagos = $pagos->fetchAll();
                    ?>
                    <table id="tblPagos" class="table table-hover table-sm mb-0" style="font-size:.8rem">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-nowrap">Fecha</th>
                                <th class="text-nowrap">Cod.Hab.</th>
                                <th class="text-nowrap">R.Letra</th>
                                <th class="text-nowrap">R.Suc.</th>
                                <th class="text-nowrap">Nombre</th>
                                <th class="text-nowrap">CUIT</th>
                                <th class="text-nowrap text-end">Imp.Pagado</th>
                                <th class="text-nowrap">AP Descripción</th>
                                <th class="text-nowrap">Descripción</th>
                                <th class="text-nowrap text-end">Imp.Divisa</th>
                                <th class="text-nowrap text-end">Imp.PNUD</th>
                                <th class="text-nowrap">F.Pesificación</th>
                                <th class="text-nowrap">F.Retiro</th>
                                <th class="text-nowrap">Estado Rendido</th>
                                <th class="text-nowrap text-center">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagos as $p):
                                $ex = json_decode($p['datos_extra'] ?? '{}', true) ?: [];
                                // Índice case-insensitive sin espacios para tolerar variaciones de cabecera
                                $exIdx = [];
                                foreach ($ex as $k => $v) { $exIdx[strtolower(trim($k))] = (string)$v; }
                                $esc = fn($k) => htmlspecialchars($exIdx[strtolower(trim($k))] ?? '');
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= htmlspecialchars($p['col_fecha'] ?? '') ?></td>
                                <td class="text-nowrap"><?= $esc('Codigo_Habilitado') ?></td>
                                <td><?= $esc('Doc_R_Letra') ?></td>
                                <td><?= $esc('Doc_R_Sucursal') ?></td>
                                <td><?= $esc('Nombre') ?></td>
                                <td class="text-nowrap font-monospace small"><?= $esc('Cuit') ?></td>
                                <td class="text-end font-monospace fw-bold"><?= $esc('Importe_Pagado') ?></td>
                                <td class="small"><?= $esc('AP_Descripcion') ?></td>
                                <td class="small"><?= $esc('Descripcion') ?></td>
                                <td class="text-end font-monospace"><?= $esc('Importe_Divisa') ?></td>
                                <td class="text-end font-monospace"><?= $esc('ImportePNUD') ?></td>
                                <td class="text-nowrap"><?= $esc('Fecha_de_Pesificacion') ?></td>
                                <td class="text-nowrap"><?= $esc('Fecha_Retiro_Pago') ?></td>
                                <td><?= $esc('Estado_Rendido') ?></td>
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
    $('#tblDesemb').DataTable(Object.assign({}, dtOpts, { order:[[0,'desc']], columnDefs:[{orderable:false,targets:[4,5]}] }));
    $('#tblRend').DataTable(Object.assign({}, dtOpts, { order:[[0,'desc']], columnDefs:[{orderable:false,targets:[6,7]}] }));
    $('#tblSaldos').DataTable(Object.assign({}, dtOpts, { order:[[0,'desc']], columnDefs:[{orderable:false,targets:[6,7]}] }));
    $('#tblPagos').DataTable(Object.assign({}, dtOpts, {
        order:[[0,'desc']],
        scrollX: true,
        columnDefs:[{orderable:false, targets:[14]}],
        buttons:[{
            extend: 'excelHtml5',
            text: '<i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel',
            className: 'btn btn-success btn-sm',
            title: 'Pagos Importados',
            exportOptions: { columns: ':not(:last-child)' }
        }],
        dom: '<"d-flex justify-content-between align-items-center mb-2"Bf>t<"d-flex justify-content-between"ip>'
    }));

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
            tbody.append(
                '<tr class="' + (isEmpty ? 'text-muted' : '') + '">' +
                '<td class="fw-semibold text-nowrap small">' + $('<span>').text(k).html() + '</td>' +
                '<td class="small">' + (isEmpty ? '<em>vacío</em>' : $('<span>').text(v).html()) + '</td>' +
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

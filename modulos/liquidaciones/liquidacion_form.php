<?php
// modulos/liquidaciones/liquidacion_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/includes/Rg830Engine.php';
include __DIR__ . '/../../public/_header.php';

$id = (int)($_GET['id'] ?? 0);
$mensaje = '';
$tipo_alerta = '';
$resultado = null;
$liquidacion = null;

// Cargar liquidación existente
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM liquidaciones WHERE id = ?");
    $stmt->execute([$id]);
    $liquidacion = $stmt->fetch();
    if (!$liquidacion) { echo "<div class='alert alert-danger m-4'>Liquidación no encontrada.</div>"; exit; }
    if ($liquidacion['estado'] === 'CONFIRMADO' || $liquidacion['estado'] === 'ANULADO') {
        header("Location: liquidacion_ver.php?id=$id");
        exit;
    }
}

// =============================================
// PROCESAR POST: CALCULAR / GUARDAR / CONFIRMAR
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'calcular';

    try {
        // Recoger datos del formulario
        $tipo_origen = $_POST['tipo_origen'] ?? 'ARCA';
        $comprobante_arca_id = !empty($_POST['comprobante_arca_id']) ? (int)$_POST['comprobante_arca_id'] : null;
        $empresa_id = (int)$_POST['empresa_id'];
        $obra_id = (int)$_POST['obra_id'];
        $fecha_pago = $_POST['fecha_pago'];

        // Si es ARCA, traer datos del comprobante
        if ($tipo_origen === 'ARCA' && $comprobante_arca_id) {
            $stmtA = $pdo->prepare("SELECT * FROM comprobantes_arca WHERE id = ?");
            $stmtA->execute([$comprobante_arca_id]);
            $arca = $stmtA->fetch();
            if (!$arca) throw new Exception("Comprobante ARCA no encontrado.");

            $comp_tipo = $arca['tipo_comprobante'];
            $comp_fecha = $arca['fecha'];
            $comp_numero = str_pad($arca['punto_venta'], 5, '0', STR_PAD_LEFT) . '-' . str_pad($arca['numero'], 8, '0', STR_PAD_LEFT);
            $comp_total = (float)$arca['importe_total'];
            $comp_iva = (float)$arca['importe_iva'];
            $comp_neto = (float)$arca['importe_neto'];
        } else {
            $comp_tipo = $_POST['comp_tipo'] ?? 'Factura';
            $comp_fecha = $_POST['comp_fecha'] ?? $fecha_pago;
            $comp_numero = $_POST['comp_numero'] ?? '';
            $comp_total = (float)str_replace(',', '.', str_replace('.', '', $_POST['comp_total'] ?? '0'));
            $comp_iva = (float)str_replace(',', '.', str_replace('.', '', $_POST['comp_iva'] ?? '0'));
            $comp_neto = (float)str_replace(',', '.', str_replace('.', '', $_POST['comp_neto'] ?? '0'));
        }

        // Pago parcial
        $importe_pago = (float)str_replace(',', '.', str_replace('.', '', $_POST['importe_pago'] ?? '0'));
        if ($importe_pago <= 0) $importe_pago = $comp_total;

        // Concepto Ganancias RG830
        $ganancias_concepto_id = !empty($_POST['ganancias_concepto_id']) ? (int)$_POST['ganancias_concepto_id'] : null;

        // Parámetros de cálculo
        $alicuota_iva_contenido = (float)str_replace(',', '.', $_POST['alicuota_iva_contenido'] ?? '21');
        $obra_tipo = $_POST['obra_tipo'] ?? 'ARQUITECTURA';
        $obra_exencion_ganancias = (int)($_POST['obra_exencion_ganancias'] ?? 0);
        $obra_exencion_iva = (int)($_POST['obra_exencion_iva'] ?? 0);
        $obra_exencion_iibb = (int)($_POST['obra_exencion_iibb'] ?? 0);

        // Fondo de Reparo (auto 5% o manual)
        $fondo_reparo_pct = (float)str_replace(',', '.', $_POST['fondo_reparo_pct'] ?? '5');
        // Si el usuario puso un monto manual, usarlo; si no, NULL para que el motor calcule
        $fondo_reparo_monto_raw = trim($_POST['fondo_reparo_monto'] ?? '');
        $fondo_reparo_monto = ($fondo_reparo_monto_raw !== '') 
            ? (float)str_replace(',', '.', str_replace('.', '', $fondo_reparo_monto_raw)) 
            : null;
        $fondo_reparo_obs = trim($_POST['fondo_reparo_obs'] ?? '');

        // Overrides manuales de retenciones (vacío = usar cálculo automático)
        $parseOverride = function($name) {
            $v = trim($_POST[$name] ?? '');
            return ($v !== '') ? (float)str_replace(',', '.', str_replace('.', '', $v)) : null;
        };
        $override_suss = $parseOverride('override_suss');
        $override_ganancias = $parseOverride('override_ganancias');
        $override_iibb = $parseOverride('override_iibb');
        $override_iva = $parseOverride('override_iva');
        $ret_otras_monto = (float)str_replace(',', '.', str_replace('.', '', $_POST['ret_otras_monto'] ?? '0'));
        $ret_otras_obs = trim($_POST['ret_otras_obs'] ?? '');
        $multas_monto = (float)str_replace(',', '.', str_replace('.', '', $_POST['multas_monto'] ?? '0'));
        $multas_obs = trim($_POST['multas_obs'] ?? '');

        // Observaciones por línea
        $obs_suss = trim($_POST['obs_suss'] ?? '');
        $obs_ganancias = trim($_POST['obs_ganancias'] ?? '');
        $obs_iibb = trim($_POST['obs_iibb'] ?? '');
        $observaciones_finales = trim($_POST['observaciones_finales'] ?? '');

        // IIBB
        $iibb_categoria_id = !empty($_POST['iibb_categoria_id']) ? (int)$_POST['iibb_categoria_id'] : null;
        $iibb_jurisdiccion = trim($_POST['iibb_jurisdiccion'] ?? 'NEUQUEN');
        $iibb_alicuota = !empty($_POST['iibb_alicuota']) ? (float)str_replace(',', '.', $_POST['iibb_alicuota']) : null;
        $iibb_tipo_agente = $_POST['iibb_tipo_agente'] ?? 'ESTADO';

        // Cabecera documento
        $expediente = trim($_POST['expediente'] ?? '');
        $ref_doc = trim($_POST['ref_doc'] ?? '');
        $op_sicopro = trim($_POST['op_sicopro'] ?? '');
        $cesion_cuit = trim($_POST['cesion_cuit'] ?? '');
        $cesion_proveedor = trim($_POST['cesion_proveedor'] ?? '');
        $cesion_cbu = trim($_POST['cesion_cbu'] ?? '');

        // Ejecutar motor de cálculo
        $engine = new Rg830Engine($pdo);
        $resultado = $engine->calcular([
            'empresa_id'          => $empresa_id,
            'obra_id'             => $obra_id,
            'fecha_pago'          => $fecha_pago,
            'importe_total'       => $comp_total,
            'importe_pago'        => $importe_pago,
            'importe_iva'         => $comp_iva,
            'importe_neto'        => $comp_neto,
            'alicuota_iva_contenido' => $alicuota_iva_contenido,
            'obra_tipo'              => $obra_tipo,
            'ganancias_concepto_id'  => $ganancias_concepto_id,
            'obra_exencion_ganancias'=> $obra_exencion_ganancias,
            'obra_exencion_iva'      => $obra_exencion_iva,
            'obra_exencion_iibb'     => $obra_exencion_iibb,
            'fondo_reparo_pct'       => $fondo_reparo_pct,
            'fondo_reparo_monto'     => $fondo_reparo_monto,
            'override_suss'          => $override_suss,
            'override_ganancias'     => $override_ganancias,
            'override_iibb'          => $override_iibb,
            'override_iva'           => $override_iva,
            'ret_otras_monto'        => $ret_otras_monto,
            'multas_monto'           => $multas_monto,
            'iibb_categoria_id'      => $iibb_categoria_id,
            'iibb_jurisdiccion'      => $iibb_jurisdiccion,
            'iibb_alicuota'          => $iibb_alicuota,
            'iibb_tipo_agente'       => $iibb_tipo_agente,
        ]);

        // Guardar importe_pago en resultado para uso en la vista
        $resultado['importe_pago'] = $importe_pago;

        // GUARDAR si la acción es guardar o confirmar
        if ($accion === 'guardar' || $accion === 'confirmar') {
            $pdo->beginTransaction();

            $estado = ($accion === 'confirmar') ? 'CONFIRMADO' : 'PRELIQUIDADO';
            $campos = [
                'comprobante_arca_id', 'tipo_comprobante_origen',
                'comprobante_tipo', 'comprobante_fecha', 'comprobante_numero',
                'comprobante_importe_total', 'comprobante_iva', 'comprobante_importe_neto',
                'empresa_id', 'obra_id', 'fecha_pago',
                'alicuota_iva_contenido', 'obra_tipo',
                'obra_exencion_ganancias', 'obra_exencion_iva', 'obra_exencion_iibb',
                'iibb_categoria_id', 'iibb_jurisdiccion', 'iibb_alicuota',
                'expediente', 'ref_doc', 'op_sicopro',
                'cesion_cuit', 'cesion_proveedor', 'cesion_cbu',
                'importe_pago', 'ganancias_concepto_id',
                'base_imponible',
                'fondo_reparo_pct', 'fondo_reparo_monto', 'fondo_reparo_obs',
                'override_suss', 'override_ganancias', 'override_iibb', 'override_iva',
                'ret_otras_monto', 'ret_otras_obs',
                'multas_monto', 'multas_obs',
                'obs_suss', 'obs_ganancias', 'obs_iibb', 'observaciones_finales',
                'total_retenciones', 'neto_a_pagar', 'estado',
            ];
            $valores = [
                $comprobante_arca_id, $tipo_origen,
                $comp_tipo, $comp_fecha, $comp_numero,
                $comp_total, $comp_iva, $comp_neto,
                $empresa_id, $obra_id, $fecha_pago,
                $alicuota_iva_contenido, $obra_tipo,
                $obra_exencion_ganancias, $obra_exencion_iva, $obra_exencion_iibb,
                $iibb_categoria_id, $iibb_jurisdiccion, $iibb_alicuota,
                $expediente, $ref_doc, $op_sicopro,
                $cesion_cuit, $cesion_proveedor, $cesion_cbu,
                $importe_pago, $ganancias_concepto_id,
                $resultado['base_imponible'],
                $fondo_reparo_pct, $resultado['fondo_reparo'], $fondo_reparo_obs,
                $override_suss, $override_ganancias, $override_iibb, $override_iva,
                $ret_otras_monto, $ret_otras_obs,
                $multas_monto, $multas_obs,
                $obs_suss, $obs_ganancias, $obs_iibb, $observaciones_finales,
                $resultado['total_retenciones'], $resultado['neto_a_pagar'], $estado,
            ];

            if ($id > 0) {
                $sets = implode('=?, ', $campos) . '=?';
                $pdo->prepare("UPDATE liquidaciones SET $sets WHERE id=?")->execute(array_merge($valores, [$id]));
            } else {
                $placeholders = implode(',', array_fill(0, count($campos) + 1, '?'));
                $colList = implode(', ', $campos) . ', usuario_id';
                $pdo->prepare("INSERT INTO liquidaciones ($colList) VALUES ($placeholders)")
                    ->execute(array_merge($valores, [$_SESSION['user_id']]));
                $id = (int)$pdo->lastInsertId();
            }

            // Borrar items anteriores
            $pdo->prepare("DELETE FROM liquidacion_items WHERE liquidacion_id = ?")->execute([$id]);

            // Insertar items de retención
            $empresa = $pdo->prepare("SELECT * FROM empresas WHERE id = ?")->execute([$empresa_id]);
            $empresaData = $pdo->prepare("SELECT * FROM empresas WHERE id = ?");
            $empresaData->execute([$empresa_id]);
            $emp = $empresaData->fetch();

            $stmtItem = $pdo->prepare("INSERT INTO liquidacion_items (
                liquidacion_id, impuesto, rg830_concepto_id, rg830_vigencia_id,
                condicion_fiscal, base_calculo, minimo_no_sujeto, base_sujeta,
                alicuota_aplicada, importe_retencion,
                sicore_cod_impuesto, sicore_cod_regimen, sicore_cod_comprobante,
                snapshot_parametros
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($resultado['items'] as $item) {
                // Verificar overrides del formulario
                $overrideKey = 'override_' . $item['impuesto'];
                $tieneOverride = !empty($_POST[$overrideKey]);
                $importeFinal = $item['importe_retencion'];

                if ($tieneOverride) {
                    $importeFinal = (float)str_replace(',', '.', str_replace('.', '', $_POST[$overrideKey]));
                }

                $snapshot = $engine->generarSnapshot($item, $emp);
                $stmtItem->execute([
                    $id, $item['impuesto'], $item['rg830_concepto_id'], $item['rg830_vigencia_id'],
                    $item['condicion_fiscal'], $item['base_calculo'], $item['minimo_no_sujeto'], $item['base_sujeta'],
                    $item['alicuota_aplicada'], $importeFinal,
                    $item['sicore_cod_impuesto'], $item['sicore_cod_regimen'], $item['sicore_cod_comprobante'],
                    json_encode($snapshot)
                ]);
            }

            // Si confirmar, generar nro certificado y bloquear
            if ($accion === 'confirmar') {
                $anio = date('Y');
                $pdo->prepare("INSERT INTO retencion_numeracion (anio, ultimo_numero) VALUES (?, 0) ON DUPLICATE KEY UPDATE anio=anio")->execute([$anio]);
                $pdo->prepare("UPDATE retencion_numeracion SET ultimo_numero = ultimo_numero + 1 WHERE anio = ?")->execute([$anio]);
                $stmtNum = $pdo->prepare("SELECT ultimo_numero, prefijo FROM retencion_numeracion WHERE anio = ?");
                $stmtNum->execute([$anio]);
                $numRow = $stmtNum->fetch();
                $nroCert = ($numRow['prefijo'] ?? 'RET') . '-' . $anio . '-' . str_pad($numRow['ultimo_numero'], 6, '0', STR_PAD_LEFT);

                $pdo->prepare("UPDATE liquidaciones SET nro_certificado_retencion=?, fecha_confirmacion=NOW(), usuario_confirmacion_id=? WHERE id=?")
                    ->execute([$nroCert, $_SESSION['user_id'], $id]);

                // Log
                $pdo->prepare("INSERT INTO liquidacion_logs (liquidacion_id, usuario_id, accion, motivo) VALUES (?,?,?,?)")
                    ->execute([$id, $_SESSION['user_id'], 'CONFIRMAR', 'Liquidación confirmada. Cert: ' . $nroCert]);
            } else {
                $pdo->prepare("INSERT INTO liquidacion_logs (liquidacion_id, usuario_id, accion) VALUES (?,?,?)")
                    ->execute([$id, $_SESSION['user_id'], 'GUARDAR']);
            }

            $pdo->commit();

            if ($accion === 'confirmar') {
                header("Location: liquidacion_ver.php?id=$id&ok=1");
                exit;
            }

            $mensaje = "Preliquidación guardada correctamente (ID: $id)";
            $tipo_alerta = 'success';

            // Recargar
            $stmt = $pdo->prepare("SELECT * FROM liquidaciones WHERE id = ?");
            $stmt->execute([$id]);
            $liquidacion = $stmt->fetch();

        } else {
            // Solo calcular (preview)
            $mensaje = "Cálculo realizado. Revise los resultados antes de guardar.";
            $tipo_alerta = 'info';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "Error: " . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// Datos para selects
$empresas = $pdo->query("SELECT id, razon_social, cuit, condicion_iva, ganancias_condicion FROM empresas WHERE activo=1 ORDER BY razon_social")->fetchAll();
$obras = $pdo->query("SELECT id, denominacion FROM obras WHERE activo=1 ORDER BY denominacion")->fetchAll();
$iibbCategorias = $pdo->query("SELECT * FROM iibb_categorias WHERE activo=1 ORDER BY orden, codigo")->fetchAll();
$iibbMinimos = $pdo->query("SELECT * FROM iibb_minimos WHERE activo=1 ORDER BY tipo_agente")->fetchAll();
$ganConceptos = $pdo->query("SELECT * FROM rg830_conceptos WHERE codigo='GANANCIAS' AND activo=1 ORDER BY inciso")->fetchAll();

function fmt($v) { return number_format((float)$v, 2, ',', '.'); }

// Valores del formulario (prellenar)
$v = function($col, $post = null, $def = '') use ($liquidacion) {
    $postKey = $post ?? $col;
    return $liquidacion[$col] ?? ($_POST[$postKey] ?? $def);
};
$f = [
    'tipo_origen'       => $v('tipo_comprobante_origen', 'tipo_origen', 'ARCA'),
    'empresa_id'        => $v('empresa_id'),
    'obra_id'           => $v('obra_id'),
    'fecha_pago'        => $v('fecha_pago', 'fecha_pago', date('Y-m-d')),
    'comp_tipo'         => $v('comprobante_tipo', 'comp_tipo'),
    'comp_fecha'        => $v('comprobante_fecha', 'comp_fecha'),
    'comp_numero'       => $v('comprobante_numero', 'comp_numero'),
    'comp_total'        => $v('comprobante_importe_total', 'comp_total'),
    'comp_iva'          => $v('comprobante_iva', 'comp_iva'),
    'comp_neto'         => $v('comprobante_importe_neto', 'comp_neto'),
    'comprobante_arca_id' => $v('comprobante_arca_id'),
    'importe_pago'      => $v('importe_pago', 'importe_pago', 0),
    'ganancias_concepto_id' => $v('ganancias_concepto_id'),
    'alicuota_iva_contenido' => $v('alicuota_iva_contenido', 'alicuota_iva_contenido', '21'),
    'obra_tipo'         => $v('obra_tipo', 'obra_tipo', 'ARQUITECTURA'),
    'obra_exencion_ganancias' => $v('obra_exencion_ganancias', 'obra_exencion_ganancias', 0),
    'obra_exencion_iva' => $v('obra_exencion_iva', 'obra_exencion_iva', 0),
    'obra_exencion_iibb'=> $v('obra_exencion_iibb', 'obra_exencion_iibb', 0),
    'fondo_reparo_pct'  => $v('fondo_reparo_pct', 'fondo_reparo_pct', '5'),
    'fondo_reparo_monto'=> $v('fondo_reparo_monto', 'fondo_reparo_monto', ''),
    'fondo_reparo_obs'  => $v('fondo_reparo_obs'),
    'override_suss'     => $v('override_suss', 'override_suss', ''),
    'override_ganancias'=> $v('override_ganancias', 'override_ganancias', ''),
    'override_iibb'     => $v('override_iibb', 'override_iibb', ''),
    'override_iva'      => $v('override_iva', 'override_iva', ''),
    'ret_otras_monto'   => $v('ret_otras_monto', 'ret_otras_monto', 0),
    'ret_otras_obs'     => $v('ret_otras_obs'),
    'multas_monto'      => $v('multas_monto', 'multas_monto', 0),
    'multas_obs'        => $v('multas_obs'),
    'obs_suss'          => $v('obs_suss'),
    'obs_ganancias'     => $v('obs_ganancias'),
    'obs_iibb'          => $v('obs_iibb'),
    'observaciones_finales' => $v('observaciones_finales'),
    'iibb_categoria_id' => $v('iibb_categoria_id'),
    'iibb_jurisdiccion' => $v('iibb_jurisdiccion'),
    'iibb_alicuota'     => $v('iibb_alicuota'),
    'iibb_tipo_agente'  => $_POST['iibb_tipo_agente'] ?? 'ESTADO',
    'expediente'        => $v('expediente'),
    'ref_doc'           => $v('ref_doc'),
    'op_sicopro'        => $v('op_sicopro'),
    'cesion_cuit'       => $v('cesion_cuit'),
    'cesion_proveedor'  => $v('cesion_proveedor'),
    'cesion_cbu'        => $v('cesion_cbu'),
];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<div class="container-fluid px-4 my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="text-primary fw-bold mb-0">
                <i class="bi bi-calculator"></i>
                <?= $id > 0 ? "Editar Preliquidación #$id" : 'Nueva Liquidación' ?>
            </h3>
            <p class="text-muted small mb-0">Determinación impositiva – Retenciones RG 830</p>
        </div>
        <a href="liquidaciones_listado.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show py-2">
        <?= $mensaje ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="formLiquidacion">
        <input type="hidden" name="accion" id="accionForm" value="calcular">

        <div class="row g-3">
            <!-- ===== COL IZQUIERDA: DATOS COMPROBANTE ===== -->
            <div class="col-lg-7">
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-header bg-primary text-white fw-bold">
                        <i class="bi bi-receipt"></i> 1. Datos del Comprobante
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Origen</label>
                                <select name="tipo_origen" id="tipoOrigen" class="form-select" onchange="toggleOrigen()">
                                    <option value="ARCA" <?= $f['tipo_origen']==='ARCA' ? 'selected' : '' ?>>Comprobante ARCA</option>
                                    <option value="OTROS_PAGOS" <?= $f['tipo_origen']==='OTROS_PAGOS' ? 'selected' : '' ?>>Otros Pagos</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-primary">Empresa / Proveedor *</label>
                                <select name="empresa_id" id="empresaId" class="form-select select2-empresa" required>
                                    <option value="">Seleccione...</option>
                                    <?php foreach ($empresas as $e): ?>
                                    <option value="<?= $e['id'] ?>" data-cuit="<?= $e['cuit'] ?>" data-condiva="<?= $e['condicion_iva'] ?>" data-condgan="<?= $e['ganancias_condicion'] ?>" <?= $f['empresa_id']==$e['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($e['cuit'] . ' - ' . $e['razon_social']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-primary">Obra *</label>
                                <select name="obra_id" id="obraId" class="form-select select2-obra" required>
                                    <option value="">Seleccione...</option>
                                    <?php foreach ($obras as $o): ?>
                                    <option value="<?= $o['id'] ?>" <?= $f['obra_id']==$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['denominacion']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- ARCA: Tabla de comprobantes vinculados al proveedor -->
                        <div id="divArca" class="mt-3" style="<?= $f['tipo_origen']==='OTROS_PAGOS' ? 'display:none' : '' ?>">
                            <input type="hidden" name="comprobante_arca_id" id="comprobante_arca_id" value="<?= $f['comprobante_arca_id'] ?>">

                            <!-- Comprobante seleccionado (resumen) -->
                            <div id="arcaSeleccionado" class="alert alert-success py-2 d-flex justify-content-between align-items-center mb-2" style="display:none !important;">
                                <div>
                                    <i class="bi bi-check-circle-fill"></i>
                                    <strong>Comprobante seleccionado:</strong>
                                    <span id="arcaSelResumen"></span>
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="deseleccionarArca()">
                                    <i class="bi bi-x-lg"></i> Quitar
                                </button>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold small mb-0">
                                    <i class="bi bi-receipt-cutoff"></i> Comprobantes ARCA del proveedor
                                </label>
                                <input type="text" id="buscarArca" class="form-control form-control-sm" style="width:200px" placeholder="Buscar por número...">
                            </div>

                            <div id="tablaArcaContainer" style="max-height:280px; overflow-y:auto; border:1px solid #dee2e6; border-radius:6px;">
                                <table class="table table-sm table-hover mb-0" style="font-size:0.8rem;">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Tipo</th>
                                            <th>Número</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">IVA</th>
                                            <th class="text-end">Neto</th>
                                            <th class="text-center">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyArca">
                                        <tr><td colspan="7" class="text-center text-muted py-3">
                                            <i class="bi bi-arrow-up-circle"></i> Seleccione una empresa para ver sus comprobantes ARCA
                                        </td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <small class="text-muted" id="arcaContador">0 comprobantes</small>
                                <small class="text-muted">Haga clic en una fila para seleccionar</small>
                            </div>
                        </div>

                        <!-- Manual inputs (Otros Pagos) -->
                        <div id="divManual" class="mt-3" style="<?= $f['tipo_origen']==='ARCA' ? 'display:none' : '' ?>">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label small">Tipo</label>
                                    <input type="text" name="comp_tipo" id="comp_tipo" class="form-control form-control-sm" value="<?= htmlspecialchars($f['comp_tipo']) ?>" placeholder="Factura A">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Fecha</label>
                                    <input type="date" name="comp_fecha" id="comp_fecha" class="form-control form-control-sm" value="<?= $f['comp_fecha'] ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Número (16 dígitos)</label>
                                    <input type="text" name="comp_numero" id="comp_numero" class="form-control form-control-sm" value="<?= htmlspecialchars($f['comp_numero']) ?>" maxlength="16" placeholder="00001-00000001">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">Importe Total $</label>
                                    <input type="text" name="comp_total" id="comp_total" class="form-control form-control-sm fw-bold" value="<?= $f['comp_total'] ? fmt($f['comp_total']) : '' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">IVA $</label>
                                    <input type="text" name="comp_iva" id="comp_iva" class="form-control form-control-sm" value="<?= $f['comp_iva'] ? fmt($f['comp_iva']) : '' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Neto $</label>
                                    <input type="text" name="comp_neto" id="comp_neto" class="form-control form-control-sm" value="<?= $f['comp_neto'] ? fmt($f['comp_neto']) : '' ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Pago parcial -->
                        <div class="card border-primary mt-2 mb-2">
                            <div class="card-header bg-primary-subtle py-1 px-3">
                                <span class="fw-bold small"><i class="bi bi-cash-stack"></i> Importe a Pagar</span>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-5">
                                        <label class="form-label small mb-0 fw-bold text-primary">Importe del Pago $</label>
                                        <input type="text" name="importe_pago" id="importePago" class="form-control form-control-sm fw-bold border-primary" 
                                               value="<?= ($f['importe_pago'] && (float)$f['importe_pago'] > 0) ? fmt($f['importe_pago']) : ($f['comp_total'] ? fmt($f['comp_total']) : '') ?>"
                                               placeholder="Total factura">
                                        <div class="form-text">Dejar vacío o igual al total para pago completo.</div>
                                    </div>
                                    <div class="col-md-7" id="saldoFacturaInfo">
                                        <?php
                                        // Calcular saldo pendiente si es factura ARCA
                                        if ($f['comprobante_arca_id']) {
                                            $stmtPagos = $pdo->prepare("
                                                SELECT COALESCE(SUM(importe_pago), 0) AS total_pagado
                                                FROM liquidaciones 
                                                WHERE comprobante_arca_id = ? 
                                                  AND estado NOT IN ('ANULADO')
                                                  AND id != ?
                                            ");
                                            $stmtPagos->execute([$f['comprobante_arca_id'], $id ?? 0]);
                                            $totalPagado = (float)$stmtPagos->fetchColumn();
                                            $totalFactura = (float)$f['comp_total'];
                                            $saldoPendiente = $totalFactura - $totalPagado;
                                            if ($totalPagado > 0):
                                        ?>
                                        <div class="alert alert-info py-1 px-2 mb-0 small">
                                            <strong>Factura $<?= fmt($totalFactura) ?></strong><br>
                                            Pagado anterior: $<?= fmt($totalPagado) ?><br>
                                            <strong class="text-primary">Saldo pendiente: $<?= fmt($saldoPendiente) ?></strong>
                                        </div>
                                        <?php endif; } ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr class="my-1">
                        <!-- Cabecera documento -->
                        <div class="row g-2 mb-2">
                            <div class="col-md-3">
                                <label class="form-label fw-bold small text-danger">Fecha de Pago *</label>
                                <input type="date" name="fecha_pago" class="form-control form-control-sm" value="<?= $f['fecha_pago'] ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Expediente</label>
                                <input type="text" name="expediente" class="form-control form-control-sm" value="<?= htmlspecialchars($f['expediente']) ?>" placeholder="2025-0303...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">O.P. SICOPRO</label>
                                <input type="text" name="op_sicopro" class="form-control form-control-sm" value="<?= htmlspecialchars($f['op_sicopro']) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">REF. DOC.</label>
                                <input type="text" name="ref_doc" class="form-control form-control-sm" value="<?= htmlspecialchars($f['ref_doc']) ?>">
                            </div>
                        </div>

                        <!-- Tipo obra + Concepto Ganancias + IVA + Exenciones -->
                        <div class="card border-warning mb-2">
                            <div class="card-header bg-warning-subtle py-1 px-3">
                                <span class="fw-bold small"><i class="bi bi-building-gear"></i> Tipo de Obra / SUSS / Ganancias / IVA / Exenciones</span>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small mb-0 fw-bold">Tipo de Obra</label>
                                        <select name="obra_tipo" class="form-select form-select-sm">
                                            <option value="ARQUITECTURA" <?= $f['obra_tipo']==='ARQUITECTURA' ? 'selected' : '' ?>>Arquitectura (SUSS 2,50%)</option>
                                            <option value="INGENIERIA" <?= $f['obra_tipo']==='INGENIERIA' ? 'selected' : '' ?>>Ingeniería (SUSS 1,20%)</option>
                                            <option value="OTRA" <?= $f['obra_tipo']==='OTRA' ? 'selected' : '' ?>>Otra (según config)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label small mb-0 fw-bold">Concepto Ganancias (RG 830)</label>
                                        <select name="ganancias_concepto_id" class="form-select form-select-sm">
                                            <?php foreach ($ganConceptos as $gc): ?>
                                            <option value="<?= $gc['id'] ?>" <?= $f['ganancias_concepto_id']==$gc['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($gc['inciso'] . ') ' . $gc['descripcion']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-0">Alícuota IVA Cont. %</label>
                                        <select name="alicuota_iva_contenido" class="form-select form-select-sm">
                                            <option value="21" <?= $f['alicuota_iva_contenido']==21 ? 'selected' : '' ?>>21%</option>
                                            <option value="10.5" <?= $f['alicuota_iva_contenido']==10.5 ? 'selected' : '' ?>>10,5%</option>
                                            <option value="27" <?= $f['alicuota_iva_contenido']==27 ? 'selected' : '' ?>>27%</option>
                                            <option value="0" <?= $f['alicuota_iva_contenido']==0 ? 'selected' : '' ?>>0% (Exento)</option>
                                        </select>
                                        <div class="form-text">Se detrae de base.</div>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small mb-0">Exenciones</label>
                                        <div class="d-flex flex-wrap gap-2 mt-1">
                                            <div class="form-check form-check-inline">
                                                <input type="hidden" name="obra_exencion_ganancias" value="0">
                                                <input type="checkbox" name="obra_exencion_ganancias" class="form-check-input" value="1" <?= $f['obra_exencion_ganancias'] ? 'checked' : '' ?>>
                                                <label class="form-check-label small">Gan.</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input type="hidden" name="obra_exencion_iva" value="0">
                                                <input type="checkbox" name="obra_exencion_iva" class="form-check-input" value="1" <?= $f['obra_exencion_iva'] ? 'checked' : '' ?>>
                                                <label class="form-check-label small">IVA</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input type="hidden" name="obra_exencion_iibb" value="0">
                                                <input type="checkbox" name="obra_exencion_iibb" class="form-check-input" value="1" <?= $f['obra_exencion_iibb'] ? 'checked' : '' ?>>
                                                <label class="form-check-label small">IIBB</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- IIBB – Res. 276/DPR/17 -->
                        <div class="card border-success mb-2">
                            <div class="card-header bg-success-subtle py-1 px-3 d-flex justify-content-between align-items-center">
                                <span class="fw-bold small"><i class="bi bi-geo-alt"></i> IIBB – Res. 276/DPR/17</span>
                                <a href="config/iibb_config.php" class="btn btn-outline-success btn-sm py-0 px-1" title="Configurar" target="_blank"><i class="bi bi-gear"></i></a>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <select name="iibb_categoria_id" id="iibbCategoriaId" class="form-select form-select-sm" onchange="onIibbCategoriaChange()">
                                            <option value="">Sin retención IIBB</option>
                                            <?php foreach ($iibbCategorias as $cat): ?>
                                            <option value="<?= $cat['id'] ?>" data-alicuota="<?= $cat['alicuota'] ?>" <?= $f['iibb_categoria_id']==$cat['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat['codigo'] . ') ' . $cat['descripcion'] . ' — ' . number_format($cat['alicuota'], 2, ',', '.') . '%') ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" name="iibb_alicuota" id="iibbAlicuota" class="form-control form-control-sm" value="<?= $f['iibb_alicuota'] ? str_replace('.', ',', $f['iibb_alicuota']) : '' ?>" placeholder="Alíc. %">
                                    </div>
                                    <div class="col-md-3">
                                        <select name="iibb_tipo_agente" class="form-select form-select-sm">
                                            <?php foreach ($iibbMinimos as $min): ?>
                                            <option value="<?= $min['tipo_agente'] ?>" <?= $f['iibb_tipo_agente']===$min['tipo_agente'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($min['tipo_agente'] . ' ($' . number_format($min['minimo_no_sujeto'], 0, ',', '.') . ')') ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <input type="hidden" name="iibb_jurisdiccion" value="NEUQUEN">
                            </div>
                        </div>

                        <!-- RETENCIONES Y/O DEDUCCIONES (formato SIGUE) -->
                        <div class="card border-dark mb-2">
                            <div class="card-header bg-dark text-white py-1 px-3">
                                <span class="fw-bold small">RETENCIONES Y/O DEDUCCIONES</span>
                                <span class="float-end badge bg-light text-dark" style="font-size:0.7rem">Vacío = auto-calculado</span>
                            </div>
                            <div class="card-body py-2 px-3">
                                <table class="table table-sm table-borderless mb-0" style="font-size:0.82rem">
                                    <thead><tr><th style="width:35%"></th><th style="width:30%">Importe $</th><th>Observaciones</th></tr></thead>
                                    <tbody>
                                        <tr>
                                            <td class="fw-bold">
                                                Fondo de Reparo:
                                                <div class="input-group input-group-sm mt-1" style="width:120px">
                                                    <input type="text" name="fondo_reparo_pct" class="form-control form-control-sm text-end" value="<?= str_replace('.', ',', $f['fondo_reparo_pct']) ?>" style="width:50px" id="fondoReparoPct">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" name="fondo_reparo_monto" id="fondoReparoMonto" class="form-control form-control-sm" 
                                                       value="<?= ($f['fondo_reparo_monto'] !== '' && $f['fondo_reparo_monto'] !== null && (float)$f['fondo_reparo_monto'] > 0) ? fmt($f['fondo_reparo_monto']) : '' ?>" 
                                                       placeholder="Auto (5%)">
                                                <div class="form-text" style="font-size:0.7rem">Vacío = auto <?= $f['fondo_reparo_pct'] ?>%</div>
                                            </td>
                                            <td><input type="text" name="fondo_reparo_obs" class="form-control form-control-sm" value="<?= htmlspecialchars($f['fondo_reparo_obs']) ?>"></td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="fw-bold">Retención SUSS:</td>
                                            <td>
                                                <input type="text" name="override_suss" class="form-control form-control-sm" 
                                                       value="<?= ($f['override_suss'] !== '' && $f['override_suss'] !== null) ? fmt($f['override_suss']) : '' ?>" 
                                                       placeholder="Auto-calculado">
                                            </td>
                                            <td><input type="text" name="obs_suss" class="form-control form-control-sm" value="<?= htmlspecialchars($f['obs_suss']) ?>"></td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="fw-bold">Ret. Imp. Ganancias:</td>
                                            <td>
                                                <input type="text" name="override_ganancias" class="form-control form-control-sm" 
                                                       value="<?= ($f['override_ganancias'] !== '' && $f['override_ganancias'] !== null) ? fmt($f['override_ganancias']) : '' ?>" 
                                                       placeholder="Auto-calculado">
                                            </td>
                                            <td><input type="text" name="obs_ganancias" class="form-control form-control-sm" value="<?= htmlspecialchars($f['obs_ganancias']) ?>"></td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="fw-bold">Ret. IIBB:</td>
                                            <td>
                                                <input type="text" name="override_iibb" class="form-control form-control-sm" 
                                                       value="<?= ($f['override_iibb'] !== '' && $f['override_iibb'] !== null) ? fmt($f['override_iibb']) : '' ?>" 
                                                       placeholder="Auto-calculado">
                                            </td>
                                            <td><input type="text" name="obs_iibb" class="form-control form-control-sm" value="<?= htmlspecialchars($f['obs_iibb']) ?>"></td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="fw-bold">Ret. IVA:</td>
                                            <td>
                                                <input type="text" name="override_iva" class="form-control form-control-sm" 
                                                       value="<?= ($f['override_iva'] !== '' && $f['override_iva'] !== null) ? fmt($f['override_iva']) : '' ?>" 
                                                       placeholder="Auto-calculado">
                                            </td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Ret. OTRAS:</td>
                                            <td><input type="text" name="ret_otras_monto" class="form-control form-control-sm" value="<?= $f['ret_otras_monto'] ? fmt($f['ret_otras_monto']) : '' ?>" placeholder="0,00"></td>
                                            <td><input type="text" name="ret_otras_obs" class="form-control form-control-sm" value="<?= htmlspecialchars($f['ret_otras_obs']) ?>"></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Multas:</td>
                                            <td><input type="text" name="multas_monto" class="form-control form-control-sm" value="<?= $f['multas_monto'] ? fmt($f['multas_monto']) : '' ?>" placeholder="0,00"></td>
                                            <td><input type="text" name="multas_obs" class="form-control form-control-sm" value="<?= htmlspecialchars($f['multas_obs']) ?>"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Cesión de derechos -->
                        <details class="mb-2">
                            <summary class="small fw-bold text-secondary"><i class="bi bi-arrow-left-right"></i> Cesión de Derechos (opcional)</summary>
                            <div class="row g-2 mt-1">
                                <div class="col-md-3"><input type="text" name="cesion_cuit" class="form-control form-control-sm" value="<?= htmlspecialchars($f['cesion_cuit']) ?>" placeholder="CUIT"></div>
                                <div class="col-md-5"><input type="text" name="cesion_proveedor" class="form-control form-control-sm" value="<?= htmlspecialchars($f['cesion_proveedor']) ?>" placeholder="Proveedor cedido"></div>
                                <div class="col-md-4"><input type="text" name="cesion_cbu" class="form-control form-control-sm" value="<?= htmlspecialchars($f['cesion_cbu']) ?>" placeholder="CBU"></div>
                            </div>
                        </details>

                        <!-- Observaciones finales -->
                        <div class="mb-2">
                            <label class="form-label small mb-0">Observaciones y/o Aclaraciones Finales</label>
                            <textarea name="observaciones_finales" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($f['observaciones_finales']) ?></textarea>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-info text-white fw-bold" onclick="document.getElementById('accionForm').value='calcular'">
                                <i class="bi bi-calculator"></i> Calcular Retenciones
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== COL DERECHA: RESULTADOS formato SIGUE-UPEFE ===== -->
            <div class="col-lg-5">
                <?php if ($resultado): ?>
                <div class="card shadow-sm border-0 mb-3" style="font-size:0.85rem">
                    <div class="card-header bg-dark text-white fw-bold text-center py-1">
                        RESULTADO PRELIQUIDACIÓN
                    </div>
                    <div class="card-body p-2">
                        <!-- Importe liquidado -->
                        <table class="table table-sm table-bordered mb-2">
                            <?php
                            $esParcial = isset($resultado['importe_pago']) && $resultado['importe_pago'] < (float)$f['comp_total'] && (float)$f['comp_total'] > 0;
                            ?>
                            <?php if ($esParcial): ?>
                            <tr>
                                <td class="text-muted small">Total Factura</td>
                                <td class="text-end text-muted">$<?= fmt($f['comp_total']) ?></td>
                            </tr>
                            <tr class="table-info">
                                <td class="fw-bold"><i class="bi bi-arrow-return-right"></i> PAGO PARCIAL</td>
                                <td class="text-end fw-bold fs-6">$<?= fmt($resultado['importe_liquidado']) ?></td>
                            </tr>
                            <?php else: ?>
                            <tr class="table-secondary">
                                <td class="fw-bold">IMPORTE LIQUIDADO</td>
                                <td class="text-end fw-bold fs-6">$<?= fmt($resultado['importe_liquidado']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="text-muted small">Base imponible (Pago - IVA prop.)</td>
                                <td class="text-end">$<?= fmt($resultado['base_imponible']) ?></td>
                            </tr>
                        </table>

                        <!-- Retenciones y/o deducciones -->
                        <table class="table table-sm table-bordered mb-2">
                            <thead>
                                <tr class="table-warning text-center"><th colspan="3">RETENCIONES Y/O DEDUCCIONES</th></tr>
                            </thead>
                            <?php
                            // Helper para mostrar badge manual
                            $manualBadge = '<span class="badge bg-warning text-dark" style="font-size:0.65rem">MANUAL</span>';
                            $fondoEsManual = ($resultado['fondo_reparo'] != $resultado['fondo_reparo_auto']);
                            $sussEsManual = ($resultado['ret_suss'] != $resultado['ret_suss_auto']);
                            $ganEsManual = ($resultado['ret_ganancias'] != $resultado['ret_ganancias_auto']);
                            $iibbEsManual = ($resultado['ret_iibb'] != $resultado['ret_iibb_auto']);
                            $ivaEsManual = ($resultado['ret_iva'] != $resultado['ret_iva_auto']);
                            ?>
                            <tbody>
                                <tr>
                                    <td style="width:40%">Fondo de Reparo (<?= number_format($resultado['fondo_reparo_pct'],1,',','.') ?>%):</td>
                                    <td class="text-end fw-bold" style="width:25%">$<?= fmt($resultado['fondo_reparo']) ?></td>
                                    <td class="small text-muted">
                                        <?php if ($fondoEsManual): ?>
                                            <?= $manualBadge ?> <span class="text-secondary">Auto: $<?= fmt($resultado['fondo_reparo_auto']) ?></span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($f['fondo_reparo_obs']) ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Retención SUSS:</td>
                                    <td class="text-end fw-bold">$<?= fmt($resultado['ret_suss']) ?></td>
                                    <td class="small text-muted">
                                        <?php if ($sussEsManual): ?>
                                            <?= $manualBadge ?> <span class="text-secondary">Auto: $<?= fmt($resultado['ret_suss_auto']) ?></span><br>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($f['obs_suss']) ?>
                                        <?php
                                        foreach ($resultado['items'] as $it) {
                                            if ($it['impuesto'] === 'SUSS') {
                                                echo '<br><span class="text-info">Base: $' . fmt($it['base_sujeta']) . ' × ' . number_format($it['alicuota_aplicada'],2,',','.') . '%';
                                                if (($f['obra_tipo'] ?? '') !== 'OTRA') {
                                                    echo ' (' . ($f['obra_tipo']==='INGENIERIA' ? 'Ing.' : 'Arq.') . ')';
                                                }
                                                echo '</span>';
                                                if ($it['minimo_no_sujeto'] > 0) echo '<br><span class="text-secondary">Mín. no suj.: $' . fmt($it['minimo_no_sujeto']) . '</span>';
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Ret. Imp. Ganancias:</td>
                                    <td class="text-end fw-bold">$<?= fmt($resultado['ret_ganancias']) ?></td>
                                    <td class="small text-muted">
                                        <?php if ($ganEsManual): ?>
                                            <?= $manualBadge ?> <span class="text-secondary">Auto: $<?= fmt($resultado['ret_ganancias_auto']) ?></span><br>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($f['obs_ganancias']) ?>
                                        <?php
                                        foreach ($resultado['items'] as $it) {
                                            if ($it['impuesto'] === 'GANANCIAS') {
                                                echo '<br><span class="text-info">Base: $' . fmt($it['base_sujeta']) . ' × ' . number_format($it['alicuota_aplicada'],2,',','.') . '%</span>';
                                                if ($it['minimo_no_sujeto'] > 0) echo '<br><span class="text-secondary">Mín. no suj.: $' . fmt($it['minimo_no_sujeto']) . '</span>';
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Ret. IIBB:</td>
                                    <td class="text-end fw-bold">$<?= fmt($resultado['ret_iibb']) ?></td>
                                    <td class="small text-muted">
                                        <?php if ($iibbEsManual): ?>
                                            <?= $manualBadge ?> <span class="text-secondary">Auto: $<?= fmt($resultado['ret_iibb_auto']) ?></span><br>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($f['obs_iibb']) ?>
                                        <?php
                                        foreach ($resultado['items'] as $it) {
                                            if ($it['impuesto'] === 'IIBB') {
                                                echo '<br><span class="text-info">Base: $' . fmt($it['base_sujeta']) . ' × ' . number_format($it['alicuota_aplicada'],2,',','.') . '%</span>';
                                                if ($it['minimo_no_sujeto'] > 0) echo '<br><span class="text-secondary">Mín. no suj.: $' . fmt($it['minimo_no_sujeto']) . '</span>';
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php if (isset($resultado['ret_iva']) && $resultado['ret_iva'] > 0): ?>
                                <tr>
                                    <td>Ret. IVA:</td>
                                    <td class="text-end fw-bold">$<?= fmt($resultado['ret_iva']) ?></td>
                                    <td class="small text-muted">
                                        <?php if ($ivaEsManual): ?>
                                            <?= $manualBadge ?> <span class="text-secondary">Auto: $<?= fmt($resultado['ret_iva_auto']) ?></span><br>
                                        <?php endif; ?>
                                        <?php
                                        foreach ($resultado['items'] as $it) {
                                            if ($it['impuesto'] === 'IVA') {
                                                echo 'Base IVA: $' . fmt($it['base_sujeta']) . ' × ' . number_format($it['alicuota_aplicada'],2,',','.') . '%';
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td>Ret. OTRAS:</td>
                                    <td class="text-end fw-bold">$<?= fmt($resultado['ret_otras']) ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($f['ret_otras_obs']) ?></td>
                                </tr>
                                <tr>
                                    <td>Multas:</td>
                                    <td class="text-end fw-bold">$<?= fmt($resultado['multas']) ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($f['multas_obs']) ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Total retenciones -->
                        <table class="table table-sm table-bordered mb-2">
                            <tr class="table-danger">
                                <td class="fw-bold">TOTAL RETENCIONES</td>
                                <td class="text-end fw-bold fs-6">$-<?= fmt($resultado['total_retenciones']) ?></td>
                            </tr>
                        </table>

                        <!-- Observaciones -->
                        <?php if ($f['observaciones_finales']): ?>
                        <div class="bg-light border rounded p-2 mb-2 small">
                            <strong>OBSERVACIONES:</strong> <?= htmlspecialchars($f['observaciones_finales']) ?>
                        </div>
                        <?php endif; ?>

                        <!-- Neto a pagar -->
                        <table class="table table-sm table-bordered mb-3">
                            <tr class="table-success">
                                <td class="fw-bold fs-6">TOTAL NETO A PAGAR TESORERÍA</td>
                                <td class="text-end fw-bold fs-5 text-success">$<?= fmt($resultado['neto_a_pagar']) ?></td>
                            </tr>
                        </table>

                        <!-- Detalle técnico (colapsable) -->
                        <details class="mb-3">
                            <summary class="small fw-bold text-secondary"><i class="bi bi-table"></i> Detalle técnico por impuesto</summary>
                            <table class="table table-sm table-bordered mt-1" style="font-size:0.78rem">
                                <thead class="table-light">
                                    <tr><th>Impuesto</th><th class="text-end">Base</th><th class="text-end">Mín.</th><th class="text-end">Base Suj.</th><th class="text-end">Alíc.</th><th class="text-end">Retención</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resultado['items'] as $item): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary"><?= $item['impuesto'] ?></span></td>
                                        <td class="text-end">$<?= fmt($item['base_calculo']) ?></td>
                                        <td class="text-end">$<?= fmt($item['minimo_no_sujeto']) ?></td>
                                        <td class="text-end">$<?= fmt($item['base_sujeta']) ?></td>
                                        <td class="text-end"><?= number_format($item['alicuota_aplicada'], 2, ',', '.') ?>%</td>
                                        <td class="text-end fw-bold">$<?= fmt($item['importe_retencion']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill fw-bold" onclick="document.getElementById('accionForm').value='guardar'">
                                <i class="bi bi-save"></i> Guardar Preliquidación
                            </button>
                            <button type="submit" class="btn btn-success flex-fill fw-bold" onclick="return confirmarLiquidacion()">
                                <i class="bi bi-lock"></i> Confirmar
                            </button>
                        </div>
                        <div class="form-text text-center mt-1">Confirmar bloquea la edición y genera certificado de retención.</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="bi bi-calculator display-4"></i>
                        <p class="mt-2">Complete los datos y haga clic en <strong>"Calcular Retenciones"</strong> para ver el resultado.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<script>
let comprobantesArcaData = [];

function toggleOrigen() {
    const tipo = document.getElementById('tipoOrigen').value;
    document.getElementById('divArca').style.display = (tipo === 'ARCA') ? '' : 'none';
    document.getElementById('divManual').style.display = (tipo === 'OTROS_PAGOS') ? '' : 'none';
    if (tipo === 'OTROS_PAGOS') {
        deseleccionarArca();
    }
}

function cargarFacturasArca() {
    const empId = document.getElementById('empresaId').value;
    const tbody = document.getElementById('tbodyArca');
    const contador = document.getElementById('arcaContador');

    if (!empId) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3"><i class="bi bi-arrow-up-circle"></i> Seleccione una empresa para ver sus comprobantes</td></tr>';
        contador.textContent = '0 comprobantes';
        comprobantesArcaData = [];
        deseleccionarArca();
        return;
    }

    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Cargando comprobantes ARCA...</td></tr>';

    fetch('api_facturas_arca.php?empresa_id=' + empId)
        .then(r => r.json())
        .then(data => {
            comprobantesArcaData = data;
            renderTablaArca(data);
            contador.textContent = data.length + ' comprobante(s)';

            // Si hay un comprobante preseleccionado, marcarlo
            const presel = document.getElementById('comprobante_arca_id').value;
            if (presel) {
                const comp = data.find(c => c.id == presel);
                if (comp) seleccionarArca(comp, false);
            }
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3"><i class="bi bi-exclamation-triangle"></i> Error al cargar comprobantes</td></tr>';
        });
}

function renderTablaArca(data) {
    const tbody = document.getElementById('tbodyArca');
    const selId = document.getElementById('comprobante_arca_id').value;

    if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3"><i class="bi bi-inbox"></i> No hay comprobantes ARCA para esta empresa</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(c => {
        const isSelected = (selId && c.id == selId);
        const pagadoTotal = c.pagado_total;
        const tienePagos = c.usado_en_liq;
        const seleccionable = !pagadoTotal || isSelected;
        const rowClass = isSelected ? 'table-success fw-bold' : (pagadoTotal ? 'table-light text-muted' : (tienePagos ? 'table-warning-subtle' : ''));
        const cursor = seleccionable ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;';

        let estadoBadge = '';
        if (isSelected) {
            estadoBadge = '<span class="badge bg-success"><i class="bi bi-check"></i> Seleccionado</span>';
        } else if (pagadoTotal) {
            estadoBadge = '<span class="badge bg-secondary">Pagado total</span>';
        } else if (tienePagos) {
            estadoBadge = '<span class="badge bg-warning text-dark">Parcial $' + c.saldo_fmt + '</span>';
        } else {
            estadoBadge = '<span class="badge bg-light text-success border">Disponible</span>';
        }

        return `<tr class="${rowClass}" style="${cursor}" 
                    onclick="${seleccionable ? 'seleccionarArcaById(' + c.id + ')' : ''}" 
                    data-id="${c.id}" data-numero="${c.numero_completo}">
            <td>${c.fecha}</td>
            <td><span class="badge bg-primary bg-opacity-75">${c.tipo}</span></td>
            <td class="fw-bold">${c.numero_completo}</td>
            <td class="text-end fw-bold">$ ${c.total_fmt}</td>
            <td class="text-end">$ ${c.iva_fmt}</td>
            <td class="text-end">$ ${c.neto_fmt}</td>
            <td class="text-center">${estadoBadge}</td>
        </tr>`;
    }).join('');
}

function seleccionarArcaById(id) {
    const comp = comprobantesArcaData.find(c => c.id === id);
    if (comp && !comp.pagado_total) {
        seleccionarArca(comp, true);
    }
}

function seleccionarArca(comp, rerender) {
    // Guardar ID
    document.getElementById('comprobante_arca_id').value = comp.id;

    // Mostrar resumen
    const divSel = document.getElementById('arcaSeleccionado');
    divSel.style.cssText = '';
    let resumen = '<span class="badge bg-primary ms-1">' + comp.tipo + '</span> ' +
        '<strong>' + comp.numero_completo + '</strong> | ' +
        comp.fecha + ' | ' +
        '<strong class="text-success">$ ' + comp.total_fmt + '</strong>' +
        ' (IVA: $ ' + comp.iva_fmt + ' / Neto: $ ' + comp.neto_fmt + ')';
    if (comp.usado_en_liq && comp.saldo > 0) {
        resumen += '<br><small class="text-warning"><i class="bi bi-exclamation-triangle"></i> Pagado parcial: $' + comp.total_pagado_fmt + ' | <strong>Saldo: $' + comp.saldo_fmt + '</strong></small>';
    }
    document.getElementById('arcaSelResumen').innerHTML = resumen;

    // Precargar importe_pago con saldo pendiente (o total si no hay pagos previos)
    const inputPago = document.getElementById('importePago');
    if (inputPago) {
        const monto = (comp.usado_en_liq && comp.saldo > 0) ? comp.saldo : comp.total;
        inputPago.value = monto.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // Re-render tabla para marcar seleccionado
    if (rerender) renderTablaArca(comprobantesArcaData);
}

function deseleccionarArca() {
    document.getElementById('comprobante_arca_id').value = '';
    const divSel = document.getElementById('arcaSeleccionado');
    divSel.style.cssText = 'display:none !important';
    document.getElementById('arcaSelResumen').textContent = '';
    renderTablaArca(comprobantesArcaData);
}

// Búsqueda en tabla ARCA
document.addEventListener('DOMContentLoaded', () => {
    const inputBuscar = document.getElementById('buscarArca');
    if (inputBuscar) {
        inputBuscar.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            if (!q) {
                renderTablaArca(comprobantesArcaData);
                return;
            }
            const filtrados = comprobantesArcaData.filter(c => 
                c.numero_completo.includes(q) || 
                c.tipo.toLowerCase().includes(q) || 
                c.fecha.includes(q) ||
                c.total_fmt.includes(q)
            );
            renderTablaArca(filtrados);
            document.getElementById('arcaContador').textContent = filtrados.length + ' de ' + comprobantesArcaData.length + ' comprobante(s)';
        });
    }

    // Cargar al inicio si ya hay empresa
    if (document.getElementById('empresaId').value) cargarFacturasArca();

    // Inicializar Select2 en empresa y obra
    $('.select2-empresa').select2({
        theme: 'bootstrap-5',
        placeholder: 'Buscar empresa por CUIT o razón social...',
        allowClear: true,
        width: '100%'
    }).on('change', function() {
        cargarFacturasArca();
    });

    $('.select2-obra').select2({
        theme: 'bootstrap-5',
        placeholder: 'Buscar obra por denominación...',
        allowClear: true,
        width: '100%'
    });
});

// Formateo automático de campos de moneda (separador de miles con punto, decimales con coma)
function fmtMoneyInput(el) {
    let val = el.value.replace(/\./g, '').replace(',', '.');
    let num = parseFloat(val);
    if (isNaN(num)) return;
    el.value = num.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
document.addEventListener('DOMContentLoaded', function() {
    ['importePago', 'fondoReparoMonto', 'override_suss', 'override_ganancias', 'override_iibb', 'override_iva', 'ret_otras_monto', 'multas_monto'].forEach(function(n) {
        // Buscar por id o por name
        var el = document.getElementById(n) || document.querySelector('[name="' + n + '"]');
        if (el) el.addEventListener('blur', function() { fmtMoneyInput(this); });
    });
});

function onIibbCategoriaChange() {
    const sel = document.getElementById('iibbCategoriaId');
    const opt = sel.options[sel.selectedIndex];
    const alicuotaInput = document.getElementById('iibbAlicuota');
    if (opt && opt.dataset.alicuota) {
        alicuotaInput.value = opt.dataset.alicuota.replace('.', ',');
    } else {
        alicuotaInput.value = '';
    }
}

function confirmarLiquidacion() {
    if (!confirm('¿Confirmar esta liquidación? Una vez confirmada NO podrá editarse y se generará el certificado de retención.')) {
        return false;
    }
    document.getElementById('accionForm').value = 'confirmar';
    return true;
}
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

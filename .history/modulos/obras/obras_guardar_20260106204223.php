<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// --- Funciones Auxiliares ---
function post($k, $default='') {
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

function parseMonto($valor): float {
    if ($valor === null) return 0.0;
    $v = trim((string)$valor);
    if ($v === '') return 0.0;
    // Elimina puntos de miles, cambia coma por punto
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

function parsePct($valor): float {
    if ($valor === null) return 0.0;
    $v = trim((string)$valor);
    if ($v === '') return 0.0;
    // Asegura formato float estándar
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

// --- Recepción de Datos ---
$id = (int)($_POST['id'] ?? 0);

// Nuevo campo: Empresa Contratista
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;

$codigo_interno = post('codigo_interno');
$expediente = post('expediente');
$denominacion = post('denominacion');
$tipo_obra_id = (int)($_POST['tipo_obra_id'] ?? 0);
$estado_obra_id = (int)($_POST['estado_obra_id'] ?? 0);
$ubicacion = post('ubicacion');
$fecha_inicio = post('fecha_inicio') ?: null;
$fecha_fin_prevista = post('fecha_fin_prevista') ?: null;
$plazo_dias_original = post('plazo_dias_original') !== '' ? (int)post('plazo_dias_original') : null;
$moneda = post('moneda','ARS');
$periodo_base = post('periodo_base') ?: null;

$monto_original = parseMonto($_POST['monto_original'] ?? 0);

// --- CAMBIO AQUI: Capturamos el monto actualizado enviado ---
$monto_actualizado = parseMonto($_POST['monto_actualizado'] ?? 0);

// Si es nuevo y no se puso actualizado, igualamos al original
if ($id == 0 && $monto_actualizado == 0) {
    $monto_actualizado = $monto_original;
}

$anticipo_pct = parsePct($_POST['anticipo_pct'] ?? 0);
$anticipo_monto = parseMonto($_POST['anticipo_monto'] ?? 0);
$observaciones = post('observaciones');

// Validaciones básicas
if ($denominacion === '' || $tipo_obra_id <= 0 || $estado_obra_id <= 0) {
    http_response_code(400);
    echo "Faltan datos obligatorios (Denominación, Tipo, Estado).";
    exit;
}

try {
    $pdo->beginTransaction();

    // ---------------------------------------------------------
    // 1. INSERT / UPDATE DE LA OBRA
    // ---------------------------------------------------------
    if ($id > 0) {
        $stmt = $pdo->prepare("
          UPDATE obras SET
            empresa_id=?, codigo_interno=?, expediente=?, denominacion=?, tipo_obra_id=?, estado_obra_id=?,
            ubicacion=?, fecha_inicio=?, fecha_fin_prevista=?, plazo_dias_original=?,
            moneda=?, periodo_base=?, monto_original=?, monto_actualizado=?, 
            anticipo_pct=?, anticipo_monto=?, observaciones=?
          WHERE id=? AND activo=1
        ");
        $stmt->execute([
          $empresa_id, $codigo_interno, $expediente, $denominacion, $tipo_obra_id, $estado_obra_id,
          $ubicacion, $fecha_inicio, $fecha_fin_prevista, $plazo_dias_original,
          $moneda, $periodo_base, $monto_original, $monto_actualizado, 
          $anticipo_pct, $anticipo_monto, $observaciones, $id
        ]);
    } else {
        $stmt = $pdo->prepare("
          INSERT INTO obras
            (empresa_id, codigo_interno, expediente, denominacion, tipo_obra_id, estado_obra_id, ubicacion,
             fecha_inicio, fecha_fin_prevista, plazo_dias_original, moneda, periodo_base,
             monto_original, monto_actualizado, anticipo_pct, anticipo_monto, observaciones, activo)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ");
        $stmt->execute([
          $empresa_id, $codigo_interno, $expediente, $denominacion, $tipo_obra_id, $estado_obra_id, $ubicacion,
          $fecha_inicio, $fecha_fin_prevista, $plazo_dias_original, $moneda, $periodo_base,
          $monto_original, $monto_actualizado, $anticipo_pct, $anticipo_monto, $observaciones
        ]);
        $id = (int)$pdo->lastInsertId();
    }

    // ---------------------------------------------------------
    // 2. SECCIÓN PARTIDA PRESUPUESTARIA
    // ---------------------------------------------------------
    $p = [
      'ejercicio'=>post('ejercicio'), 'cpn1'=>post('cpn1'), 'cpn2'=>post('cpn2'), 'cpn3'=>post('cpn3'),
      'juri'=>post('juri'),'sa'=>post('sa'),'unor'=>post('unor'),'fina'=>post('fina'),'func'=>post('func'),'subf'=>post('subf'),
      'inci'=>post('inci'),'ppal'=>post('ppal'),'ppar'=>post('ppar'),'spar'=>post('spar'),'fufi'=>post('fufi'),'ubge'=>post('ubge'),'defc'=>post('defc'),
      'denominacion1'=>post('denominacion1'),'denominacion2'=>post('denominacion2'),'denominacion3'=>post('denominacion3'),
      'imputacion_codigo'=>post('imputacion_codigo'),
      'vigente_desde'=>post('vigente_desde') ?: null,
      'vigente_hasta'=>post('vigente_hasta') ?: null,
    ];

    // Verificar si viene algún dato de partida
    $hayPartida = false;
    foreach ($p as $k => $v) {
        if (!in_array($k, ['vigente_desde','vigente_hasta'])) {
            if (trim((string)$v) !== '') { $hayPartida = true; break; }
        }
    }

    if ($hayPartida) {
        // Desactivamos la anterior
        $stmt = $pdo->prepare("UPDATE obra_partida SET activo=0 WHERE obra_id=?");
        $stmt->execute([$id]);

        // Insertamos la nueva
        $stmt = $pdo->prepare("
          INSERT INTO obra_partida
            (obra_id, ejercicio, cpn1, cpn2, cpn3, juri, sa, unor, fina, func, subf, inci, ppal, ppar, spar, fufi, ubge, defc,
             denominacion1, denominacion2, denominacion3, imputacion_codigo, vigente_desde, vigente_hasta, activo)
          VALUES
            (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ");
        $stmt->execute([
          $id, $p['ejercicio'], $p['cpn1'], $p['cpn2'], $p['cpn3'], 
          $p['juri'], $p['sa'], $p['unor'], $p['fina'], $p['func'], $p['subf'], $p['inci'],
          $p['ppal'], $p['ppar'], $p['spar'], $p['fufi'], $p['ubge'], $p['defc'],
          $p['denominacion1'], $p['denominacion2'], $p['denominacion3'], $p['imputacion_codigo'],
          $p['vigente_desde'], $p['vigente_hasta']
        ]);
    }

    // ---------------------------------------------------------
    // 3. SECCIÓN FUENTES DE FINANCIAMIENTO (NUEVO)
    // ---------------------------------------------------------
    
    // a) Limpiamos configuración previa (Estrategia: Borrar y Recrear)
    $stmtDel = $pdo->prepare("DELETE FROM obra_fuentes_config WHERE obra_id = ?");
    $stmtDel->execute([$id]);

    // b) Insertamos las nuevas
    if (isset($_POST['fuente_id']) && is_array($_POST['fuente_id'])) {
        $stmtIns = $pdo->prepare("INSERT INTO obra_fuentes_config (obra_id, fuente_id, porcentaje) VALUES (?, ?, ?)");
        
        $fuentes_ids = $_POST['fuente_id'];
        $fuentes_pcts = $_POST['fuente_pct'] ?? [];

        for ($i = 0; $i < count($fuentes_ids); $i++) {
            $f_id = (int)$fuentes_ids[$i];
            // Parseamos el porcentaje con la función auxiliar para evitar errores con comas
            $f_pct = parsePct($fuentes_pcts[$i] ?? 0);

            // Solo guardamos si hay una fuente válida y el porcentaje es mayor a 0 (o lo permitimos en 0 si es placeholder)
            if ($f_id > 0) {
                $stmtIns->execute([$id, $f_id, $f_pct]);
            }
        }
    }

    $pdo->commit();
    header("Location: obras_listado.php?msg=guardado_ok");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error SQL: " . $e->getMessage()); 
}
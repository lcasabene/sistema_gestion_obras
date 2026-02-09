<?php
// obras_guardar.php - Modificado para soportar GeoJSON
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
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

function parsePct($valor): float {
    if ($valor === null) return 0.0;
    $v = trim((string)$valor);
    if ($v === '') return 0.0;
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

// --- Recepción de Datos Principales ---
$id = (int)($_POST['id'] ?? 0);
$empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
$organismo_financiador_id = !empty($_POST['organismo_financiador_id']) ? (int)$_POST['organismo_financiador_id'] : null;

$codigo_interno = post('codigo_interno');
$expediente = post('expediente');
$denominacion = post('denominacion');
$tipo_obra_id = (int)($_POST['tipo_obra_id'] ?? 0);
$estado_obra_id = (int)($_POST['estado_obra_id'] ?? 0);
$ubicacion = post('ubicacion');
$region = post('region') ?: null;

// --- Datos Técnicos y Geográficos ---
$organismo_requirente = post('organismo_requirente');
$titularidad_terreno = post('titularidad_terreno');
$superficie_desarrollo = post('superficie_desarrollo');
$caracteristicas_obra = post('caracteristicas_obra');
$memoria_objetivo = post('memoria_objetivo');
$latitud = !empty($_POST['latitud']) ? (float)str_replace(',', '.', $_POST['latitud']) : null;
$longitud = !empty($_POST['longitud']) ? (float)str_replace(',', '.', $_POST['longitud']) : null;

// GeoJSON
$geojson_data = isset($_POST['geojson_data']) ? $_POST['geojson_data'] : null;
if (trim($geojson_data) === '') $geojson_data = null;

// --- Datos Financieros ---
$fecha_inicio = post('fecha_inicio') ?: null;
$fecha_fin_prevista = post('fecha_fin_prevista') ?: null;
$plazo_dias_original = post('plazo_dias_original') !== '' ? (int)post('plazo_dias_original') : null;
$moneda = post('moneda', 'ARS');
$periodo_base = post('periodo_base') ?: null;

$monto_original = parseMonto($_POST['monto_original'] ?? 0);
$monto_actualizado = parseMonto($_POST['monto_actualizado'] ?? 0);
$anticipo_pct = parsePct($_POST['anticipo_pct'] ?? 0);
$anticipo_monto = parseMonto($_POST['anticipo_monto'] ?? 0);
$observaciones = post('observaciones');

if ($denominacion === '' || $tipo_obra_id <= 0 || $estado_obra_id <= 0) {
    die("Error: Faltan datos obligatorios.");
}

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        // --- ACTUALIZACIÓN ---
        $sql = "UPDATE obras SET 
            empresa_id=?, organismo_financiador_id=?, codigo_interno=?, expediente=?, denominacion=?, 
            tipo_obra_id=?, estado_obra_id=?, ubicacion=?, region=?, organismo_requirente=?, 
            titularidad_terreno=?, superficie_desarrollo=?, caracteristicas_obra=?, memoria_objetivo=?, 
            latitud=?, longitud=?, geojson_data=?, fecha_inicio=?, fecha_fin_prevista=?, plazo_dias_original=?, 
            moneda=?, periodo_base=?, monto_original=?, monto_actualizado=?, 
            anticipo_pct=?, anticipo_monto=?, observaciones=? 
            WHERE id=? AND activo=1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $empresa_id, $organismo_financiador_id, $codigo_interno, $expediente, $denominacion,
            $tipo_obra_id, $estado_obra_id, $ubicacion, $region, $organismo_requirente,
            $titularidad_terreno, $superficie_desarrollo, $caracteristicas_obra, $memoria_objetivo,
            $latitud, $longitud, $geojson_data, $fecha_inicio, $fecha_fin_prevista, $plazo_dias_original,
            $moneda, $periodo_base, $monto_original, $monto_actualizado,
            $anticipo_pct, $anticipo_monto, $observaciones, $id
        ]);
    } else {
        // --- INSERCIÓN ---
        $sql = "INSERT INTO obras (
            empresa_id, organismo_financiador_id, codigo_interno, expediente, denominacion, 
            tipo_obra_id, estado_obra_id, ubicacion, region, organismo_requirente, 
            titularidad_terreno, superficie_desarrollo, caracteristicas_obra, memoria_objetivo, 
            latitud, longitud, geojson_data, fecha_inicio, fecha_fin_prevista, plazo_dias_original, 
            moneda, periodo_base, monto_original, monto_actualizado, 
            anticipo_pct, anticipo_monto, observaciones, activo
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $empresa_id, $organismo_financiador_id, $codigo_interno, $expediente, $denominacion,
            $tipo_obra_id, $estado_obra_id, $ubicacion, $region, $organismo_requirente,
            $titularidad_terreno, $superficie_desarrollo, $caracteristicas_obra, $memoria_objetivo,
            $latitud, $longitud, $geojson_data, $fecha_inicio, $fecha_fin_prevista, $plazo_dias_original,
            $moneda, $periodo_base, $monto_original, $monto_actualizado,
            $anticipo_pct, $anticipo_monto, $observaciones
        ]);
        $id = (int)$pdo->lastInsertId();
    }

    // --- PARTIDA ---
    $pdo->prepare("DELETE FROM obra_partida WHERE obra_id=?")->execute([$id]);
    $stmtP = $pdo->prepare("INSERT INTO obra_partida (
        obra_id, ejercicio, cpn1, cpn2, cpn3, juri, sa, unor, fina, func, subf, 
        inci, ppal, ppar, spar, fufi, ubge, defc, denominacion1, denominacion2, 
        denominacion3, imputacion_codigo, activo
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    $stmtP->execute([
        $id, post('ejercicio'), post('cpn1'), post('cpn2'), post('cpn3'), 
        post('juri'), post('sa'), post('unor'), post('fina'), post('func'), post('subf'),
        post('inci'), post('ppal'), post('ppar'), post('spar'), post('fufi'), post('ubge'), 
        post('defc'), post('denominacion1'), post('denominacion2'), post('denominacion3'), post('imputacion_codigo')
    ]);

    // --- FUENTES ---
    $pdo->prepare("DELETE FROM obra_fuentes_config WHERE obra_id=?")->execute([$id]);
    if (isset($_POST['fuente_id']) && is_array($_POST['fuente_id'])) {
        $stmtF = $pdo->prepare("INSERT INTO obra_fuentes_config (obra_id, fuente_id, porcentaje) VALUES (?,?,?)");
        foreach ($_POST['fuente_id'] as $k => $f_id) {
            if (!empty($f_id)) {
                $stmtF->execute([$id, (int)$f_id, parsePct($_POST['fuente_pct'][$k] ?? 0)]);
            }
        }
    }

    $pdo->commit();
    header("Location: obras_listado.php?msg=ok");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error al guardar obra: " . $e->getMessage());
}
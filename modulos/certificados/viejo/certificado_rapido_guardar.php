<?php
// modulos/certificados/certificado_rapido_guardar.php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$basePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $basePath . 'config/database.php';

function parseArNumber($str): float {
    if ($str === null) return 0.0;
    $s = trim((string)$str);
    if ($s === '') return 0.0;
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    $n = (float)$s;
    return is_finite($n) ? $n : 0.0;
}

function detectObraMontoCols(PDO $pdo): array {
    $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();

    $st = $pdo->prepare("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = :db
          AND table_name IN ('obras','obra')
        ORDER BY FIELD(table_name,'obras','obra')
        LIMIT 1
    ");
    $st->execute([':db' => $dbName]);
    $table = $st->fetchColumn();
    if (!$table) return [null, null, null, null];

    $st = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = :db AND table_name = :t
    ");
    $st->execute([':db' => $dbName, ':t' => $table]);
    $cols = $st->fetchAll(PDO::FETCH_COLUMN);
    $map = [];
    foreach ($cols as $c) $map[strtolower((string)$c)] = (string)$c;

    $contratoCandidates = [
        'monto_contrato','monto_contrato_original','monto_original','monto_total',
        'monto_adjudicado','monto_obra','monto','importe_contrato','importe_total',
        'precio_original','precio_contrato','contrato_monto','monto_base'
    ];
    $anticipoMontoCandidates = ['anticipo_monto','monto_anticipo','anticipo','anticipo_financiero','anticipo_original'];
    $anticipoPctCandidates = ['anticipo_pct','anticipo_porcentaje','porcentaje_anticipo','anticipo_percent'];

    $contratoCol = null;
    foreach ($contratoCandidates as $c) { if (isset($map[$c])) { $contratoCol = $map[$c]; break; } }

    $anticipoMontoCol = null;
    foreach ($anticipoMontoCandidates as $c) { if (isset($map[$c])) { $anticipoMontoCol = $map[$c]; break; } }

    $anticipoPctCol = null;
    foreach ($anticipoPctCandidates as $c) { if (isset($map[$c])) { $anticipoPctCol = $map[$c]; break; } }

    return [(string)$table, $contratoCol, $anticipoMontoCol, $anticipoPctCol];
}

function getBaseObra(PDO $pdo, int $obra_id): array {
    [$table, $contratoCol, $anticipoMontoCol, $anticipoPctCol] = detectObraMontoCols($pdo);
    if (!$table || !$contratoCol) {
        throw new RuntimeException('No se pudo detectar contrato original en la tabla de obras.');
    }

    $selectCols = ["id", "`$contratoCol` AS contrato_original"];
    if ($anticipoMontoCol) $selectCols[] = "`$anticipoMontoCol` AS anticipo_monto";
    if ($anticipoPctCol) $selectCols[] = "`$anticipoPctCol` AS anticipo_pct";

    $sql = "SELECT " . implode(", ", $selectCols) . " FROM `$table` WHERE id = :id LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $obra_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('No se encontró la obra.');

    $contrato = (float)$row['contrato_original'];
    $anticipo = 0.0;

    if (isset($row['anticipo_monto'])) {
        $anticipo = (float)$row['anticipo_monto'];
    } elseif (isset($row['anticipo_pct'])) {
        $pct = (float)$row['anticipo_pct'];
        $anticipo = $contrato * ($pct / 100.0);
    }

    return [round($contrato,2), round($anticipo,2)];
}

try {
    $obra_id = isset($_POST['obra_id']) ? (int)$_POST['obra_id'] : 0;
    if ($obra_id <= 0) throw new RuntimeException('Obra inválida.');

    [$contratoOriginal, $anticipoOriginal] = getBaseObra($pdo, $obra_id);

    $periodos = $_POST['periodo'] ?? [];
    $pctList  = $_POST['pct_neto'] ?? [];
    $frList   = $_POST['fondo_reparo'] ?? [];
    $muList   = $_POST['multas'] ?? [];
    $otList   = $_POST['otros_desc'] ?? [];

    $count = count($periodos);
    if ($count === 0) throw new RuntimeException('No hay filas para guardar.');

    // nro correlativo por obra
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(nro),0) AS max_nro FROM certificados WHERE obra_id = :obra_id");
    $stmt->execute([':obra_id' => $obra_id]);
    $nextNro = ((int)($stmt->fetch(PDO::FETCH_ASSOC)['max_nro'] ?? 0)) + 1;

    $pdo->beginTransaction();

    $ins = $pdo->prepare("
        INSERT INTO certificados (
            obra_id, nro, periodo,
            monto_certificado, anticipo_desc, fondo_reparo, multas, otros_desc,
            importe_a_pagar,
            estado, activo
        ) VALUES (
            :obra_id, :nro, :periodo,
            :monto_certificado, :anticipo_desc, :fondo_reparo, :multas, :otros_desc,
            :importe_a_pagar,
            :estado, :activo
        )
    ");

    $insertados = 0;

    for ($i=0; $i<$count; $i++) {
        $periodo = trim((string)($periodos[$i] ?? ''));
        if ($periodo === '') continue;

        $pct = parseArNumber($pctList[$i] ?? '0');
        if ($pct <= 0) continue;

        // Regla nueva (FORZADA en backend)
        $monto = $contratoOriginal * ($pct / 100.0);
        $anticipoDesc = $anticipoOriginal * ($pct / 100.0);

        $fr = parseArNumber($frList[$i] ?? '0');
        $mu = parseArNumber($muList[$i] ?? '0');
        $ot = parseArNumber($otList[$i] ?? '0');

        $importe = $monto - ($anticipoDesc + $fr + $mu + $ot);

        $ins->execute([
            ':obra_id' => $obra_id,
            ':nro' => $nextNro++,
            ':periodo' => $periodo,
            ':monto_certificado' => round($monto, 2),
            ':anticipo_desc' => round($anticipoDesc, 2),
            ':fondo_reparo' => round($fr, 2),
            ':multas' => round($mu, 2),
            ':otros_desc' => round($ot, 2),
            ':importe_a_pagar' => round($importe, 2),
            ':estado' => 'CARGADO',
            ':activo' => 1
        ]);

        $insertados++;
    }

    $pdo->commit();

    header("Location: ../curva/curva_view.php?obra_id={$obra_id}&msg=" . urlencode("Guardados: {$insertados}"));
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "<h3>Error al guardar</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}

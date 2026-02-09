<?php
// modulos/certificados/certificado_rapido_get_plan.php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$basePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $basePath . 'config/database.php'; // $pdo (PDO)

function getIntParam(string $name, int $default = 0): int {
    if (isset($_GET[$name])) return (int)$_GET[$name];
    if (isset($_POST[$name])) return (int)$_POST[$name];
    return $default;
}

function tableExists(PDO $pdo, string $table): bool {
    $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = :db AND table_name = :t
    ");
    $st->execute([':db' => $db, ':t' => $table]);
    return ((int)$st->fetchColumn() > 0);
}

function columnExists(PDO $pdo, string $table, string $col): bool {
    $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = :db AND table_name = :t AND column_name = :c
    ");
    $st->execute([':db' => $db, ':t' => $table, ':c' => $col]);
    return ((int)$st->fetchColumn() > 0);
}

try {
    $debug = (isset($_GET['debug']) && $_GET['debug'] === '1') || (isset($_POST['debug']) && $_POST['debug'] === '1');

    $obra_id = getIntParam('obra_id', 0);
    if ($obra_id <= 0) $obra_id = getIntParam('id', 0);

    if ($obra_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'obra_id inválido',
            'debug' => $debug ? ['received_GET' => $_GET] : null
        ]);
        exit;
    }

    // 1) Contrato original
    $stmt = $pdo->prepare("SELECT monto_original FROM obras WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $obra_id]);
    $obra = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$obra) throw new RuntimeException('No se encontró la obra en tabla obras.');

    $contrato_original = (float)$obra['monto_original'];
    if ($contrato_original <= 0) {
        throw new RuntimeException('La obra no tiene monto_original válido.');
    }

    // 2) Curva vigente
    $stmt = $pdo->prepare("
        SELECT id, nro_version, modo, fecha_desde, fecha_hasta
        FROM curva_version
        WHERE obra_id = :obra_id AND es_vigente = 1
        ORDER BY nro_version DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([':obra_id' => $obra_id]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$version) {
        echo json_encode([
            'ok' => true,
            'vigente' => false,
            'version' => null,
            'periodos' => new stdClass(),
            'base' => [
                'contrato_original' => round($contrato_original, 2),
                'anticipo_original' => 0.0
            ],
            'message' => 'No hay curva vigente para la obra.'
        ]);
        exit;
    }

    $vid = (int)$version['id'];

    $periodos = [];
    $anticipo_original = 0.0;
    $source = null;

    // Helper: calcula % plan sobre contrato
    $calcPct = function(float $monto) use ($contrato_original): float {
        if ($contrato_original <= 0) return 0.0;
        return round(($monto / $contrato_original) * 100.0, 6);
    };

    // 3) Preferencia: curva_detalle_fuente
    if (tableExists($pdo, 'curva_detalle_fuente')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM curva_detalle_fuente WHERE curva_version_id = :vid");
        $stmt->execute([':vid' => $vid]);
        $cantFuente = (int)$stmt->fetchColumn();

        if ($cantFuente > 0) {
            $stmt = $pdo->prepare("
                SELECT
                    periodo,
                    ROUND(SUM(monto_bruto_plan), 2) AS bruto_plan,
                    ROUND(SUM(monto_neto_plan), 2) AS neto_plan,
                    ROUND(SUM(anticipo_pago_plan), 2) AS anticipo_pago_plan,
                    ROUND(SUM(anticipo_recupero_plan), 2) AS anticipo_recupero_plan
                FROM curva_detalle_fuente
                WHERE curva_version_id = :vid
                GROUP BY periodo
                ORDER BY periodo
            ");
            $stmt->execute([':vid' => $vid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $p = (string)$r['periodo'];
                $bruto = (float)$r['bruto_plan'];
                $neto  = (float)$r['neto_plan'];
                // PRIORIDAD: neto (es el que representa el plan en tu base)
                $montoPlan = ($neto > 0) ? $neto : $bruto;
                $pctPlan = $calcPct($montoPlan);
                $periodos[$p] = [
                    'monto_plan_periodo' => round($montoPlan, 2),
                    'pct_plan' => $pctPlan,
                    'bruto_plan' => $bruto,
                    'neto_plan' => $neto,
                    'anticipo_pago_plan' => (float)$r['anticipo_pago_plan'],
                    'anticipo_recupero_plan' => (float)$r['anticipo_recupero_plan'],
                ];

                $anticipo_original += (float)$r['anticipo_pago_plan'];
            }

            $source = 'curva_detalle_fuente';
        }
    }

    // 4) Fallback: curva_detalle (tu caso actual)
    if (count($periodos) === 0 && tableExists($pdo, 'curva_detalle')) {

        $okPeriodo = columnExists($pdo, 'curva_detalle', 'periodo');
        $okVid = columnExists($pdo, 'curva_detalle', 'curva_version_id');

        if (!$okPeriodo || !$okVid) {
            throw new RuntimeException('curva_detalle no tiene columnas mínimas (periodo, curva_version_id).');
        }

        $hasBruto = columnExists($pdo, 'curva_detalle', 'monto_bruto_plan');
        $hasNeto  = columnExists($pdo, 'curva_detalle', 'monto_neto_plan');
        $hasAntPago = columnExists($pdo, 'curva_detalle', 'anticipo_pago_plan');
        $hasAntRec  = columnExists($pdo, 'curva_detalle', 'anticipo_recupero_plan');

        // Si no hay ni bruto ni neto, no hay con qué calcular %
        if (!$hasBruto && !$hasNeto) {
            throw new RuntimeException('curva_detalle no tiene monto_bruto_plan ni monto_neto_plan para calcular %.');
        }

        $select = "periodo";
        $select .= $hasBruto ? ", ROUND(SUM(monto_bruto_plan),2) AS bruto_plan" : ", 0 AS bruto_plan";
        $select .= $hasNeto  ? ", ROUND(SUM(monto_neto_plan),2)  AS neto_plan"  : ", 0 AS neto_plan";
        $select .= $hasAntPago ? ", ROUND(SUM(anticipo_pago_plan),2) AS anticipo_pago_plan" : ", 0 AS anticipo_pago_plan";
        $select .= $hasAntRec  ? ", ROUND(SUM(anticipo_recupero_plan),2) AS anticipo_recupero_plan" : ", 0 AS anticipo_recupero_plan";

        $sql = "
            SELECT $select
            FROM curva_detalle
            WHERE curva_version_id = :vid
            GROUP BY periodo
            ORDER BY periodo
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':vid' => $vid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $p = (string)$r['periodo'];
            $bruto = (float)$r['bruto_plan'];
            $neto  = (float)$r['neto_plan'];
            // PRIORIDAD: neto (es el que está cargado en curva_detalle)
            $montoPlan = ($neto > 0) ? $neto : $bruto;
            $pctPlan = $calcPct($montoPlan);
            $periodos[$p] = [
                'monto_plan_periodo' => round($montoPlan, 2),
                'pct_plan' => $pctPlan,
                'bruto_plan' => $bruto,
                'neto_plan' => $neto,
                'anticipo_pago_plan' => (float)$r['anticipo_pago_plan'],
                'anticipo_recupero_plan' => (float)$r['anticipo_recupero_plan'],
            ];

            if ($hasAntPago) {
                $anticipo_original += (float)$r['anticipo_pago_plan'];
            }
        }

        $source = 'curva_detalle';

        if (!$hasAntPago) {
            $anticipo_original = 0.0; // no existe dato en curva_detalle
        }
    }

    echo json_encode([
        'ok' => true,
        'vigente' => true,
        'version' => [
            'id' => $vid,
            'nro_version' => (int)$version['nro_version'],
            'modo' => $version['modo'],
            'fecha_desde' => $version['fecha_desde'],
            'fecha_hasta' => $version['fecha_hasta'],
        ],
        'periodos' => $periodos,
        'base' => [
            'contrato_original' => round($contrato_original, 2),
            'anticipo_original' => round($anticipo_original, 2)
        ],
        'debug' => $debug ? [
            'obra_id' => $obra_id,
            'curva_version_id' => $vid,
            'periodos_count' => count($periodos),
            'source' => $source
        ] : null
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../public/index.php"); exit; }

$basePath = __DIR__ . '/../../';
require_once $basePath . 'config/database.php';

// Configurar PDO para que lance excepciones en caso de error SQL (vital para depurar)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ==========================================================================
   HELPERS MATEMÁTICOS Y DE FECHA (Respetando lógica original)
   ========================================================================== */

function monthPeriods(DateTime $desde, DateTime $hasta): array {
    // Forzamos al día 1 para evitar saltos de mes erróneos (ej: 31 enero -> 3 marzo)
    $start = (clone $desde)->modify('first day of this month')->setTime(0,0,0);
    $end   = (clone $hasta)->modify('first day of this month')->setTime(0,0,0);
    $out = [];
    while ($start <= $end) { 
        $out[] = $start->format('Y-m-d'); // Usamos Y-m-d para compatibilidad SQL
        $start->modify('+1 month'); 
    }
    return $out;
}

function sCurveCum(float $t, float $k = 10.0): float {
    // Función Sigmoide para la curva S
    $x = $k * ($t - 0.5);
    $sig = 1.0 / (1.0 + exp(-$x));
    $sig0 = 1.0 / (1.0 + exp(-$k * (0.0 - 0.5)));
    $sig1 = 1.0 / (1.0 + exp(-$k * (1.0 - 0.5)));
    return ($sig - $sig0) / ($sig1 - $sig0);
}

function sCurvePercentages(int $n, float $k = 10.0): array {
    // Genera los porcentajes mensuales basados en la sigmoide
    if ($n <= 0) return [];
    if ($n === 1) return [100.0];
    $cum=[]; for ($i=0;$i<$n;$i++) $cum[$i]=sCurveCum($i/($n-1), $k);
    $per=[]; $prev=0.0;
    for ($i=0;$i<$n;$i++){ $per[$i]=$cum[$i]-$prev; $prev=$cum[$i]; }
    $sum=array_sum($per);
    
    // Ajuste de errores de redondeo
    if ($sum<=0) $per=array_fill(0,$n,1.0/$n);
    else foreach($per as $i=>$v) $per[$i]=$v/$sum;
    
    $pct=array_map(fn($v)=>round($v*100.0,4),$per);
    $diff=100.0-array_sum($pct);
    $pct[$n-1]=round($pct[$n-1]+$diff,4); // Ajustar diferencia al último mes
    return $pct;
}

function distributeAnticipoRecupero(array $montosBrutos, int $idxAnticipoPago, float $anticipoMonto): array {
    // Distribuye el descuento del anticipo proporcionalmente en el resto de los meses
    $n=count($montosBrutos);
    $rec=array_fill(0,$n,0.0);
    if ($anticipoMonto<=0) return $rec;

    $base=0.0;
    for($i=0;$i<$n;$i++){ if($i===$idxAnticipoPago) continue; $base += (float)$montosBrutos[$i]; }
    
    if($base<=0) {
        // Si no hay base (curva plana al inicio), descontamos todo al final
        if ($n > 1) $rec[$n-1] = $anticipoMonto; 
        return $rec;
    }

    $acum=0.0;
    for($i=0;$i<$n;$i++){
        if($i===$idxAnticipoPago) continue;
        $rec[$i]=round($anticipoMonto*((float)$montosBrutos[$i]/$base),2);
        $acum += $rec[$i];
    }
    
    // Ajuste final de decimales
    $diff=round($anticipoMonto-$acum,2);
    if(abs($diff)>0){
        for($i=$n-1;$i>=0;$i--){
            if($i===$idxAnticipoPago) continue;
            $rec[$i]=round($rec[$i]+$diff,2);
            break;
        }
    }
    return $rec;
}

/* ==========================================================================
   LÓGICA DE PROCESAMIENTO
   ========================================================================== */

// 1. Recepción de Parámetros
$obra_id = (int)($_POST['obra_id'] ?? 0);
$modo = $_POST['modo'] ?? 'S'; // 'S' (Curva S) o 'L' (Lineal)
$k = (float)($_POST['k'] ?? 10.0); // Pendiente de la curva

// Validaciones básicas iniciales
if ($obra_id <= 0) { 
    die("<h1>Error</h1><p>ID de obra no válido.</p><a href='javascript:history.back()'>Volver</a>"); 
}

$anticipo_monto = (float)($_POST['anticipo_monto'] ?? 0);
$anticipo_mes = $_POST['anticipo_mes'] ?? 'PRIMERO';
$anticipo_fuente_id = (int)($_POST['anticipo_fuente_id'] ?? 0);

if ($anticipo_monto > 0 && $anticipo_fuente_id <= 0) { 
    die("<h1>Error</h1><p>Si ingresa un monto de anticipo, debe seleccionar la Fuente de Financiamiento (FUFI).</p><a href='javascript:history.back()'>Volver</a>"); 
}

try {
    $pdo->beginTransaction();

    // 2. Obtener Datos de la Obra (Montos y Fechas)
    $st = $pdo->prepare("SELECT id, monto_actualizado, fecha_inicio, fecha_fin_prevista FROM obras WHERE id=? LIMIT 1");
    $st->execute([$obra_id]);
    $obra = $st->fetch(PDO::FETCH_ASSOC);

    if (!$obra) throw new Exception("Obra no encontrada en la base de datos.");

    // --- LÓGICA DE FECHAS (PRIORIDAD: POST -> DB) ---
    $fecha_desde = !empty($_POST['fecha_desde']) ? $_POST['fecha_desde'] : $obra['fecha_inicio'];
    $fecha_hasta = !empty($_POST['fecha_hasta']) ? $_POST['fecha_hasta'] : $obra['fecha_fin_prevista'];

    // Validar que tengamos fechas
    if (empty($fecha_desde) || empty($fecha_hasta)) {
        throw new Exception("La obra no tiene fechas definidas (Inicio/Fin) y no se enviaron manualmente.");
    }

    $dDesde = new DateTime($fecha_desde);
    $dHasta = new DateTime($fecha_hasta);
    
    if ($dHasta < $dDesde) throw new Exception("La fecha 'Hasta' es anterior a la fecha 'Desde'. Revise los plazos.");

    $montoActualizado = (float)($obra['monto_actualizado'] ?? 0);
    if ($montoActualizado <= 0) throw new Exception("La obra tiene un Monto Actualizado de 0. Por favor asigne un monto válido en 'Editar Obra'.");

    // 3. Generar Periodos
    $periodos = monthPeriods($dDesde, $dHasta);
    $n = count($periodos);
    if ($n <= 0) throw new Exception("El rango de fechas seleccionado no genera ningún mes válido.");

    // 4. Crear Nueva Versión
    $st = $pdo->prepare("SELECT COALESCE(MAX(nro_version),0)+1 FROM curva_version WHERE obra_id=?");
    $st->execute([$obra_id]);
    $nro_version = (int)$st->fetchColumn();

    // Desactivar versiones viejas
    $pdo->prepare("UPDATE curva_version SET es_vigente=0 WHERE obra_id=?")->execute([$obra_id]);

    // Insertar nueva
    $insV = $pdo->prepare("
        INSERT INTO curva_version (obra_id, nro_version, modo, fecha_desde, fecha_hasta, es_vigente, created_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW())
    ");
    $insV->execute([$obra_id, $nro_version, $modo, $dDesde->format('Y-m-d'), $dHasta->format('Y-m-d')]);
    $curva_version_id = (int)$pdo->lastInsertId();

    // 5. Cálculos Matemáticos de la Curva
    // Si es modo 'S' usa la sigmoide, si es 'L' divide linealmente
    $pcts = ($modo === 'S') ? sCurvePercentages($n, $k) : array_fill(0, $n, round(100.0/$n, 4));
    
    // Ajuste fino para asegurar suma 100% en lineal
    if ($modo !== 'S') {
        $pcts[$n-1] = round(100.0 - array_sum(array_slice($pcts, 0, $n-1)), 4);
    }

    // Calcular montos brutos
    $brutos = [];
    for ($i=0; $i<$n; $i++) {
        $brutos[$i] = round($montoActualizado * ($pcts[$i] / 100.0), 2);
    }

    // Calcular Anticipos y Recuperos
    $idxAnticipoPago = 0;
    if ($anticipo_mes === 'SEGUNDO' && $n >= 2) $idxAnticipoPago = 1;

    $anticipoPago = array_fill(0, $n, 0.0);
    if ($anticipo_monto > 0) $anticipoPago[$idxAnticipoPago] = round($anticipo_monto, 2);
    
    $anticipoRec = distributeAnticipoRecupero($brutos, $idxAnticipoPago, $anticipo_monto);

    // 6. Insertar Detalle Mensual (curva_detalle)
    $insD = $pdo->prepare("
      INSERT INTO curva_detalle
      (curva_version_id, periodo, porcentaje_plan,
       monto_plan, monto_bruto_plan, anticipo_pago_plan, anticipo_pago_modo, anticipo_pago_fuente_id,
       anticipo_recupero_plan, anticipo_rec_modo, anticipo_rec_fuente_id,
       monto_neto_plan)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    for ($i=0; $i<$n; $i++) {
        $per = $periodos[$i]; // Y-m-d
        $bruto = (float)$brutos[$i];
        $aPago = (float)$anticipoPago[$i];
        $aRec  = (float)$anticipoRec[$i];
        $neto  = round($bruto - $aRec + $aPago, 2);

        // Definir modos de imputación
        $pagoModo = ($aPago > 0) ? 'FUFI' : 'PARIPASSU';
        $pagoFufi = ($aPago > 0 && $anticipo_fuente_id > 0) ? $anticipo_fuente_id : null;

        $recModo  = ($aRec > 0) ? 'PARIPASSU' : 'PARIPASSU';
        $recFufi  = null;

        $insD->execute([
            $curva_version_id, $per, $pcts[$i],
            $neto, // monto_plan suele ser el neto a certificar
            round($bruto, 2), 
            round($aPago, 2), $pagoModo, $pagoFufi,
            round($aRec, 2),  $recModo,  $recFufi,
            $neto
        ]);
    }

    // 7. Gestión de Fuentes de Financiamiento (FUFI)
    // Obtenemos las fuentes asignadas a la obra
    $st = $pdo->prepare("
      SELECT ofu.fuente_id, ofu.porcentaje
      FROM obra_fuentes ofu
      INNER JOIN fuentes_financiamiento f ON f.id=ofu.fuente_id
      WHERE ofu.obra_id=? AND ofu.activo=1 AND f.activo=1
      ORDER BY f.codigo
    ");
    $st->execute([$obra_id]);
    $obraFuentes = $st->fetchAll(PDO::FETCH_ASSOC);

    // Si no tiene fuentes, creamos una genérica para no romper el cálculo
    if (count($obraFuentes) === 0) {
        $pdo->exec("INSERT IGNORE INTO fuentes_financiamiento (codigo,nombre,activo) VALUES ('SIN_FUFI','Tesoro / Sin Fuente',1)");
        $fid = (int)$pdo->query("SELECT id FROM fuentes_financiamiento WHERE codigo='SIN_FUFI' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT IGNORE INTO obra_fuentes (obra_id,fuente_id,porcentaje,activo) VALUES (?,?,100.000,1)")
            ->execute([$obra_id, $fid]);
        $obraFuentes = [[ 'fuente_id' => $fid, 'porcentaje' => 100.000 ]];
    }

    // Verificar que los porcentajes sumen algo
    $sumPct = 0.0; 
    foreach ($obraFuentes as $f) $sumPct += (float)$f['porcentaje'];
    if ($sumPct <= 0) throw new Exception("Las fuentes de financiamiento de la obra suman 0%. Configúrelas correctamente.");

    // 8. Insertar Detalle por Fuente (curva_detalle_fuente)
    $insF = $pdo->prepare("
      INSERT INTO curva_detalle_fuente
      (curva_version_id, periodo, fuente_id, porcentaje_fuente,
       monto_bruto_plan, anticipo_pago_plan, anticipo_recupero_plan, monto_neto_plan)
      VALUES (?,?,?,?,?,?,?,?)
    ");

    // Releer lo insertado en curva_detalle para asegurar consistencia
    $st = $pdo->prepare("
      SELECT periodo, monto_bruto_plan, anticipo_pago_plan, anticipo_pago_modo, anticipo_pago_fuente_id,
             anticipo_recupero_plan, anticipo_rec_modo, anticipo_rec_fuente_id
      FROM curva_detalle WHERE curva_version_id=? ORDER BY periodo ASC
    ");
    $st->execute([$curva_version_id]);
    $detCurva = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($detCurva as $d) {
        $per = $d['periodo'];
        $brutoMes = (float)$d['monto_bruto_plan'];
        $aPagoMes = (float)$d['anticipo_pago_plan'];
        $aRecMes  = (float)$d['anticipo_recupero_plan'];

        $pagoModo = $d['anticipo_pago_modo'];
        $pagoFufi = (int)($d['anticipo_pago_fuente_id'] ?? 0);

        $recModo = $d['anticipo_rec_modo'];
        $recFufi = (int)($d['anticipo_rec_fuente_id'] ?? 0);

        foreach ($obraFuentes as $f) {
            $fuenteId = (int)$f['fuente_id'];
            $pct = (float)$f['porcentaje'] / $sumPct * 100.0;

            $brutoF = round($brutoMes * ($pct / 100.0), 2);

            // Calcular Pago de Anticipo por Fuente
            $pagoF = 0.0;
            if ($aPagoMes > 0) {
                if ($pagoModo === 'FUFI') {
                    // Si el modo es FUFI, solo paga la fuente seleccionada
                    $pagoF = ($fuenteId === $pagoFufi) ? round($aPagoMes, 2) : 0.0;
                } else { 
                    // Si es PARIPASSU, pagan todas según porcentaje
                    $pagoF = round($aPagoMes * ($pct / 100.0), 2);
                }
            }

            // Calcular Recupero por Fuente
            $recF = 0.0;
            if ($aRecMes > 0) {
                if ($recModo === 'FUFI' && $recFufi > 0) {
                    $recF = ($fuenteId === $recFufi) ? round($aRecMes, 2) : 0.0;
                } else { 
                    $recF = round($aRecMes * ($pct / 100.0), 2);
                }
            }

            $netoF = round($brutoF - $recF + $pagoF, 2);
            $insF->execute([$curva_version_id, $per, $fuenteId, round($pct, 3), $brutoF, $pagoF, $recF, $netoF]);
        }
    }

    $pdo->commit();
    
    // Redirección al éxito
    header("Location: curva_view.php?obra_id=" . $obra_id);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    
    // Pantalla de error amigable
    echo "<div style='font-family: sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #dc3545; background-color: #fff8f8; border-radius: 8px;'>";
    echo "<h2 style='color: #dc3545; margin-top:0;'>Error al Generar Curva</h2>";
    echo "<p>Ocurrió un problema al procesar los datos:</p>";
    echo "<div style='background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;'>";
    echo htmlspecialchars($e->getMessage());
    echo "</div>";
    echo "<br><a href='javascript:history.back()' style='display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px;'>&larr; Volver atrás</a>";
    echo "</div>";
    exit;
}
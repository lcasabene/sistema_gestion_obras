<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../public/index.php"); exit; }

$basePath = __DIR__ . '/../../';
require_once $basePath . 'config/database.php';

// FORZAR REPORTE DE ERRORES SQL
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ===== Helpers ===== */
function monthPeriods(DateTime $desde, DateTime $hasta): array {
    $start = (clone $desde)->modify('first day of this month')->setTime(0,0,0);
    $end   = (clone $hasta)->modify('first day of this month')->setTime(0,0,0);
    $out = [];
    while ($start <= $end) { 
        $out[] = $start->format('Y-m-d'); // IMPORTANTE: Formato completo Y-m-d para base de datos
        $start->modify('+1 month'); 
    }
    return $out;
}

function sCurveCum(float $t, float $k = 10.0): float {
    $x = $k * ($t - 0.5);
    $sig = 1.0 / (1.0 + exp(-$x));
    $sig0 = 1.0 / (1.0 + exp(-$k * (0.0 - 0.5)));
    $sig1 = 1.0 / (1.0 + exp(-$k * (1.0 - 0.5)));
    return ($sig - $sig0) / ($sig1 - $sig0);
}

function sCurvePercentages(int $n, float $k = 10.0): array {
    if ($n <= 0) return [];
    if ($n === 1) return [100.0];
    $cum=[]; for ($i=0;$i<$n;$i++) $cum[$i]=sCurveCum($i/($n-1), $k);
    $per=[]; $prev=0.0;
    for ($i=0;$i<$n;$i++){ $per[$i]=$cum[$i]-$prev; $prev=$cum[$i]; }
    $sum=array_sum($per);
    if ($sum<=0) $per=array_fill(0,$n,1.0/$n);
    else foreach($per as $i=>$v) $per[$i]=$v/$sum;
    $pct=array_map(fn($v)=>round($v*100.0,4),$per);
    $diff=100.0-array_sum($pct);
    $pct[$n-1]=round($pct[$n-1]+$diff,4);
    return $pct;
}

function distributeAnticipoRecupero(array $montosBrutos, int $idxAnticipoPago, float $anticipoMonto): array {
    $n=count($montosBrutos);
    $rec=array_fill(0,$n,0.0);
    if ($anticipoMonto<=0) return $rec;

    $base=0.0;
    for($i=0;$i<$n;$i++){ if($i===$idxAnticipoPago) continue; $base += (float)$montosBrutos[$i]; }
    
    // Si la base es 0 (ej. curva plana al inicio), distribuimos en el último
    if($base<=0) {
        if ($n > 1) $rec[$n-1] = $anticipoMonto; 
        return $rec;
    }

    $acum=0.0;
    for($i=0;$i<$n;$i++){
        if($i===$idxAnticipoPago) continue;
        $rec[$i]=round($anticipoMonto*((float)$montosBrutos[$i]/$base),2);
        $acum += $rec[$i];
    }
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

/* ===== Input ===== */
$obra_id = (int)($_POST['obra_id'] ?? 0);
$modo = $_POST['modo'] ?? 'S';
$k = (float)($_POST['k'] ?? 10.0);
$fecha_desde = $_POST['fecha_desde'] ?? null;
$fecha_hasta = $_POST['fecha_hasta'] ?? null;

$anticipo_monto = (float)($_POST['anticipo_monto'] ?? 0);
$anticipo_mes = $_POST['anticipo_mes'] ?? 'PRIMERO';
$anticipo_fuente_id = (int)($_POST['anticipo_fuente_id'] ?? 0);

if ($obra_id<=0 || !$fecha_desde || !$fecha_hasta) { die("Error: Parámetros inválidos (ID obra o fechas)."); }
if ($anticipo_monto>0 && $anticipo_fuente_id<=0) { die("Error: Debe seleccionar FUFI del anticipo si hay monto."); }

try {
    $dDesde=new DateTime($fecha_desde);
    $dHasta=new DateTime($fecha_hasta);
    
    // Validar que las fechas sean lógicas
    if($dHasta < $dDesde) throw new Exception("La fecha Hasta es menor que la fecha Desde.");

    $pdo->beginTransaction();

    // 1. Obtener datos de la obra
    $st=$pdo->prepare("SELECT id, monto_actualizado FROM obras WHERE id=? LIMIT 1");
    $st->execute([$obra_id]);
    $obra=$st->fetch(PDO::FETCH_ASSOC);
    if(!$obra) throw new Exception("Obra no encontrada.");

    $montoActualizado=(float)($obra['monto_actualizado'] ?? 0);
    if($montoActualizado <= 0) throw new Exception("La obra tiene Monto Actualizado en 0. Edita la obra y asígnale un monto.");

    // 2. Calcular periodos
    $periodos=monthPeriods($dDesde,$dHasta);
    $n=count($periodos);
    if($n<=0) throw new Exception("No se pudieron generar periodos entre las fechas seleccionadas.");

    // 3. Crear cabecera de versión
    $idxAnticipoPago=0;
    if($anticipo_mes==='SEGUNDO' && $n>=2) $idxAnticipoPago=1;

    $st=$pdo->prepare("SELECT COALESCE(MAX(nro_version),0)+1 FROM curva_version WHERE obra_id=?");
    $st->execute([$obra_id]);
    $nro_version=(int)$st->fetchColumn();

    $pdo->prepare("UPDATE curva_version SET es_vigente=0 WHERE obra_id=?")->execute([$obra_id]);

    $insV=$pdo->prepare("
        INSERT INTO curva_version (obra_id,nro_version,modo,fecha_desde,fecha_hasta,es_vigente,created_at)
        VALUES (?,?,?,?,?,1,NOW())
    ");
    $insV->execute([$obra_id,$nro_version,$modo,$dDesde->format('Y-m-d'),$dHasta->format('Y-m-d')]);
    $curva_version_id=(int)$pdo->lastInsertId();

    // 4. Calcular Curva
    $pcts = ($modo==='S') ? sCurvePercentages($n,$k) : array_fill(0,$n, round(100.0/$n,4));
    if($modo!=='S') $pcts[$n-1]=round(100.0-array_sum(array_slice($pcts,0,$n-1)),4);

    $brutos=[];
    for($i=0;$i<$n;$i++) $brutos[$i]=round($montoActualizado*($pcts[$i]/100.0),2);

    $anticipoPago=array_fill(0,$n,0.0);
    if($anticipo_monto>0) $anticipoPago[$idxAnticipoPago]=round($anticipo_monto,2);
    $anticipoRec=distributeAnticipoRecupero($brutos,$idxAnticipoPago,$anticipo_monto);

    // 5. Insertar DETALLE
    $insD=$pdo->prepare("
      INSERT INTO curva_detalle
      (curva_version_id,periodo,porcentaje_plan,
       monto_plan,monto_bruto_plan,anticipo_pago_plan,anticipo_pago_modo,anticipo_pago_fuente_id,
       anticipo_recupero_plan,anticipo_rec_modo,anticipo_rec_fuente_id,
       monto_neto_plan)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    for($i=0;$i<$n;$i++){
        $per=$periodos[$i]; // Formato Y-m-d
        $bruto=(float)$brutos[$i];
        $aPago=(float)$anticipoPago[$i];
        $aRec =(float)$anticipoRec[$i];
        $neto=round($bruto - $aRec + $aPago,2);

        // Validar nulos para evitar errores SQL
        $pagoModo = ($aPago>0) ? 'FUFI' : 'PARIPASSU';
        $pagoFufi = ($aPago>0 && $anticipo_fuente_id > 0) ? $anticipo_fuente_id : null;

        $recModo  = ($aRec>0) ? 'PARIPASSU' : 'PARIPASSU';
        $recFufi  = null;

        $insD->execute([
            $curva_version_id, $per, $pcts[$i],
            $neto, round($bruto,2), // OJO: monto_plan solía ser neto
            round($aPago,2), $pagoModo, $pagoFufi,
            round($aRec,2),  $recModo,  $recFufi,
            $neto
        ]);
    }

    // 6. Gestionar Fuentes (Si no existe, crear dummy)
    $st=$pdo->prepare("
      SELECT ofu.fuente_id, ofu.porcentaje
      FROM obra_fuentes ofu
      INNER JOIN fuentes_financiamiento f ON f.id=ofu.fuente_id
      WHERE ofu.obra_id=? AND ofu.activo=1 AND f.activo=1
      ORDER BY f.codigo
    ");
    $st->execute([$obra_id]);
    $obraFuentes=$st->fetchAll(PDO::FETCH_ASSOC);

    if(count($obraFuentes)===0){
        // Auto-crear fuente genérica si no existe para evitar crash
        $pdo->exec("INSERT IGNORE INTO fuentes_financiamiento (codigo,nombre,activo) VALUES ('SIN_FUFI','Tesoro / Sin Fuente',1)");
        $fid=(int)$pdo->query("SELECT id FROM fuentes_financiamiento WHERE codigo='SIN_FUFI' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT IGNORE INTO obra_fuentes (obra_id,fuente_id,porcentaje,activo) VALUES (?,?,100.000,1)")
            ->execute([$obra_id,$fid]);
        $obraFuentes=[[ 'fuente_id'=>$fid, 'porcentaje'=>100.000 ]];
    }

    $sumPct=0.0; foreach($obraFuentes as $f) $sumPct += (float)$f['porcentaje'];
    if($sumPct<=0) throw new Exception("Las fuentes de financiamiento suman 0%. Revise configuración.");

    // 7. Insertar DETALLE POR FUENTE
    $insF=$pdo->prepare("
      INSERT INTO curva_detalle_fuente
      (curva_version_id,periodo,fuente_id,porcentaje_fuente,
       monto_bruto_plan,anticipo_pago_plan,anticipo_recupero_plan,monto_neto_plan)
      VALUES (?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
       porcentaje_fuente=VALUES(porcentaje_fuente),
       monto_bruto_plan=VALUES(monto_bruto_plan),
       monto_neto_plan=VALUES(monto_neto_plan)
    ");

    // Releer lo que acabamos de insertar para distribuir
    $st=$pdo->prepare("
      SELECT periodo, monto_bruto_plan, anticipo_pago_plan, anticipo_pago_modo, anticipo_pago_fuente_id,
             anticipo_recupero_plan, anticipo_rec_modo, anticipo_rec_fuente_id
      FROM curva_detalle WHERE curva_version_id=? ORDER BY periodo ASC
    ");
    $st->execute([$curva_version_id]);
    $detCurva=$st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($detCurva)) {
        throw new Exception("Error crítico: No se generaron registros en curva_detalle. Revise logs.");
    }

    foreach($detCurva as $d){
        $per=$d['periodo'];
        $brutoMes=(float)$d['monto_bruto_plan'];
        $aPagoMes=(float)$d['anticipo_pago_plan'];
        $aRecMes =(float)$d['anticipo_recupero_plan'];

        $pagoModo=$d['anticipo_pago_modo'];
        $pagoFufi=(int)($d['anticipo_pago_fuente_id'] ?? 0);

        $recModo=$d['anticipo_rec_modo'];
        $recFufi=(int)($d['anticipo_rec_fuente_id'] ?? 0);

        foreach($obraFuentes as $f){
            $fuenteId=(int)$f['fuente_id'];
            $pct = (float)$f['porcentaje']/$sumPct*100.0;

            $brutoF=round($brutoMes*($pct/100.0),2);

            // pago anticipo por fuente
            $pagoF=0.0;
            if($aPagoMes>0){
                if($pagoModo==='FUFI'){
                    $pagoF = ($fuenteId===$pagoFufi) ? round($aPagoMes,2) : 0.0;
                } else { // PARIPASSU
                    $pagoF = round($aPagoMes*($pct/100.0),2);
                }
            }

            // recupero por fuente
            $recF=0.0;
            if($aRecMes>0){
                if($recModo==='FUFI' && $recFufi>0){
                    $recF = ($fuenteId===$recFufi) ? round($aRecMes,2) : 0.0;
                } else { // PARIPASSU
                    $recF = round($aRecMes*($pct/100.0),2);
                }
            }

            $netoF=round($brutoF - $recF + $pagoF,2);
            $insF->execute([$curva_version_id,$per,$fuenteId,round($pct,3),$brutoF,$pagoF,$recF,$netoF]);
        }
    }

    $pdo->commit();
    header("Location: curva_view.php?obra_id=".$obra_id);
    exit;

} catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    // Muestra el error en pantalla en lugar de un error 500 genérico
    die("<h1>Error al generar curva</h1><p>".htmlspecialchars($e->getMessage())."</p><pre>".$e->getTraceAsString()."</pre>");
}
<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_login();
require_can_edit();

$programa_id = (int)($_GET['programa_id'] ?? $_POST['programa_id'] ?? 0);
$msg = '';
$preview = [];
$headers = [];
$lote_id = '';
$autoMap = [];

// -------------------------------------------------------
// Función para leer xlsx sin librerías externas
// -------------------------------------------------------
function leer_xlsx(string $path): array {
    $rows = [];
    if (!class_exists('ZipArchive')) return $rows;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $rows;
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $xml = simplexml_load_string($ssXml);
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string)$si->t;
            } else {
                $t = '';
                foreach ($si->r as $r) { if (isset($r->t)) $t .= (string)$r->t; }
                $sharedStrings[] = $t;
            }
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return $rows;
    $xml = simplexml_load_string($sheetXml);
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        $lastCol = 0;
        foreach ($row->c as $cell) {
            preg_match('/([A-Z]+)(\d+)/', (string)$cell['r'], $m);
            $col = 0;
            foreach (str_split($m[1]) as $ch) { $col = $col * 26 + (ord($ch) - 64); }
            while ($lastCol < $col - 1) { $rowData[] = ''; $lastCol++; }
            $val = (string)$cell->v;
            if ((string)$cell['t'] === 's') $val = $sharedStrings[(int)$val] ?? '';
            $rowData[] = $val;
            $lastCol = $col;
        }
        $rows[] = $rowData;
    }
    return $rows;
}

function leer_csv(string $path): array {
    $rows = [];
    if (($h = fopen($path, 'r')) === false) return $rows;
    while (($row = fgetcsv($h, 0, ',')) !== false) { $rows[] = $row; }
    fclose($h);
    return $rows;
}

// Columnas de fecha conocidas del Excel
define('DATE_COLS', ['Fecha','Doc_R_Fecha','Fecha_de_Pesificacion','Fecha_Retiro_Pago','Fecha_Devengado']);

// Convertir serial numérico de Excel a fecha legible
function excel_to_date(string $val): string {
    if (!is_numeric($val) || (float)$val < 1 || (float)$val > 109000) return $val;
    $ts = (int)(((float)$val - 25569) * 86400);
    if ($ts < -2208988800) return $val; // anterior a 1900
    return gmdate('d/m/Y', $ts);
}

// Detectar índice de columna por nombre (case-insensitive)
function detectar_col(array $headers, array $names): int {
    foreach ($names as $n) {
        foreach ($headers as $i => $h) {
            if (strtolower(trim((string)$h)) === strtolower($n)) return $i;
        }
    }
    return -1;
}

// -------------------------------------------------------
// POST: confirmar importación (lee filas + headers desde sesión)
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
    $programa_id = (int)$_POST['programa_id'];
    $lote_id     = $_POST['lote_id'];
    $sesData     = $_SESSION['pagos_import'][$lote_id] ?? [];
    unset($_SESSION['pagos_import'][$lote_id]);

    if (empty($sesData)) {
        $msg = 'danger|Sesión expirada. Por favor cargue el archivo nuevamente.';
    } else {
        $filas = $sesData['rows'];
        $hdrs  = $sesData['headers'];
        $map = [
            'fecha'      => (int)$_POST['col_fecha'],
            'concepto'   => (int)$_POST['col_concepto'],
            'importe'    => (int)$_POST['col_importe'],
            'moneda'     => (int)($_POST['col_moneda'] ?? -1),
            'referencia' => (int)($_POST['col_referencia'] ?? -1),
        ];
        $ini = (int)$_POST['fila_inicio'];

        $stmt = $pdo->prepare("INSERT INTO programa_pagos_importados
            (programa_id,lote_id,fila,col_fecha,col_concepto,col_importe,col_moneda,col_referencia,datos_extra,usuario_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $cnt = 0;
        foreach ($filas as $i => $row) {
            if ($i < $ini) continue;
            $fFecha = $map['fecha']      >= 0 ? ($row[$map['fecha']]      ?? '') : '';
            $fConc  = $map['concepto']   >= 0 ? ($row[$map['concepto']]   ?? '') : '';
            $fImp   = $map['importe']    >= 0 ? ($row[$map['importe']]    ?? '') : '';
            if ($fFecha === '' && $fConc === '' && $fImp === '') continue;
            $fMon = $map['moneda']     >= 0 ? ($row[$map['moneda']]     ?? '') : '';
            $fRef = $map['referencia'] >= 0 ? ($row[$map['referencia']] ?? '') : '';
            // Guardar TODAS las columnas en datos_extra (convirtiendo fechas numéricas)
            $extra = [];
            foreach ($hdrs as $ci => $hname) {
                $v = $row[$ci] ?? '';
                $hname = trim((string)$hname); // eliminar espacios/BOM del nombre
                if (in_array($hname, DATE_COLS)) $v = excel_to_date((string)$v);
                $extra[$hname] = $v;
            }
            // También convertir col_fecha si es numérico
            if (is_numeric($fFecha) && (float)$fFecha > 1) $fFecha = excel_to_date($fFecha);
            $stmt->execute([
                $programa_id, $lote_id, $i,
                $fFecha, $fConc, $fImp, $fMon, $fRef,
                json_encode($extra, JSON_UNESCAPED_UNICODE),
                $_SESSION['user_id']
            ]);
            $cnt++;
        }
        header("Location: programa_ver.php?id=$programa_id&msg=importado_$cnt#tabPagos");
        exit;
    }
}

// -------------------------------------------------------
// POST: subir archivo → guardar en sesión y mostrar preview
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $programa_id = (int)$_POST['programa_id'];
    $file  = $_FILES['archivo'];
    $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmpDir = sys_get_temp_dir();

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = 'danger|Error al subir el archivo.';
    } elseif (!in_array($ext, ['csv','xlsx','xls'])) {
        $msg = 'danger|Solo se aceptan archivos CSV o XLSX.';
    } else {
        $tmp = $tmpDir . '/' . uniqid('pagos_') . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $tmp);
        $todasFilas = $ext === 'csv' ? leer_csv($tmp) : leer_xlsx($tmp);
        unlink($tmp);
        if (empty($todasFilas)) {
            $msg = 'danger|No se pudieron leer filas del archivo. Para XLS guardalo como XLSX o CSV.';
        } else {
            $lote_id = uniqid('lote_', true);
            $headers = $todasFilas[0] ?? [];
            $_SESSION['pagos_import'][$lote_id] = [
                'headers' => $headers,
                'rows'    => $todasFilas,
            ];
            $preview = array_slice($todasFilas, 1, 5);
        }
    }
}

// Auto-mapeo de columnas por nombre
if (!empty($headers)) {
    $autoMap = [
        'col_fecha'      => detectar_col($headers, ['Fecha','fecha','FECHA','Doc_R_Fecha','Fecha_Pago']),
        'col_concepto'   => detectar_col($headers, ['Nombre','nombre','NOMBRE','Descripcion','descripcion','Insumo_Descripcion']),
        'col_importe'    => detectar_col($headers, ['Importe_Pagado','importe_pagado','Importe','importe','IMPORTE','ImportePNUD']),
        'col_moneda'     => detectar_col($headers, ['Moneda','moneda','MONEDA','Funding_Descripcion']),
        'col_referencia' => detectar_col($headers, ['Nro_Transferencia','nro_transferencia','Nro_Cheque','Referencia','referencia','Codigo_Habilitado']),
    ];
}

// Cargar programa
$prog = null;
if ($programa_id) {
    $s = $pdo->prepare("SELECT p.*, o.nombre_organismo FROM programas p JOIN organismos_financiadores o ON o.id=p.organismo_id WHERE p.id=?");
    $s->execute([$programa_id]);
    $prog = $s->fetch();
}
$programas = $pdo->query("SELECT p.id, CONCAT(o.nombre_organismo,' – ',p.nombre,' [',p.codigo,']') AS label FROM programas p JOIN organismos_financiadores o ON o.id=p.organismo_id WHERE p.activo=1 ORDER BY label")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<div class="container-fluid my-4" style="max-width:1100px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-file-earmark-spreadsheet me-2 text-secondary"></i>
            Importar Pagos desde Excel / CSV
        </h5>
        <a href="<?= $programa_id ? "programa_ver.php?id=$programa_id#tabPagos" : "index.php" ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Volver
        </a>
    </div>

    <?php if ($msg): [$t,$m] = explode('|',$msg,2); ?>
    <div class="alert alert-<?= $t ?>"><?= htmlspecialchars($m) ?></div>
    <?php endif; ?>

    <!-- PASO 1: Subir archivo -->
    <?php if (empty($preview)): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold bg-light">Paso 1 – Seleccionar programa y archivo</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Programa</label>
                    <select name="programa_id" class="form-select" required>
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($programas as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $programa_id ? 'selected':'' ?>>
                            <?= htmlspecialchars($p['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Archivo Excel (.xlsx) o CSV</label>
                    <input type="file" name="archivo" class="form-control" required accept=".csv,.xlsx">
                    <div class="form-text">Para .XLS (formato antiguo) guardalo primero como <strong>.xlsx</strong> o <strong>.csv</strong>.</div>
                </div>
                <button type="submit" class="btn btn-secondary">
                    <i class="bi bi-eye me-1"></i>Vista Previa
                </button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- PASO 2: Mapeo de columnas y confirmación -->
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold bg-light">
            Paso 2 – Verificar mapeo y confirmar
            <span class="badge bg-secondary ms-2"><?= count($headers) ?> columnas detectadas</span>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-2">Las columnas marcadas en <span class="text-success fw-bold">verde</span> fueron auto-detectadas. Ajustá si es necesario. <strong>Todas las columnas del archivo se guardarán</strong> — el mapeo solo indica cuáles son los campos clave.</p>

            <form method="POST">
                <input type="hidden" name="programa_id" value="<?= $programa_id ?>">
                <input type="hidden" name="lote_id" value="<?= htmlspecialchars($lote_id) ?>">
                <input type="hidden" name="confirmar" value="1">
                <input type="hidden" name="fila_inicio" value="1">

                <div class="row g-2 mb-3">
                    <?php
                    $campos = [
                        'col_fecha'      => ['Fecha *',      'text-info'],
                        'col_concepto'   => ['Nombre/Concepto *', 'text-warning'],
                        'col_importe'    => ['Importe *',    'text-success'],
                        'col_moneda'     => ['Moneda',       'text-secondary'],
                        'col_referencia' => ['Referencia',   'text-secondary'],
                    ];
                    foreach ($campos as $fname => [$flabel, $fcls]):
                        $autoVal = $autoMap[$fname] ?? -1;
                    ?>
                    <div class="col-md-2 col-sm-4">
                        <label class="form-label fw-semibold small <?= $fcls ?>"><?= $flabel ?></label>
                        <select name="<?= $fname ?>" class="form-select form-select-sm <?= $autoVal >= 0 ? 'border-success' : '' ?>">
                            <option value="-1">-- N/A --</option>
                            <?php foreach ($headers as $ci => $h): ?>
                            <option value="<?= $ci ?>" <?= $ci === $autoVal ? 'selected' : '' ?>>
                                <?= htmlspecialchars(mb_strimwidth((string)$h, 0, 22, '…')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>

                <h6 class="fw-bold mb-2 small text-muted text-uppercase">Vista previa (primeras 5 filas de datos):</h6>
                <div class="table-responsive mb-3" style="max-height:260px; overflow:auto">
                    <table class="table table-sm table-bordered small" style="white-space:nowrap">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <?php foreach ($headers as $ci => $h): ?>
                                <th class="small"><?= htmlspecialchars((string)$h) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview as $row): ?>
                            <tr>
                                <?php foreach ($headers as $ci => $_): ?>
                                <td><?= htmlspecialchars(mb_strimwidth($row[$ci] ?? '', 0, 30, '…')) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Confirmar Importación
                    </button>
                    <a href="pagos_importar.php?programa_id=<?= $programa_id ?>" class="btn btn-outline-secondary">Cargar otro archivo</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

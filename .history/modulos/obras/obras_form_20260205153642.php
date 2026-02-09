<?php
// obras_form.php - Versión Completa con Datos Técnicos y Presupuestarios Integrados
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- Inicialización de variables (Incluye todos los campos de la DB y los nuevos) ---
$obra = [
  'id' => 0,
  'empresa_id' => 0, 
  'codigo_interno' => '',
  'expediente' => '',
  'denominacion' => '',
  'tipo_obra_id' => 0,
  'estado_obra_id' => 0,
  'ubicacion' => '',
  'region' => '', 
  'organismo_requirente' => '', 
  'titularidad_terreno' => '', 
  'superficie_desarrollo' => '', 
  'caracteristicas_obra' => '', 
  'memoria_objetivo' => '', 
  'fecha_inicio' => '',
  'fecha_fin_prevista' => '',
  'plazo_dias_original' => '',
  'moneda' => 'ARS',
  'periodo_base' => '',
  'monto_original' => 0,
  'monto_actualizado' => 0,
  'anticipo_pct' => 0,
  'anticipo_monto' => 0,
  'observaciones' => '',
];

$partida = [
  'ejercicio'=>'', 'cpn1'=>'','cpn2'=>'','cpn3'=>'','juri'=>'','sa'=>'','unor'=>'','fina'=>'','func'=>'','subf'=>'','inci'=>'','ppal'=>'','ppar'=>'','spar'=>'','fufi'=>'','ubge'=>'','defc'=>'','denominacion1'=>'','denominacion2'=>'','denominacion3'=>'','imputacion_codigo'=>'','vigente_desde'=>'','vigente_hasta'=>''
];

$fuentes_asignadas = [];

if ($id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ? AND activo=1 LIMIT 1");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  if (!$row) { http_response_code(404); echo "Obra no encontrada."; exit; }
  $obra = array_merge($obra, $row);

  $stmt = $pdo->prepare("SELECT * FROM obra_partida WHERE obra_id = ? ORDER BY id DESC LIMIT 1"); 
  $stmt->execute([$id]);
  $p = $stmt->fetch();
  if ($p) $partida = array_merge($partida, $p);

  $stmt = $pdo->prepare("SELECT * FROM obra_fuentes_config WHERE obra_id = ?");
  $stmt->execute([$id]);
  $fuentes_asignadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($fuentes_asignadas)) {
    $fuentes_asignadas[] = ['fuente_id' => '', 'porcentaje' => 0];
}

$tipos = $pdo->query("SELECT id, nombre FROM tipos_obra WHERE activo=1 ORDER BY nombre")->fetchAll();
$estados = $pdo->query("SELECT id, nombre FROM estados_obra WHERE activo=1 ORDER BY nombre")->fetchAll();
$empresas = $pdo->query("SELECT id, razon_social, cuit FROM empresas WHERE activo=1 ORDER BY razon_social ASC")->fetchAll();
$todas_fuentes = $pdo->query("SELECT id, codigo, nombre FROM fuentes_financiamiento WHERE activo=1 ORDER BY codigo ASC")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="../../assets/montos.js"></script>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h3 class="mb-0 fw-bold text-primary"><?php echo $id>0 ? 'Editar Obra' : 'Nueva Obra'; ?></h3>
    <div class="text-muted small">Gestión técnica, presupuestaria y financiera.</div>
  </div>
  <a class="btn btn-secondary shadow-sm" href="obras_listado.php"><i class="bi bi-arrow-left-circle me-1"></i> Volver</a>
</div>

<form method="post" action="obras_guardar.php" class="card shadow-sm" id="formObra">
  <div class="card-body">
    <input type="hidden" name="id" value="<?php echo (int)$obra['id']; ?>">

    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_general" type="button" role="tab">General</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_tecnico" type="button" role="tab">Datos Técnicos</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_presu" type="button" role="tab">Presupuesto</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_finan" type="button" role="tab">Financiamiento <span id="badge-finan" class="badge bg-danger rounded-pill ms-1">!</span></button></li>
    </ul>

    <div class="tab-content pt-3">
      
      <div class="tab-pane fade show active" id="tab_general" role="tabpanel">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label small fw-bold">Código interno</label>
            <input type="text" name="codigo_interno" class="form-control" value="<?php echo htmlspecialchars($obra['codigo_interno'] ?? ''); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-bold">Expediente</label>
            <input type="text" name="expediente" class="form-control" value="<?php echo htmlspecialchars($obra['expediente'] ?? ''); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-bold text-primary">Denominación *</label>
            <input type="text" name="denominacion" class="form-control" required value="<?php echo htmlspecialchars($obra['denominacion'] ?? ''); ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label small fw-bold text-primary">Empresa Contratista *</label>
            <select name="empresa_id" id="selectEmpresa" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($empresas as $emp): ?>
                <option value="<?php echo (int)$emp['id']; ?>" <?php echo ((int)$obra['empresa_id']==(int)$emp['id'])?'selected':''; ?>>
                  <?php echo htmlspecialchars($emp['cuit'] . ' - ' . $emp['razon_social']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label small fw-bold">Tipo de obra *</label>
            <select name="tipo_obra_id" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($tipos as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$obra['tipo_obra_id']==(int)$t['id'])?'selected':''; ?>><?php echo htmlspecialchars($t['nombre']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label small fw-bold">Estado *</label>
            <select name="estado_obra_id" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($estados as $e): ?>
                <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$obra['estado_obra_id']==(int)$e['id'])?'selected':''; ?>><?php echo htmlspecialchars($e['nombre']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label small fw-bold">Ubicación</label>
            <input type="text" name="ubicacion" class="form-control" value="<?php echo htmlspecialchars($obra['ubicacion'] ?? ''); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-bold">Región</label>
            <select name="region" class="form-select">
                <option value="">Seleccione...</option>
                <?php foreach(['Alto Neuquén', 'Pehuén', 'Lagos del Sur', 'Limay', 'Comarca', 'Confluencia', 'Vaca Muerta'] as $r): ?>
                    <option value="<?= $r ?>" <?= ($obra['region'] == $r)?'selected':'' ?>><?= $r ?></option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-bold">Moneda</label>
            <select name="moneda" class="form-select">
              <option value="ARS" <?php echo ($obra['moneda']==='ARS')?'selected':''; ?>>ARS</option>
              <option value="USD" <?php echo ($obra['moneda']==='USD')?'selected':''; ?>>USD</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-bold">Período base</label>
            <input type="month" name="periodo_base" class="form-control" value="<?php echo htmlspecialchars($obra['periodo_base'] ?? ''); ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label small fw-bold">Fecha inicio</label>
            <input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($obra['fecha_inicio'] ?? ''); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-bold">Fecha fin prevista</label>
            <input type="date" name="fecha_fin_prevista" class="form-control" value="<?php echo htmlspecialchars($obra['fecha_fin_prevista'] ?? ''); ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-bold">Plazo (días)</label>
            <input type="number" name="plazo_dias_original" class="form-control" value="<?php echo htmlspecialchars($obra['plazo_dias_original'] ?? ''); ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label small fw-bold">Monto Original</label>
            <div class="input-group"><span class="input-group-text">$</span>
            <input type="text" inputmode="decimal" name="monto_original" class="form-control monto" value="<?php echo number_format((float)$obra['monto_original'], 2, ',', '.'); ?>">
            </div>
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-bold text-success">Monto Actualizado</label>
            <div class="input-group"><span class="input-group-text bg-success bg-opacity-10">$</span>
            <input type="text" inputmode="decimal" name="monto_actualizado" class="form-control monto fw-bold" value="<?php echo number_format((float)$obra['monto_actualizado'], 2, ',', '.'); ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab_tecnico" role="tabpanel">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Organismo Requirente</label>
                <input type="text" name="organismo_requirente" class="form-control" value="<?= htmlspecialchars($obra['organismo_requirente'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Titularidad del Terreno</label>
                <input type="text" name="titularidad_terreno" class="form-control" value="<?= htmlspecialchars($obra['titularidad_terreno'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Superficie / Desarrollo</label>
                <input type="text" name="superficie_desarrollo" class="form-control" placeholder="m2 / km" value="<?= htmlspecialchars($obra['superficie_desarrollo'] ?? '') ?>">
            </div>
            <div class="col-md-12">
                <label class="form-label small fw-bold">Características de la Obra</label>
                <textarea name="caracteristicas_obra" class="form-control" rows="3"><?= htmlspecialchars($obra['caracteristicas_obra'] ?? '') ?></textarea>
            </div>
            <div class="col-md-12">
                <label class="form-label small fw-bold text-primary">Memoria y Objetivo</label>
                <textarea name="memoria_objetivo" class="form-control" rows="4"><?= htmlspecialchars($obra['memoria_objetivo'] ?? '') ?></textarea>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Anticipo (%)</label>
                <input type="text" inputmode="decimal" name="anticipo_pct" class="form-control" value="<?= $obra['anticipo_pct'] ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Monto Anticipo (Ref)</label>
                <div class="input-group input-group-sm"><span class="input-group-text">$</span>
                   <input type="text" name="anticipo_monto" class="form-control bg-light" readonly value="<?= number_format((float)$obra['anticipo_monto'], 2, ',', '.') ?>">
                </div>
            </div>
            <div class="col-md-7">
                <label class="form-label small fw-bold">Observaciones Generales</label>
                <textarea name="observaciones" class="form-control" rows="1"><?= htmlspecialchars($obra['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab_presu" role="tabpanel">
        <div class="row g-2">
          <div class="col-md-2"><label class="form-label small">Ejercicio</label><input class="form-control form-control-sm" name="ejercicio" value="<?php echo htmlspecialchars($partida['ejercicio'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">CPN1</label><input class="form-control form-control-sm" name="cpn1" value="<?php echo htmlspecialchars($partida['cpn1'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">CPN2</label><input class="form-control form-control-sm" name="cpn2" value="<?php echo htmlspecialchars($partida['cpn2'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">CPN3</label><input class="form-control form-control-sm" name="cpn3" value="<?php echo htmlspecialchars($partida['cpn3'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">JURI</label><input class="form-control form-control-sm" name="juri" value="<?php echo htmlspecialchars($partida['juri'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">SA</label><input class="form-control form-control-sm" name="sa" value="<?php echo htmlspecialchars($partida['sa'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">UNOR</label><input class="form-control form-control-sm" name="unor" value="<?php echo htmlspecialchars($partida['unor'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">FINA</label><input class="form-control form-control-sm" name="fina" value="<?php echo htmlspecialchars($partida['fina'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">FUNC</label><input class="form-control form-control-sm" name="func" value="<?php echo htmlspecialchars($partida['func'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">SUBF</label><input class="form-control form-control-sm" name="subf" value="<?php echo htmlspecialchars($partida['subf'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">INCI</label><input class="form-control form-control-sm" name="inci" value="<?php echo htmlspecialchars($partida['inci'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">PPAL</label><input class="form-control form-control-sm" name="ppal" value="<?php echo htmlspecialchars($partida['ppal'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">PPAR</label><input class="form-control form-control-sm" name="ppar" value="<?php echo htmlspecialchars($partida['ppar'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">SPAR</label><input class="form-control form-control-sm" name="spar" value="<?php echo htmlspecialchars($partida['spar'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">FUFI</label><input class="form-control form-control-sm" name="fufi" value="<?php echo htmlspecialchars($partida['fufi'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">UBGE</label><input class="form-control form-control-sm" name="ubge" value="<?php echo htmlspecialchars($partida['ubge'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label small">DEF</label><input class="form-control form-control-sm" name="defc" value="<?php echo htmlspecialchars($partida['defc'] ?? ''); ?>"></div>
          <div class="col-md-10"><label class="form-label small fw-bold">Imputación (código)</label><input class="form-control" name="imputacion_codigo" value="<?php echo htmlspecialchars($partida['imputacion_codigo'] ?? ''); ?>"></div>
          <div class="col-md-4"><label class="form-label small">Denominación 1</label><input class="form-control form-control-sm" name="denominacion1" value="<?php echo htmlspecialchars($partida['denominacion1'] ?? ''); ?>"></div>
          <div class="col-md-4"><label class="form-label small">Denominación 2</label><input class="form-control form-control-sm" name="denominacion2" value="<?php echo htmlspecialchars($partida['denominacion2'] ?? ''); ?>"></div>
          <div class="col-md-4"><label class="form-label small">Denominación 3</label><input class="form-control form-control-sm" name="denominacion3" value="<?php echo htmlspecialchars($partida['denominacion3'] ?? ''); ?>"></div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab_finan" role="tabpanel">
        <table class="table table-bordered table-sm" id="tablaFuentes">
            <thead class="table-light"><tr><th>Fuente de Financiamiento</th><th width="150">Porcentaje (%)</th><th width="50"></th></tr></thead>
            <tbody id="tbodyFuentes">
                <?php foreach($fuentes_asignadas as $fa): ?>
                <tr>
                    <td>
                        <select name="fuente_id[]" class="form-select form-select-sm" required>
                            <option value="">Seleccione...</option>
                            <?php foreach($todas_fuentes as $tf): ?>
                                <option value="<?php echo $tf['id']; ?>" <?php echo ($fa['fuente_id'] == $tf['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tf['codigo'] . ' - ' . $tf['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" step="0.01" name="fuente_pct[]" class="form-control form-control-sm text-end input-pct" value="<?php echo $fa['porcentaje']; ?>" required></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-borrar-fila"><i class="bi bi-trash"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td class="text-end fw-bold">TOTAL:</td><td class="text-end fw-bold" id="totalPct">0.00%</td><td></td></tr></tfoot>
        </table>
        <button type="button" class="btn btn-sm btn-success" id="btnAgregarFuente"><i class="bi bi-plus-lg"></i> Agregar Fuente</button>
      </div>

    </div>
  </div>

  <div class="card-footer d-flex justify-content-end gap-2 bg-light">
    <a class="btn btn-outline-secondary" href="obras_listado.php">Cancelar</a>
    <button class="btn btn-primary px-4 fw-bold" type="submit" id="btnGuardar"><i class="bi bi-save me-1"></i> Guardar Obra</button>
  </div>
</form>

<script>
$(document).ready(function() {
    $('#selectEmpresa').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Buscar...', allowClear: true });
    window.bindMontoMask('.monto');

    const updateCalculos = () => {
        const monto = window.unformatMonto($('[name="monto_original"]').val());
        const pct = parseFloat($('[name="anticipo_pct"]').val().replace(',','.')) || 0;
        $('[name="anticipo_monto"]').val((monto * pct / 100).toLocaleString('es-AR', { minimumFractionDigits: 2 }));
    };

    $('[name="monto_original"], [name="anticipo_pct"]').on('input', updateCalculos);

    const updateFuentes = () => {
        let t = 0; $('.input-pct').each(function(){ t += parseFloat($(this).val()) || 0; });
        $('#totalPct').text(t.toFixed(2) + '%').toggleClass('text-danger', Math.abs(t-100)>0.01).toggleClass('text-success', Math.abs(t-100)<=0.01);
        $('#badge-finan').text(Math.abs(t-100)<=0.01 ? 'OK' : '!').toggleClass('bg-success', Math.abs(t-100)<=0.01).toggleClass('bg-danger', Math.abs(t-100)>0.01);
    };

    $('#btnAgregarFuente').click(function(){
        let row = $('#tbodyFuentes tr:first').clone();
        row.find('select').val(''); row.find('input').val('0');
        $('#tbodyFuentes').append(row);
    });

    $(document).on('input', '.input-pct', updateFuentes);
    $(document).on('click', '.btn-borrar-fila', function(){ if($('#tbodyFuentes tr').length > 1){ $(this).closest('tr').remove(); updateFuentes(); } });

    updateFuentes();
    updateCalculos();
});
</script>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
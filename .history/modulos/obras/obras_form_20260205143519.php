<?php
// obras_form.php - Versión Integrada (Técnica, Georreferenciación y Financiamiento)
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- Inicialización de variables extendida ---
$obra = [
  'id' => 0, 'empresa_id' => 0, 'organismo_financiador_id' => 0, 'codigo_interno' => '', 
  'expediente' => '', 'denominacion' => '', 'tipo_obra_id' => 0, 'estado_obra_id' => 0, 
  'ubicacion' => '', 'region' => '', 'organismo_requirente' => '', 'titularidad_terreno' => '', 
  'superficie_desarrollo' => '', 'caracteristicas_obra' => '', 'memoria_objetivo' => '', 
  'fecha_inicio' => '', 'fecha_fin_prevista' => '', 'plazo_dias_original' => '', 
  'moneda' => 'ARS', 'periodo_base' => '', 'monto_original' => 0, 'monto_actualizado' => 0,
  'anticipo_pct' => 0, 'anticipo_monto' => 0, 'observaciones' => '',
  'latitud' => '', 'longitud' => ''
];

$partida = [
  'ejercicio'=>'','cpn1'=>'','cpn2'=>'','cpn3'=>'','juri'=>'','sa'=>'','unor'=>'','fina'=>'','func'=>'','subf'=>'','inci'=>'','ppal'=>'','ppar'=>'','spar'=>'','fufi'=>'','ubge'=>'','defc'=>'','denominacion1'=>'','denominacion2'=>'','denominacion3'=>'','imputacion_codigo'=>''
];

$fuentes_asignadas = [];

if ($id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ? AND activo=1 LIMIT 1");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  if (!$row) { http_response_code(404); exit("Obra no encontrada."); }
  $obra = array_merge($obra, $row);

  $stmt = $pdo->prepare("SELECT * FROM obra_partida WHERE obra_id = ? ORDER BY id DESC LIMIT 1"); 
  $stmt->execute([$id]);
  $p = $stmt->fetch();
  if ($p) $partida = array_merge($partida, $p);

  $stmt = $pdo->prepare("SELECT * FROM obra_fuentes_config WHERE obra_id = ?");
  $stmt->execute([$id]);
  $fuentes_asignadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if (empty($fuentes_asignadas)) $fuentes_asignadas[] = ['fuente_id' => '', 'porcentaje' => 0];

// Consultas para selects
$tipos = $pdo->query("SELECT id, nombre FROM tipos_obra WHERE activo=1 ORDER BY nombre")->fetchAll();
$estados = $pdo->query("SELECT id, nombre FROM estados_obra WHERE activo=1 ORDER BY nombre")->fetchAll();
$empresas = $pdo->query("SELECT id, razon_social, cuit FROM empresas WHERE activo=1 ORDER BY razon_social ASC")->fetchAll();
$todas_fuentes = $pdo->query("SELECT id, codigo, nombre FROM fuentes_financiamiento WHERE activo=1 ORDER BY codigo ASC")->fetchAll();
$organismos_finan = $pdo->query("SELECT id, nombre_organismo, descripcion_programa FROM organismos_financiadores WHERE activo=1 ORDER BY nombre_organismo ASC")->fetchAll();

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
    <div class="text-muted small">Gestión técnica, presupuestaria y georreferenciación.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-secondary shadow-sm" href="../../public/menu.php"><i class="bi bi-house-door"></i> Menú</a>
    <a class="btn btn-outline-secondary shadow-sm" href="obras_listado.php"><i class="bi bi-arrow-left-circle"></i> Volver</a>
  </div>
</div>

<form method="post" action="obras_guardar.php" class="card shadow-sm" id="formObra">
  <div class="card-body">
    <input type="hidden" name="id" value="<?php echo (int)$obra['id']; ?>">

    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_general" type="button" role="tab">General</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_tecnico" type="button" role="tab">Técnica y Mapas</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_presu" type="button" role="tab">Presupuesto</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_finan" type="button" role="tab">Financiamiento <span id="badge-finan" class="badge bg-danger rounded-pill ms-1">!</span></button></li>
    </ul>

    <div class="tab-content pt-3">
      
      <div class="tab-pane fade show active" id="tab_general" role="tabpanel">
        <div class="row g-3">
          <div class="col-md-3"><label class="form-label small fw-bold">Código interno</label><input type="text" name="codigo_interno" class="form-control" value="<?= htmlspecialchars($obra['codigo_interno']) ?>"></div>
          <div class="col-md-3"><label class="form-label small fw-bold">Expediente</label><input type="text" name="expediente" class="form-control" value="<?= htmlspecialchars($obra['expediente']) ?>"></div>
          <div class="col-md-6"><label class="form-label small fw-bold text-primary">Denominación *</label><input type="text" name="denominacion" class="form-control" required value="<?= htmlspecialchars($obra['denominacion']) ?>"></div>

          <div class="col-md-6">
            <label class="form-label small fw-bold text-primary">Empresa Contratista *</label>
            <select name="empresa_id" id="selectEmpresa" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($empresas as $emp): ?>
                <option value="<?= $emp['id'] ?>" <?= ($obra['empresa_id']==$emp['id'])?'selected':'' ?>><?= htmlspecialchars($emp['cuit'].' - '.$emp['razon_social']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3"><label class="form-label small fw-bold">Tipo de obra *</label>
            <select name="tipo_obra_id" class="form-select" required>
              <?php foreach ($tipos as $t): ?> <option value="<?= $t['id'] ?>" <?= ($obra['tipo_obra_id']==$t['id'])?'selected':'' ?>><?= htmlspecialchars($t['nombre']) ?></option> <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label small fw-bold">Estado *</label>
            <select name="estado_obra_id" class="form-select" required>
              <?php foreach ($estados as $e): ?> <option value="<?= $e['id'] ?>" <?= ($obra['estado_obra_id']==$e['id'])?'selected':'' ?>><?= htmlspecialchars($e['nombre']) ?></option> <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4"><label class="form-label small fw-bold">Región</label>
            <select name="region" class="form-select">
                <option value="">Seleccione Región...</option>
                <?php foreach(['Alto Neuquén', 'Pehuén', 'Lagos del Sur', 'Limay', 'Comarca', 'Confluencia', 'Vaca Muerta'] as $r): ?>
                    <option value="<?= $r ?>" <?= ($obra['region'] == $r)?'selected':'' ?>><?= $r ?></option>
                <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8"><label class="form-label small fw-bold">Ubicación (Localidad/Calle)</label><input type="text" name="ubicacion" class="form-control" value="<?= htmlspecialchars($obra['ubicacion']) ?>"></div>

          <div class="col-md-3"><label class="form-label small fw-bold text-success">Monto Actualizado</label>
            <div class="input-group"><span class="input-group-text bg-success bg-opacity-10">$</span><input type="text" inputmode="decimal" name="monto_actualizado" class="form-control monto fw-bold" value="<?= number_format((float)$obra['monto_actualizado'], 2, ',', '.') ?>"></div>
          </div>
          <div class="col-md-3"><label class="form-label small fw-bold text-muted">Monto Original</label>
            <div class="input-group"><span class="input-group-text">$</span><input type="text" inputmode="decimal" name="monto_original" class="form-control monto" value="<?= number_format((float)$obra['monto_original'], 2, ',', '.') ?>"></div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab_tecnico" role="tabpanel">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label small fw-bold text-primary">Organismo Financiador / Programa</label>
                <select name="organismo_financiador_id" class="form-select select2-basic">
                    <option value="">Seleccione...</option>
                    <?php foreach($organismos_finan as $org): ?>
                        <option value="<?= $org['id'] ?>" <?= ($obra['organismo_financiador_id'] == $org['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($org['nombre_organismo'] . " - " . $org['descripcion_programa']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><label class="form-label small fw-bold">Organismo Requirente</label><input type="text" name="organismo_requirente" class="form-control" value="<?= htmlspecialchars($obra['organismo_requirente']) ?>"></div>
            
            <div class="col-md-3">
                  <label class="form-label small fw-bold text-danger"><i class="bi bi-geo-alt"></i> Latitud</label>
                  <input type="text" name="latitud" id="latitud" class="form-control" value="<?= $obra['latitud'] ?>" placeholder="-38.95...">
              </div>
              <div class="col-md-3">
                  <label class="form-label small fw-bold text-danger"><i class="bi bi-geo-alt"></i> Longitud</label>
                  <input type="text" name="longitud" id="longitud" class="form-control" value="<?= $obra['longitud'] ?>" placeholder="-68.06...">
              </div>
              <div class="col-md-2 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-danger w-100" onclick="verEnGoogleMaps()">
                      <i class="bi bi-map"></i> Ver Mapa
                  </button>
              </div>

<script>
            <div class="col-md-6"><label class="form-label small fw-bold">Titularidad del Terreno</label><input type="text" name="titularidad_terreno" class="form-control" value="<?= htmlspecialchars($obra['titularidad_terreno']) ?>"></div>

            <div class="col-md-12"><label class="form-label small fw-bold">Características Técnicas de la Obra</label><textarea name="caracteristicas_obra" class="form-control" rows="3"><?= htmlspecialchars($obra['caracteristicas_obra']) ?></textarea></div>
            <div class="col-md-12"><label class="form-label small fw-bold">Memoria Descriptiva y Objetivos</label><textarea name="memoria_objetivo" class="form-control" rows="3"><?= htmlspecialchars($obra['memoria_objetivo']) ?></textarea></div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab_presu" role="tabpanel">
        <div class="row g-2">
            <div class="col-md-2"><label class="form-label small">Ejercicio</label><input class="form-control form-control-sm" name="ejercicio" value="<?= htmlspecialchars($partida['ejercicio']) ?>"></div>
            <div class="col-md-2"><label class="form-label small">JURI</label><input class="form-control form-control-sm" name="juri" value="<?= htmlspecialchars($partida['juri']) ?>"></div>
            <div class="col-md-2"><label class="form-label small">SA</label><input class="form-control form-control-sm" name="sa" value="<?= htmlspecialchars($partida['sa']) ?>"></div>
            <div class="col-md-2"><label class="form-label small">UNOR</label><input class="form-control form-control-sm" name="unor" value="<?= htmlspecialchars($partida['unor'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label small fw-bold">Imputación (Código Completo)</label><input class="form-control" name="imputacion_codigo" value="<?= htmlspecialchars($partida['imputacion_codigo']) ?>"></div>
            
            <div class="col-md-4"><label class="form-label small text-muted">Denominación 1</label><input class="form-control form-control-sm" name="denominacion1" value="<?= htmlspecialchars($partida['denominacion1']) ?>"></div>
            <div class="col-md-4"><label class="form-label small text-muted">Denominación 2</label><input class="form-control form-control-sm" name="denominacion2" value="<?= htmlspecialchars($partida['denominacion2']) ?>"></div>
            <div class="col-md-4"><label class="form-label small text-muted">Denominación 3</label><input class="form-control form-control-sm" name="denominacion3" value="<?= htmlspecialchars($partida['denominacion3']) ?>"></div>
            
            <div class="col-md-1"><label class="form-label small">FINA</label><input class="form-control form-control-sm" name="fina" value="<?= $partida['fina'] ?? '' ?>"></div>
            <div class="col-md-1"><label class="form-label small">FUNC</label><input class="form-control form-control-sm" name="func" value="<?= $partida['func'] ?? '' ?>"></div>
            <div class="col-md-1"><label class="form-label small">SUBF</label><input class="form-control form-control-sm" name="subf" value="<?= $partida['subf'] ?? '' ?>"></div>
            <div class="col-md-1"><label class="form-label small">INCI</label><input class="form-control form-control-sm" name="inci" value="<?= $partida['inci'] ?? '' ?>"></div>
            <div class="col-md-2"><label class="form-label small">PPAL</label><input class="form-control form-control-sm" name="ppal" value="<?= $partida['ppal'] ?? '' ?>"></div>
            <div class="col-md-2"><label class="form-label small">PPAR</label><input class="form-control form-control-sm" name="ppar" value="<?= $partida['ppar'] ?? '' ?>"></div>
            <div class="col-md-2"><label class="form-label small">SPAR</label><input class="form-control form-control-sm" name="spar" value="<?= $partida['spar'] ?? '' ?>"></div>
            <div class="col-md-1"><label class="form-label small">FUFI</label><input class="form-control form-control-sm" name="fufi" value="<?= $partida['fufi'] ?? '' ?>"></div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab_finan" role="tabpanel">
        <table class="table table-bordered table-sm" id="tablaFuentes">
            <thead class="table-light"><tr><th>Fuente</th><th width="150">Porcentaje (%)</th><th width="50"></th></tr></thead>
            <tbody id="tbodyFuentes">
                <?php foreach($fuentes_asignadas as $fa): ?>
                <tr>
                    <td><select name="fuente_id[]" class="form-select form-select-sm">
                        <option value="">Seleccione...</option>
                        <?php foreach($todas_fuentes as $tf): ?> <option value="<?= $tf['id'] ?>" <?= ($fa['fuente_id']==$tf['id'])?'selected':'' ?>><?= htmlspecialchars($tf['codigo'].' - '.$tf['nombre']) ?></option> <?php endforeach; ?>
                    </select></td>
                    <td><input type="number" step="0.01" name="fuente_pct[]" class="form-control form-control-sm text-end input-pct" value="<?= $fa['porcentaje'] ?>"></td>
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
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#selectEmpresa').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true });
    
    if(window.bindMontoMask) window.bindMontoMask('.monto');

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
});
</script>
<script>
function verEnGoogleMaps() {
    const lat = document.getElementById('latitud').value;
    const lon = document.getElementById('longitud').value;
    if(lat && lon) {
        window.open(`https://www.google.com/maps?q=${lat},${lon}`, '_blank');
    } else {
        alert("Por favor, ingrese latitud y longitud primero.");
    }
}
</script>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
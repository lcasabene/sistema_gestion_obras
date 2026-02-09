<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- Inicialización de variables ---
$obra = [
  'id' => 0,
  'empresa_id' => 0, 
  'codigo_interno' => '',
  'expediente' => '',
  'denominacion' => '',
  'tipo_obra_id' => 0,
  'estado_obra_id' => 0,
  'ubicacion' => '',
  'fecha_inicio' => '',
  'fecha_fin_prevista' => '',
  'plazo_dias_original' => '',
  'moneda' => 'ARS',
  'periodo_base' => '',
  'monto_original' => 0,
  'monto_actualizado' => 0, // Aseguramos que exista
  'anticipo_pct' => 0,
  'anticipo_monto' => 0,
  'observaciones' => '',
];

$partida = [
  'ejercicio'=>'', 'cpn1'=>'','cpn2'=>'','cpn3'=>'',
  'juri'=>'','sa'=>'','unor'=>'','fina'=>'','func'=>'','subf'=>'','inci'=>'',
  'ppal'=>'','ppar'=>'','spar'=>'','fufi'=>'','ubge'=>'','defc'=>'',
  'denominacion1'=>'','denominacion2'=>'','denominacion3'=>'',
  'imputacion_codigo'=>'', 'vigente_desde'=>'','vigente_hasta'=>''
];

// Array para guardar las fuentes asignadas a esta obra
$fuentes_asignadas = [];

if ($id > 0) {
  // 1. Datos Obra
  $stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ? AND activo=1 LIMIT 1");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  if (!$row) { http_response_code(404); echo "Obra no encontrada."; exit; }
  $obra = array_merge($obra, $row);

  // 2. Datos Partida
  $stmt = $pdo->prepare("SELECT * FROM obra_partida WHERE obra_id = ? ORDER BY id DESC LIMIT 1"); 
  $stmt->execute([$id]);
  $p = $stmt->fetch();
  if ($p) $partida = array_merge($partida, $p);

  // 3. Datos Financiamiento
  $stmt = $pdo->prepare("SELECT * FROM obra_fuentes_config WHERE obra_id = ?");
  $stmt->execute([$id]);
  $fuentes_asignadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($fuentes_asignadas)) {
    $fuentes_asignadas[] = ['fuente_id' => '', 'porcentaje' => 0];
}

// --- Carga de Listas Auxiliares ---
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
    <h3 class="mb-0"><?php echo $id>0 ? 'Editar Obra' : 'Nueva Obra'; ?></h3>
    <div class="text-muted small">Gestión integral del proyecto, contratista y financiamiento.</div>
  </div>
  <a class="btn btn-secondary" href="obras_listado.php"><i class="bi bi-arrow-left-circle me-1"></i> Volver</a>
</div>

<form method="post" action="obras_guardar.php" class="card shadow-sm" id="formObra">
  <div class="card-body">
    <input type="hidden" name="id" value="<?php echo (int)$obra['id']; ?>">

    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_general" type="button" role="tab">General</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_presu" type="button" role="tab">Presupuesto</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_finan" type="button" role="tab">Financiamiento <span id="badge-finan" class="badge bg-danger rounded-pill ms-1">!</span></button>
      </li>
    </ul>

    <div class="tab-content pt-3">
      
      <div class="tab-pane fade show active" id="tab_general" role="tabpanel">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Código interno</label>
            <input type="text" name="codigo_interno" class="form-control" value="<?php echo htmlspecialchars($obra['codigo_interno'] ?? ''); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Expediente</label>
            <input type="text" name="expediente" class="form-control" value="<?php echo htmlspecialchars($obra['expediente'] ?? ''); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Denominación *</label>
            <input type="text" name="denominacion" class="form-control" required value="<?php echo htmlspecialchars($obra['denominacion'] ?? ''); ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label fw-bold text-primary">Empresa Contratista *</label>
            <select name="empresa_id" id="selectEmpresa" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($empresas as $emp): ?>
                <option value="<?php echo (int)$emp['id']; ?>" <?php echo ((int)$obra['empresa_id']==(int)$emp['id'])?'selected':''; ?>>
                  <?php echo htmlspecialchars($emp['cuit'] . ' - ' . $emp['razon_social']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text small">Escriba para filtrar por CUIT o Nombre.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label">Tipo de obra *</label>
            <select name="tipo_obra_id" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($tipos as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$obra['tipo_obra_id']==(int)$t['id'])?'selected':''; ?>>
                  <?php echo htmlspecialchars($t['nombre']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Estado *</label>
            <select name="estado_obra_id" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($estados as $e): ?>
                <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$obra['estado_obra_id']==(int)$e['id'])?'selected':''; ?>>
                  <?php echo htmlspecialchars($e['nombre']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Ubicación</label>
            <input type="text" name="ubicacion" class="form-control" value="<?php echo htmlspecialchars($obra['ubicacion'] ?? ''); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Fecha inicio</label>
            <input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($obra['fecha_inicio'] ?? ''); ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Fecha fin prevista</label>
            <input type="date" name="fecha_fin_prevista" class="form-control" value="<?php echo htmlspecialchars($obra['fecha_fin_prevista'] ?? ''); ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Plazo (días)</label>
            <input type="number" name="plazo_dias_original" class="form-control" min="0" value="<?php echo htmlspecialchars($obra['plazo_dias_original'] ?? ''); ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Moneda</label>
            <select name="moneda" class="form-select">
              <option value="ARS" <?php echo ($obra['moneda']==='ARS')?'selected':''; ?>>ARS</option>
              <option value="USD" <?php echo ($obra['moneda']==='USD')?'selected':''; ?>>USD</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Período base</label>
            <input type="month" name="periodo_base" class="form-control" value="<?php echo htmlspecialchars($obra['periodo_base'] ?? ''); ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label text-muted">Monto Original</label>
            <div class="input-group"><span class="input-group-text">$</span>
            <input type="text" inputmode="decimal" name="monto_original" class="form-control monto" value="<?php echo htmlspecialchars(number_format((float)($obra['monto_original'] ?? 0), 2, ',', '.')); ?>">
            </div>
          </div>
          
          <div class="col-md-3">
            <label class="form-label fw-bold">Monto Actualizado</label>
            <div class="input-group"><span class="input-group-text bg-warning bg-opacity-10 fw-bold">$</span>
            <input type="text" inputmode="decimal" name="monto_actualizado" class="form-control monto fw-bold" value="<?php echo htmlspecialchars(number_format((float)($obra['monto_actualizado'] ?? 0), 2, ',', '.')); ?>">
            </div>
          </div>
          
          <div class="col-md-2">
            <label class="form-label">Anticipo (%)</label>
            <input type="text" inputmode="decimal" name="anticipo_pct" class="form-control" value="<?php echo htmlspecialchars((string)($obra['anticipo_pct'] ?? 0)); ?>">
          </div>
          
          <div class="col-md-12">
             <div class="row g-3">
                 <div class="col-md-3 offset-md-8">
                     <label class="form-label small">Monto Anticipo (Ref)</label>
                     <div class="input-group input-group-sm"><span class="input-group-text">$</span>
                        <input type="text" inputmode="decimal" name="anticipo_monto" class="form-control monto bg-light" readonly value="<?php echo htmlspecialchars(number_format((float)($obra['anticipo_monto'] ?? 0), 2, ',', '.')); ?>">
                     </div>
                 </div>
             </div>
          </div>


          <div class="col-md-12">
            <label class="form-label">Observaciones</label>
            <textarea name="observaciones" class="form-control" rows="2"><?php echo htmlspecialchars($obra['observaciones'] ?? ''); ?></textarea>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab_presu" role="tabpanel">
        <div class="alert alert-secondary small mb-3">
          Datos para la imputación presupuestaria y contable.
        </div>
        <div class="row g-3">
          <div class="col-md-2"><label class="form-label">Ejercicio</label><input class="form-control" name="ejercicio" value="<?php echo htmlspecialchars($partida['ejercicio'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">CPN1</label><input class="form-control" name="cpn1" value="<?php echo htmlspecialchars($partida['cpn1'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">CPN2</label><input class="form-control" name="cpn2" value="<?php echo htmlspecialchars($partida['cpn2'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">CPN3</label><input class="form-control" name="cpn3" value="<?php echo htmlspecialchars($partida['cpn3'] ?? ''); ?>"></div>

          <div class="col-md-2"><label class="form-label">JURI</label><input class="form-control" name="juri" value="<?php echo htmlspecialchars($partida['juri'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">SA</label><input class="form-control" name="sa" value="<?php echo htmlspecialchars($partida['sa'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">UNOR</label><input class="form-control" name="unor" value="<?php echo htmlspecialchars($partida['unor'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">FINA</label><input class="form-control" name="fina" value="<?php echo htmlspecialchars($partida['fina'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">FUNC</label><input class="form-control" name="func" value="<?php echo htmlspecialchars($partida['func'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">SUBF</label><input class="form-control" name="subf" value="<?php echo htmlspecialchars($partida['subf'] ?? ''); ?>"></div>

          <div class="col-md-2"><label class="form-label">INCI</label><input class="form-control" name="inci" value="<?php echo htmlspecialchars($partida['inci'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">PPAL</label><input class="form-control" name="ppal" value="<?php echo htmlspecialchars($partida['ppal'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">PPAR</label><input class="form-control" name="ppar" value="<?php echo htmlspecialchars($partida['ppar'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">SPAR</label><input class="form-control" name="spar" value="<?php echo htmlspecialchars($partida['spar'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">FUFI</label><input class="form-control" name="fufi" value="<?php echo htmlspecialchars($partida['fufi'] ?? ''); ?>"></div>
          <div class="col-md-2"><label class="form-label">UBGE</label><input class="form-control" name="ubge" value="<?php echo htmlspecialchars($partida['ubge'] ?? ''); ?>"></div>

          <div class="col-md-2"><label class="form-label">DEF</label><input class="form-control" name="defc" value="<?php echo htmlspecialchars($partida['defc'] ?? ''); ?>"></div>
          <div class="col-md-10"><label class="form-label">Imputación (código)</label><input class="form-control" name="imputacion_codigo" value="<?php echo htmlspecialchars($partida['imputacion_codigo'] ?? ''); ?>"></div>

          <div class="col-md-4"><label class="form-label">Denominación 1</label><input class="form-control" name="denominacion1" value="<?php echo htmlspecialchars($partida['denominacion1'] ?? ''); ?>"></div>
          <div class="col-md-4"><label class="form-label">Denominación 2</label><input class="form-control" name="denominacion2" value="<?php echo htmlspecialchars($partida['denominacion2'] ?? ''); ?>"></div>
          <div class="col-md-4"><label class="form-label">Denominación 3</label><input class="form-control" name="denominacion3" value="<?php echo htmlspecialchars($partida['denominacion3'] ?? ''); ?>"></div>

          <div class="col-md-3"><label class="form-label">Vigente desde</label><input type="date" class="form-control" name="vigente_desde" value="<?php echo htmlspecialchars($partida['vigente_desde'] ?? ''); ?>"></div>
          <div class="col-md-3"><label class="form-label">Vigente hasta</label><input type="date" class="form-control" name="vigente_hasta" value="<?php echo htmlspecialchars($partida['vigente_hasta'] ?? ''); ?>"></div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab_finan" role="tabpanel">
        <div class="alert alert-info d-flex align-items-center">
            <i class="bi bi-info-circle-fill me-2 fs-4"></i>
            <div>
                <strong>Configuración de Fuentes (Pari Passu)</strong><br>
                Defina qué organismos financian la obra y en qué porcentaje. La suma debe dar <strong>100%</strong>.
            </div>
        </div>

        <table class="table table-bordered table-hover" id="tablaFuentes">
            <thead class="table-light">
                <tr>
                    <th>Fuente de Financiamiento</th>
                    <th width="150" class="text-center">Porcentaje (%)</th>
                    <th width="50"></th>
                </tr>
            </thead>
            <tbody id="tbodyFuentes">
                <?php foreach($fuentes_asignadas as $fa): ?>
                <tr>
                    <td>
                        <select name="fuente_id[]" class="form-select select-fuente" required>
                            <option value="">Seleccione...</option>
                            <?php foreach($todas_fuentes as $tf): ?>
                                <option value="<?php echo $tf['id']; ?>" <?php echo ($fa['fuente_id'] == $tf['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tf['codigo'] . ' - ' . $tf['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" name="fuente_pct[]" class="form-control text-end input-pct" value="<?php echo $fa['porcentaje']; ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger btn-borrar-fila"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="text-end fw-bold">TOTAL:</td>
                    <td class="text-end fw-bold" id="totalPct">0.00%</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        
        <button type="button" class="btn btn-sm btn-success" id="btnAgregarFuente"><i class="bi bi-plus-lg"></i> Agregar Fuente</button>
      </div>

    </div>
  </div>

  <div class="card-footer d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="obras_listado.php">Cancelar</a>
    <button class="btn btn-primary" type="submit" id="btnGuardar"><i class="bi bi-save me-1"></i> Guardar</button>
  </div>
</form>

<script>
// --- ACTIVACIÓN SELECT2 (Buscador Empresa) ---
$(document).ready(function() {
    $('#selectEmpresa').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Escriba CUIT o Razón Social...',
        allowClear: true
    });
});

// --- Montos AR ---
if (typeof window.formatMonto !== 'function') {
  window.formatMonto = function(value) {
    value = (value || '').toString().replace(/\D/g, '');
    if (value === '') return '';
    let number = parseFloat(value) / 100;
    return number.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  window.unformatMonto = function(value) {
    if (!value) return 0;
    return parseFloat(value.toString().replace(/\./g, '').replace(',', '.')) || 0;
  };
  window.bindMontoMask = function(selector) {
    document.querySelectorAll(selector).forEach(input => {
      input.addEventListener('input', function() {
        const start = this.selectionStart;
        const oldLen = this.value.length;
        this.value = window.formatMonto(this.value);
        this.setSelectionRange(start + (this.value.length - oldLen), start + (this.value.length - oldLen));
      });
    });
  };
}

function parsePct(value) {
  if (!value) return 0;
  let v = value.toString().replace(',', '.');
  return parseFloat(v) || 0;
}

// --- Lógica de Anticipo ---
function recalcularAnticipo() {
  const montoEl = document.querySelector('[name="monto_original"]');
  const pctEl = document.querySelector('[name="anticipo_pct"]');
  const anticipoEl = document.querySelector('[name="anticipo_monto"]');
  if (!montoEl || !pctEl || !anticipoEl) return;

  const monto = window.unformatMonto(montoEl.value);
  const pct = parsePct(pctEl.value);
  const anticipo = monto * (pct / 100);
  anticipoEl.value = anticipo.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// --- Lógica de Fuentes de Financiamiento ---
function updateTotalFinanciamiento() {
    let total = 0;
    document.querySelectorAll('.input-pct').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });

    const totalEl = document.getElementById('totalPct');
    const badgeEl = document.getElementById('badge-finan');
    
    totalEl.textContent = total.toFixed(2) + '%';
    
    // Validaciones Visuales
    if (Math.abs(total - 100) < 0.01) { 
        totalEl.className = 'text-end fw-bold text-success';
        badgeEl.className = 'badge bg-success rounded-pill ms-1';
        badgeEl.textContent = 'OK';
    } else {
        totalEl.className = 'text-end fw-bold text-danger';
        badgeEl.className = 'badge bg-danger rounded-pill ms-1';
        badgeEl.textContent = '!';
    }
}

document.addEventListener('DOMContentLoaded', function() {
  window.bindMontoMask('.monto');
  const montoEl = document.querySelector('[name="monto_original"]');
  const pctEl = document.querySelector('[name="anticipo_pct"]');
  if (montoEl) montoEl.addEventListener('input', recalcularAnticipo);
  if (pctEl) pctEl.addEventListener('input', recalcularAnticipo);
  
  if (<?php echo $id; ?> === 0) recalcularAnticipo();

  // Inicializar cálculo de fuentes
  updateTotalFinanciamiento();

  // Evento agregar fila fuente
  const btnAgregar = document.getElementById('btnAgregarFuente');
  if(btnAgregar){
      btnAgregar.addEventListener('click', function() {
          const tbody = document.getElementById('tbodyFuentes');
          if(tbody.children.length > 0){
             const templateRow = tbody.children[0].cloneNode(true);
             templateRow.querySelector('select').value = "";
             templateRow.querySelector('input').value = "0";
             tbody.appendChild(templateRow);
             updateTotalFinanciamiento();
          }
      });
  }

  // Evento delegado para inputs y borrar fila
  const tbodyF = document.getElementById('tbodyFuentes');
  if(tbodyF){
      tbodyF.addEventListener('input', function(e) {
          if (e.target.classList.contains('input-pct')) {
              updateTotalFinanciamiento();
          }
      });

      tbodyF.addEventListener('click', function(e) {
          if (e.target.closest('.btn-borrar-fila')) {
              const rows = document.querySelectorAll('#tbodyFuentes tr');
              if (rows.length > 1) {
                  e.target.closest('tr').remove();
                  updateTotalFinanciamiento();
              } else {
                  alert("Debe haber al menos una fuente de financiamiento.");
              }
          }
      });
  }
  
  // Validación final al enviar
  const form = document.getElementById('formObra');
  if(form){
      form.addEventListener('submit', function(e) {
          let total = 0;
          document.querySelectorAll('.input-pct').forEach(inp => total += parseFloat(inp.value) || 0);
          
          if (total > 0 && Math.abs(total - 100) > 0.1) {
              e.preventDefault();
              alert("Atención: La suma de porcentajes de financiamiento debe ser 100%. Actualmente es: " + total.toFixed(2) + "%");
              const triggerEl = document.querySelector('button[data-bs-target="#tab_finan"]');
              const tab = new bootstrap.Tab(triggerEl);
              tab.show();
          }
      });
  }
});
</script>
<?php include __DIR__ . '/../../public/_footer.php'; ?>
<?php
// modulos/certificados/certificado_rapido_form.php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

$basePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $basePath . 'config/database.php';

$obra_id = isset($_GET['obra_id']) ? (int)$_GET['obra_id'] : 0;

// Listado de obras (ajustado a tu tabla: obras.denominacion)
$obras = [];
try {
    $st = $pdo->query("SELECT id, denominacion AS nombre FROM obras ORDER BY denominacion");
    $obras = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $obras = [];
}

include $basePath . 'public/_header.php';
?>

<div class="container-fluid mt-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="mb-0">Carga rápida de certificados</h4>
        <a class="btn btn-secondary" href="../../menu.php">Volver</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Obra</label>
                    <select id="obra_id" class="form-select">
                        <option value="">Seleccionar…</option>
                        <?php foreach ($obras as $o): ?>
                            <option value="<?= (int)$o['id'] ?>" <?= ($obra_id === (int)$o['id'] ? 'selected' : '') ?>>
                                <?= htmlspecialchars((string)$o['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <button id="btnCargarCurva" type="button" class="btn btn-primary w-100">
                        Cargar períodos / base desde curva vigente
                    </button>
                </div>

                <div class="col-md-3">
                    <button id="btnAgregarFila" type="button" class="btn btn-outline-secondary w-100">
                        Agregar fila manual
                    </button>
                </div>
            </div>

            <div class="row g-2 mt-3">
                <div class="col-md-3">
                    <label class="form-label">Contrato original (info)</label>
                    <input type="text" id="info_contrato" class="form-control" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Anticipo original (info)</label>
                    <input type="text" id="info_anticipo" class="form-control" readonly>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info mb-0">
                        El <b>%</b> se calcula sobre el <b>contrato original</b>. El <b>anticipo desc.</b> se calcula como <b>% del anticipo original</b>.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form id="formRapido" method="post" action="certificado_rapido_guardar.php">
        <input type="hidden" name="obra_id" id="obra_id_post" value="<?= (int)$obra_id ?>">

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle" id="tablaRapida">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:120px;">Período (YYYY-MM)</th>
                                <th style="min-width:170px;">% s/ Contrato Original</th>
                                <th style="min-width:170px;">Monto Certificado</th>
                                <th style="min-width:170px;">Anticipo desc. (auto)</th>
                                <th style="min-width:150px;">Fondo reparo</th>
                                <th style="min-width:120px;">Multas</th>
                                <th style="min-width:140px;">Otros desc.</th>
                                <th style="min-width:170px;">Importe a pagar</th>
                                <th style="width:60px;">Acc.</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyRapida"></tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button class="btn btn-success" type="submit">Guardar certificados</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    const obraSelect = document.getElementById('obra_id');
    const obraPost   = document.getElementById('obra_id_post');
    const btnCargar  = document.getElementById('btnCargarCurva');
    const btnAgregar = document.getElementById('btnAgregarFila');
    const tbody      = document.getElementById('tbodyRapida');

    const infoContrato = document.getElementById('info_contrato');
    const infoAnticipo = document.getElementById('info_anticipo');

    let baseContrato = 0;
    let baseAnticipo = 0;
    let planPeriodos = {}; // periodo -> { pct_plan, ... }

    // Parse para entradas es-AR (miles con . y decimales con ,)
    function parseArNumber(str) {
        if (str === null || str === undefined) return 0;
        str = String(str).trim();
        if (str === '') return 0;
        str = str.replace(/\./g, '');
        str = str.replace(',', '.');
        const n = Number(str);
        return Number.isFinite(n) ? n : 0;
    }

    function formatArMoney(n) {
        const num = Number(n);
        if (!Number.isFinite(num)) return '';
        return num.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Formato % (3 decimales para que 0,448 no se pierda)
    function formatArPct(n) {
        const num = Number(n);
        if (!Number.isFinite(num)) return '0,000';
        return num.toLocaleString('es-AR', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    }

    function recalcRow(tr) {
        const pct = parseArNumber(tr.querySelector('.pct').value);

        const monto = baseContrato > 0 && pct > 0 ? (baseContrato * (pct / 100)) : 0;
        tr.querySelector('.monto_cert').value = formatArMoney(monto);

        const anticipo = baseAnticipo > 0 && pct > 0 ? (baseAnticipo * (pct / 100)) : 0;
        tr.querySelector('.anticipo').value = formatArMoney(anticipo);

        const fr = parseArNumber(tr.querySelector('.reparo').value);
        const mu = parseArNumber(tr.querySelector('.multas').value);
        const ot = parseArNumber(tr.querySelector('.otros').value);

        const pagar = monto - (anticipo + fr + mu + ot);
        tr.querySelector('.pagar').value = formatArMoney(pagar);
    }

    function buildRow(periodo = '', pctPlanRaw = null) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input class="form-control form-control-sm" name="periodo[]" value="${periodo}" placeholder="YYYY-MM"></td>
            <td><input class="form-control form-control-sm pct" name="pct_neto[]" value="" placeholder="0,000"></td>
            <td><input class="form-control form-control-sm monto_cert" name="monto_certificado[]" value="" readonly></td>
            <td><input class="form-control form-control-sm anticipo" name="anticipo_desc[]" value="" readonly></td>
            <td><input class="form-control form-control-sm reparo" name="fondo_reparo[]" value="0,00"></td>
            <td><input class="form-control form-control-sm multas" name="multas[]" value="0,00"></td>
            <td><input class="form-control form-control-sm otros" name="otros_desc[]" value="0,00"></td>
            <td><input class="form-control form-control-sm pagar" name="importe_a_pagar[]" value="" readonly></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnDel">X</button></td>
        `;

        tr.querySelector('.btnDel').addEventListener('click', () => tr.remove());

        const pctInput = tr.querySelector('.pct');
        const frInput  = tr.querySelector('.reparo');
        const muInput  = tr.querySelector('.multas');
        const otInput  = tr.querySelector('.otros');

        // ✅ CORRECTO: pctPlanRaw viene del endpoint como número (ej 0.44829)
        if (pctPlanRaw !== null && pctPlanRaw !== '' && pctPlanRaw !== undefined) {
            const n = Number(pctPlanRaw);
            pctInput.value = formatArPct(n);
        } else {
            pctInput.value = formatArPct(0);
        }

        function recalc() { recalcRow(tr); }

        pctInput.addEventListener('input', recalc);
        frInput.addEventListener('input', recalc);
        muInput.addEventListener('input', recalc);
        otInput.addEventListener('input', recalc);

        // formateo en blur
        pctInput.addEventListener('blur', () => {
            pctInput.value = formatArPct(parseArNumber(pctInput.value));
            recalc();
        });
        [frInput, muInput, otInput].forEach(inp => {
            inp.addEventListener('blur', () => {
                inp.value = formatArMoney(parseArNumber(inp.value));
                recalc();
            });
        });

        setTimeout(recalc, 0);
        return tr;
    }

    function clearTable() { tbody.innerHTML = ''; }
    function addRow(periodo = '', pctPlan = null) { tbody.appendChild(buildRow(periodo, pctPlan)); }

    obraSelect.addEventListener('change', () => {
        obraPost.value = obraSelect.value || '';
    });

    btnAgregar.addEventListener('click', () => addRow('', null));

    btnCargar.addEventListener('click', async () => {
        const obraId = obraSelect.value ? Number(obraSelect.value) : 0;
        if (!obraId) { alert('Seleccioná una obra.'); return; }

        obraPost.value = String(obraId);

        btnCargar.disabled = true;
        btnCargar.textContent = 'Cargando...';

        try {
            const res = await fetch(`certificado_rapido_get_plan.php?obra_id=${encodeURIComponent(obraId)}`, { cache: 'no-store' });
            const data = await res.json();

            if (!data.ok) throw new Error(data.error || 'Error al consultar.');

            baseContrato = Number(data.base?.contrato_original || 0);
            baseAnticipo = Number(data.base?.anticipo_original || 0);

            infoContrato.value = formatArMoney(baseContrato);
            infoAnticipo.value = formatArMoney(baseAnticipo);

            planPeriodos = data.periodos || {};
            clearTable();

            const periodos = Object.keys(planPeriodos);
            if (periodos.length > 0) {
                periodos.forEach(p => {
                    const pct = planPeriodos[p]?.pct_plan ?? null; // número
                    addRow(p, pct);
                });
            } else {
                addRow('', null);
                alert('La curva no tiene detalle por períodos. Podés cargar manualmente.');
            }

        } catch (e) {
            console.error(e);
            alert('No se pudo cargar base/curva: ' + (e.message || e));
        } finally {
            btnCargar.disabled = false;
            btnCargar.textContent = 'Cargar períodos / base desde curva vigente';
        }
    });

    // Init
    if (obraSelect.value) obraPost.value = obraSelect.value;
    addRow('', null);
})();
</script>

<?php include $basePath . 'public/_footer.php'; ?>

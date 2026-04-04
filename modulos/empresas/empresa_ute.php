<?php
// empresa_ute.php - Gestión de composición UTE
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$empresa_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($empresa_id <= 0) { header("Location: empresas_listado.php"); exit; }

$empresa = $pdo->prepare("SELECT * FROM empresas WHERE id = ? AND activo = 1");
$empresa->execute([$empresa_id]);
$empresa = $empresa->fetch(PDO::FETCH_ASSOC);
if (!$empresa || empty($empresa['es_ute'])) {
    header("Location: empresas_listado.php");
    exit;
}

$mensaje = '';
$tipo_alerta = '';

// Cargar empresas existentes para autocompletar
$todas_empresas = $pdo->query("SELECT id, razon_social, cuit FROM empresas WHERE activo=1 ORDER BY razon_social")->fetchAll(PDO::FETCH_ASSOC);

// --- GUARDAR COMPOSICIÓN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];

    try {
        if ($accion === 'guardar_composicion') {
            $pdo->beginTransaction();

            // Borrar composición actual
            $pdo->prepare("DELETE FROM empresa_ute_integrantes WHERE empresa_id = ?")->execute([$empresa_id]);

            $cuits = $_POST['int_cuit'] ?? [];
            $denoms = $_POST['int_denominacion'] ?? [];
            $pcts = $_POST['int_porcentaje'] ?? [];
            $emp_ids = $_POST['int_empresa_id'] ?? [];

            $stmtIns = $pdo->prepare("INSERT INTO empresa_ute_integrantes (empresa_id, integrante_empresa_id, cuit, denominacion, porcentaje) VALUES (?, ?, ?, ?, ?)");

            for ($i = 0; $i < count($cuits); $i++) {
                $cuit = trim($cuits[$i] ?? '');
                $denom = trim($denoms[$i] ?? '');
                if (empty($cuit) && empty($denom)) continue;

                $int_emp_id = !empty($emp_ids[$i]) ? (int)$emp_ids[$i] : null;
                $pct = (float)str_replace(',', '.', $pcts[$i] ?? 0);

                $stmtIns->execute([$empresa_id, $int_emp_id, $cuit, $denom, $pct]);
            }

            $pdo->commit();
            $mensaje = "Composición UTE guardada correctamente.";
            $tipo_alerta = "success";
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "Error: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// Cargar integrantes actuales
$integrantes = $pdo->prepare("SELECT * FROM empresa_ute_integrantes WHERE empresa_id = ? ORDER BY id ASC");
$integrantes->execute([$empresa_id]);
$integrantes = $integrantes->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-0">
                <i class="bi bi-people-fill"></i> Composición UTE
            </h3>
            <p class="text-muted mb-0">
                <span class="badge bg-warning text-dark">UTE</span>
                <strong><?= htmlspecialchars($empresa['razon_social']) ?></strong>
                <span class="font-monospace small">(CUIT: <?= htmlspecialchars($empresa['cuit']) ?>)</span>
            </p>
        </div>
        <a href="empresas_listado.php" class="btn btn-secondary shadow-sm">
            <i class="bi bi-arrow-left-circle me-1"></i> Volver
        </a>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show" role="alert">
            <?= $mensaje ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_composicion">

                <table class="table table-bordered table-sm" id="tablaUte">
                    <thead class="table-light">
                        <tr>
                            <th width="280">Denominación / Razón Social</th>
                            <th width="180">CUIT</th>
                            <th width="120">% Participación</th>
                            <th width="250">Vincular a Empresa existente</th>
                            <th width="50"></th>
                        </tr>
                    </thead>
                    <tbody id="tbodyUte">
                        <?php if (!empty($integrantes)): ?>
                            <?php foreach($integrantes as $int): ?>
                            <tr>
                                <td>
                                    <input type="text" name="int_denominacion[]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($int['denominacion']) ?>" placeholder="Razón social" required>
                                </td>
                                <td>
                                    <input type="text" name="int_cuit[]" class="form-control form-control-sm font-monospace" 
                                           value="<?= htmlspecialchars($int['cuit']) ?>" placeholder="CUIT" required>
                                </td>
                                <td>
                                    <input type="number" step="0.01" name="int_porcentaje[]" 
                                           class="form-control form-control-sm text-end input-ute-pct" 
                                           value="<?= $int['porcentaje'] ?>">
                                </td>
                                <td>
                                    <select name="int_empresa_id[]" class="form-select form-select-sm sel-empresa">
                                        <option value="">-- No vinculada --</option>
                                        <?php foreach($todas_empresas as $te): ?>
                                            <option value="<?= $te['id'] ?>" 
                                                    data-cuit="<?= htmlspecialchars($te['cuit']) ?>"
                                                    data-razon="<?= htmlspecialchars($te['razon_social']) ?>"
                                                    <?= ($int['integrante_empresa_id'] == $te['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($te['cuit'] . ' - ' . $te['razon_social']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-borrar-int"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td><input type="text" name="int_denominacion[]" class="form-control form-control-sm" placeholder="Razón social" required></td>
                                <td><input type="text" name="int_cuit[]" class="form-control form-control-sm font-monospace" placeholder="CUIT" required></td>
                                <td><input type="number" step="0.01" name="int_porcentaje[]" class="form-control form-control-sm text-end input-ute-pct" value="0"></td>
                                <td>
                                    <select name="int_empresa_id[]" class="form-select form-select-sm sel-empresa">
                                        <option value="">-- No vinculada --</option>
                                        <?php foreach($todas_empresas as $te): ?>
                                            <option value="<?= $te['id'] ?>" data-cuit="<?= htmlspecialchars($te['cuit']) ?>" data-razon="<?= htmlspecialchars($te['razon_social']) ?>">
                                                <?= htmlspecialchars($te['cuit'] . ' - ' . $te['razon_social']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-borrar-int"><i class="bi bi-trash"></i></button></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end fw-bold">TOTAL:</td>
                            <td class="text-end fw-bold" id="totalUtePct">0.00%</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>

                <div class="d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-success" id="btnAgregarInt">
                        <i class="bi bi-plus-lg"></i> Agregar Integrante
                    </button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="bi bi-save me-1"></i> Guardar Composición
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(function() {
    // Opciones de empresas como template HTML
    var opcionesEmpresa = <?= json_encode(array_map(function($te) {
        return ['id' => $te['id'], 'cuit' => $te['cuit'], 'razon' => $te['razon_social']];
    }, $todas_empresas)) ?>;

    function buildSelectHTML() {
        var html = '<option value="">-- No vinculada --</option>';
        opcionesEmpresa.forEach(function(e) {
            html += '<option value="' + e.id + '" data-cuit="' + e.cuit + '" data-razon="' + e.razon + '">' + e.cuit + ' - ' + e.razon + '</option>';
        });
        return html;
    }

    // Agregar fila
    $('#btnAgregarInt').on('click', function() {
        var row = '<tr>' +
            '<td><input type="text" name="int_denominacion[]" class="form-control form-control-sm" placeholder="Razón social" required></td>' +
            '<td><input type="text" name="int_cuit[]" class="form-control form-control-sm font-monospace" placeholder="CUIT" required></td>' +
            '<td><input type="number" step="0.01" name="int_porcentaje[]" class="form-control form-control-sm text-end input-ute-pct" value="0"></td>' +
            '<td><select name="int_empresa_id[]" class="form-select form-select-sm sel-empresa">' + buildSelectHTML() + '</select></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-borrar-int"><i class="bi bi-trash"></i></button></td>' +
            '</tr>';
        $('#tbodyUte').append(row);
    });

    // Borrar fila
    $(document).on('click', '.btn-borrar-int', function() {
        if ($('#tbodyUte tr').length > 1) {
            $(this).closest('tr').remove();
            updateTotal();
        }
    });

    // Al seleccionar empresa existente, autocompletar CUIT y denominación
    $(document).on('change', '.sel-empresa', function() {
        var $row = $(this).closest('tr');
        var $opt = $(this).find('option:selected');
        if ($opt.val()) {
            $row.find('[name="int_cuit[]"]').val($opt.data('cuit'));
            $row.find('[name="int_denominacion[]"]').val($opt.data('razon'));
        }
    });

    // Total %
    function updateTotal() {
        var t = 0;
        $('.input-ute-pct').each(function() { t += parseFloat($(this).val()) || 0; });
        $('#totalUtePct').text(t.toFixed(2) + '%')
            .toggleClass('text-danger', Math.abs(t - 100) > 0.01)
            .toggleClass('text-success', Math.abs(t - 100) <= 0.01);
    }
    $(document).on('input', '.input-ute-pct', updateTotal);
    updateTotal();
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>

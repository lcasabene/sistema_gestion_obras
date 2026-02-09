<?php
// modulos/certificados/redeterminaciones_form.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$cert_id = isset($_GET['cert_id']) ? (int)$_GET['cert_id'] : 0;

if ($cert_id === 0) {
    die("Error: Se requiere un ID de certificado base.");
}

// Obtener datos básicos del certificado
$cert = $pdo->query("SELECT c.*, o.denominacion FROM certificados c JOIN obras o ON c.obra_id = o.id WHERE c.id = $cert_id")->fetch(PDO::FETCH_ASSOC);

// GUARDAR
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        // Borrar anteriores
        $pdo->prepare("DELETE FROM certificados_items WHERE certificado_id = ? AND tipo = 'REDETERMINACION'")->execute([$cert_id]);
        
        $totalRedet = 0;
        
        if (isset($_POST['conceptos'])) {
            $stmt = $pdo->prepare("INSERT INTO certificados_items (certificado_id, concepto, monto, tipo) VALUES (?, ?, ?, 'REDETERMINACION')");
            foreach ($_POST['conceptos'] as $k => $concepto) {
                $monto = (float)str_replace(',', '.', str_replace('.', '', $_POST['montos'][$k]));
                if ($monto != 0) {
                    $stmt->execute([$cert_id, $concepto, $monto]);
                    $totalRedet += $monto;
                }
            }
        }
        
        // Actualizar el total en la cabecera del certificado
        $montoBruto = $cert['monto_basico'] + $totalRedet;
        $pdo->prepare("UPDATE certificados SET monto_redeterminado = ?, monto_bruto = ? WHERE id = ?")
            ->execute([$totalRedet, $montoBruto, $cert_id]);

        $pdo->commit();
        // Volver al formulario principal
        header("Location: certificados_form.php?id=$cert_id&msg=redet_ok");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Cargar items existentes
$items = $pdo->query("SELECT * FROM certificados_items WHERE certificado_id = $cert_id AND tipo = 'REDETERMINACION'")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../public/_header.php';
?>

<div class="container my-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-calculator"></i> Detalle de Redeterminaciones</h5>
                    <small>Obra: <?= htmlspecialchars($cert['denominacion']) ?> | Certificado Nº <?= $cert['nro_certificado'] ?></small>
                </div>
                <div class="card-body">
                    <?php if(isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

                    <form method="POST" id="formRedet">
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered table-sm" id="tablaItems">
                                <thead class="table-light">
                                    <tr>
                                        <th>Concepto / Norma Legal / Acta</th>
                                        <th width="200">Monto Diferencia ($)</th>
                                        <th width="50"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($items)): ?>
                                        <tr>
                                            <td><input type="text" name="conceptos[]" class="form-control" placeholder="Ej: Acta Acuerdo Abril 2024"></td>
                                            <td><input type="text" name="montos[]" class="form-control text-end monto" placeholder="0,00" oninput="calcTotal()"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="borrarFila(this)">&times;</button></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($items as $i): ?>
                                        <tr>
                                            <td><input type="text" name="conceptos[]" class="form-control" value="<?= htmlspecialchars($i['concepto']) ?>"></td>
                                            <td><input type="text" name="montos[]" class="form-control text-end monto" value="<?= number_format($i['monto'], 2, ',', '.') ?>" oninput="calcTotal()"></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="borrarFila(this)">&times;</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td class="text-end fw-bold">TOTAL REDETERMINADO:</td>
                                        <td class="text-end fw-bold fs-5 text-primary" id="txtTotal">$ 0,00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="agregarFila()">
                            <i class="bi bi-plus-lg"></i> Agregar Ítem
                        </button>

                        <div class="d-flex justify-content-between pt-3 border-top">
                            <a href="certificados_form.php?id=<?= $cert_id ?>" class="btn btn-secondary">Volver al Certificado</a>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar y Volver</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../../assets/montos.js"></script>
<script>
function agregarFila() {
    let tbody = document.querySelector('#tablaItems tbody');
    let tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="conceptos[]" class="form-control"></td>
        <td><input type="text" name="montos[]" class="form-control text-end monto" oninput="calcTotal()"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="borrarFila(this)">&times;</button></td>
    `;
    tbody.appendChild(tr);
    window.bindMontoMask && window.bindMontoMask('.monto');
}

function borrarFila(btn) {
    if(document.querySelectorAll('#tablaItems tbody tr').length > 1) {
        btn.closest('tr').remove();
        calcTotal();
    } else {
        btn.closest('tr').querySelector('input').value = '';
        calcTotal();
    }
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('input[name="montos[]"]').forEach(inp => {
        total += window.unformatMonto(inp.value);
    });
    document.getElementById('txtTotal').innerText = '$ ' + total.toLocaleString('es-AR', {minimumFractionDigits: 2});
}

document.addEventListener('DOMContentLoaded', () => {
    window.bindMontoMask && window.bindMontoMask('.monto');
    calcTotal();
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
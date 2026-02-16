<?php
// modulos/liquidaciones/config/iibb_config.php
// ABM de Categorías IIBB (Res. 276/DPR/17 Neuquén) y Mínimos no sujetos
require_once __DIR__ . '/../../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../../config/database.php';
include __DIR__ . '/../../../public/_header.php';

$mensaje = '';
$tipo_alerta = '';

// =============================================
// PROCESAR POST
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $seccion = $_POST['seccion'] ?? '';

    try {
        if ($seccion === 'categoria_guardar') {
            $catId = (int)($_POST['cat_id'] ?? 0);
            $codigo = trim($_POST['codigo'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $alicuota = (float)str_replace(',', '.', $_POST['alicuota'] ?? '0');
            $activo = (int)($_POST['activo'] ?? 1);
            $orden = (int)($_POST['orden'] ?? 0);

            if (!$codigo || !$descripcion) throw new Exception("Código y descripción son obligatorios.");

            if ($catId > 0) {
                $pdo->prepare("UPDATE iibb_categorias SET codigo=?, descripcion=?, alicuota=?, activo=?, orden=? WHERE id=?")
                    ->execute([$codigo, $descripcion, $alicuota, $activo, $orden, $catId]);
                $mensaje = "Categoría actualizada.";
            } else {
                $pdo->prepare("INSERT INTO iibb_categorias (codigo, descripcion, alicuota, activo, orden) VALUES (?,?,?,?,?)")
                    ->execute([$codigo, $descripcion, $alicuota, $activo, $orden]);
                $mensaje = "Categoría creada.";
            }
            $tipo_alerta = 'success';

        } elseif ($seccion === 'categoria_eliminar') {
            $catId = (int)($_POST['cat_id'] ?? 0);
            if ($catId) {
                $pdo->prepare("DELETE FROM iibb_categorias WHERE id = ?")->execute([$catId]);
                $mensaje = "Categoría eliminada.";
                $tipo_alerta = 'success';
            }

        } elseif ($seccion === 'minimo_guardar') {
            $minId = (int)($_POST['min_id'] ?? 0);
            $tipo_agente = trim($_POST['tipo_agente'] ?? '');
            $descripcion = trim($_POST['min_descripcion'] ?? '');
            $minimo = (float)str_replace(',', '.', str_replace('.', '', $_POST['minimo_no_sujeto'] ?? '0'));

            if (!$tipo_agente || !$descripcion) throw new Exception("Tipo y descripción son obligatorios.");

            if ($minId > 0) {
                $pdo->prepare("UPDATE iibb_minimos SET tipo_agente=?, descripcion=?, minimo_no_sujeto=? WHERE id=?")
                    ->execute([$tipo_agente, $descripcion, $minimo, $minId]);
                $mensaje = "Mínimo actualizado.";
            } else {
                $pdo->prepare("INSERT INTO iibb_minimos (tipo_agente, descripcion, minimo_no_sujeto) VALUES (?,?,?)")
                    ->execute([$tipo_agente, $descripcion, $minimo]);
                $mensaje = "Mínimo creado.";
            }
            $tipo_alerta = 'success';

        } elseif ($seccion === 'minimo_eliminar') {
            $minId = (int)($_POST['min_id'] ?? 0);
            if ($minId) {
                $pdo->prepare("DELETE FROM iibb_minimos WHERE id = ?")->execute([$minId]);
                $mensaje = "Mínimo eliminado.";
                $tipo_alerta = 'success';
            }
        }
    } catch (Exception $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// Cargar datos
$categorias = $pdo->query("SELECT * FROM iibb_categorias ORDER BY orden, codigo")->fetchAll();
$minimos = $pdo->query("SELECT * FROM iibb_minimos ORDER BY tipo_agente")->fetchAll();

function fmtN($v) { return number_format((float)$v, 2, ',', '.'); }
?>

<div class="container my-4" style="max-width:1000px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="text-success fw-bold mb-0">
                <i class="bi bi-geo-alt"></i> Configuración IIBB
            </h3>
            <p class="text-muted small mb-0">Resolución 276/DPR/17 – Provincia del Neuquén</p>
        </div>
        <a href="../liquidacion_form.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show py-2">
        <?= htmlspecialchars($mensaje) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ===== CATEGORÍAS (Art. 11) ===== -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-success text-white fw-bold">
            <i class="bi bi-list-ol"></i> Categorías de Retención – Art. 11
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:60px">Código</th>
                            <th>Descripción</th>
                            <th class="text-end" style="width:90px">Alícuota %</th>
                            <th class="text-center" style="width:60px">Orden</th>
                            <th class="text-center" style="width:60px">Activo</th>
                            <th style="width:100px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categorias as $cat): ?>
                        <tr>
                            <form method="POST">
                                <input type="hidden" name="seccion" value="categoria_guardar">
                                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                <td>
                                    <input type="text" name="codigo" class="form-control form-control-sm" value="<?= htmlspecialchars($cat['codigo']) ?>" maxlength="5" required>
                                </td>
                                <td>
                                    <input type="text" name="descripcion" class="form-control form-control-sm" value="<?= htmlspecialchars($cat['descripcion']) ?>" required>
                                </td>
                                <td>
                                    <input type="text" name="alicuota" class="form-control form-control-sm text-end" value="<?= fmtN($cat['alicuota']) ?>" required>
                                </td>
                                <td>
                                    <input type="number" name="orden" class="form-control form-control-sm text-center" value="<?= $cat['orden'] ?>" min="0">
                                </td>
                                <td class="text-center">
                                    <input type="hidden" name="activo" value="0">
                                    <input type="checkbox" name="activo" class="form-check-input" value="1" <?= $cat['activo'] ? 'checked' : '' ?>>
                                </td>
                                <td>
                                    <button type="submit" class="btn btn-outline-primary btn-sm py-0" title="Guardar"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="seccion" value="categoria_eliminar">
                                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0" title="Eliminar" onclick="return confirm('¿Eliminar esta categoría?')"><i class="bi bi-trash"></i></button>
                            </form>
                                </td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Fila para agregar nueva -->
                        <tr class="table-success">
                            <form method="POST">
                                <input type="hidden" name="seccion" value="categoria_guardar">
                                <input type="hidden" name="cat_id" value="0">
                                <td><input type="text" name="codigo" class="form-control form-control-sm" placeholder="i" maxlength="5" required></td>
                                <td><input type="text" name="descripcion" class="form-control form-control-sm" placeholder="Nueva categoría..." required></td>
                                <td><input type="text" name="alicuota" class="form-control form-control-sm text-end" placeholder="0,00" required></td>
                                <td><input type="number" name="orden" class="form-control form-control-sm text-center" value="10" min="0"></td>
                                <td class="text-center">
                                    <input type="hidden" name="activo" value="1">
                                    <input type="checkbox" name="activo" class="form-check-input" value="1" checked disabled>
                                </td>
                                <td>
                                    <button type="submit" class="btn btn-success btn-sm py-0"><i class="bi bi-plus-lg"></i> Agregar</button>
                                </td>
                            </form>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== MÍNIMOS NO SUJETOS (Art. 7) ===== -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-warning-subtle fw-bold">
            <i class="bi bi-shield-check"></i> Mínimos No Sujetos a Retención – Art. 7
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:150px">Tipo Agente</th>
                            <th>Descripción</th>
                            <th class="text-end" style="width:150px">Mínimo $</th>
                            <th style="width:100px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($minimos as $min): ?>
                        <tr>
                            <form method="POST">
                                <input type="hidden" name="seccion" value="minimo_guardar">
                                <input type="hidden" name="min_id" value="<?= $min['id'] ?>">
                                <td>
                                    <input type="text" name="tipo_agente" class="form-control form-control-sm" value="<?= htmlspecialchars($min['tipo_agente']) ?>" required>
                                </td>
                                <td>
                                    <input type="text" name="min_descripcion" class="form-control form-control-sm" value="<?= htmlspecialchars($min['descripcion']) ?>" required>
                                </td>
                                <td>
                                    <input type="text" name="minimo_no_sujeto" class="form-control form-control-sm text-end" value="<?= fmtN($min['minimo_no_sujeto']) ?>" required>
                                </td>
                                <td>
                                    <button type="submit" class="btn btn-outline-primary btn-sm py-0" title="Guardar"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="seccion" value="minimo_eliminar">
                                <input type="hidden" name="min_id" value="<?= $min['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0" title="Eliminar" onclick="return confirm('¿Eliminar?')"><i class="bi bi-trash"></i></button>
                            </form>
                                </td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Fila para agregar nueva -->
                        <tr class="table-warning">
                            <form method="POST">
                                <input type="hidden" name="seccion" value="minimo_guardar">
                                <input type="hidden" name="min_id" value="0">
                                <td><input type="text" name="tipo_agente" class="form-control form-control-sm" placeholder="NUEVO_TIPO" required></td>
                                <td><input type="text" name="min_descripcion" class="form-control form-control-sm" placeholder="Descripción del tipo de agente..." required></td>
                                <td><input type="text" name="minimo_no_sujeto" class="form-control form-control-sm text-end" placeholder="0,00" required></td>
                                <td>
                                    <button type="submit" class="btn btn-warning btn-sm py-0"><i class="bi bi-plus-lg"></i> Agregar</button>
                                </td>
                            </form>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            <small class="text-muted">
                <i class="bi bi-info-circle"></i>
                <strong>Art. 7 Res. 276/DPR/17:</strong> 
                ESTADO = Reparticiones Nac/Prov/Mun ($10.000) | 
                PRIVADO = Agentes no incluidos en el inciso anterior ($5.000)
            </small>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../../public/_footer.php'; ?>

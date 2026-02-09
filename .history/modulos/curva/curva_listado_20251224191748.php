<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';
 // debe proveer $pdo (PDO)
$basePath = __DIR__ . '/../../';

$hasHeader = file_exists($basePath . 'public/_header.php');
$hasFooter = file_exists($basePath . 'public/_footer.php');

if ($hasHeader) {
    require_once $basePath . 'public/_header.php';
}



try {
    // Listado de obras + curva vigente (si existe) + última versión (si existe)
    $sql = "
    SELECT
        o.id AS obra_id,
        o.denominacion AS obra_denominacion,
        o.monto_actualizado,
        o.activo,

        cv_vig.id AS curva_vig_id,
        cv_vig.nro_version AS curva_vig_version,
        cv_vig.modo AS curva_vig_modo,
        cv_vig.fecha_desde AS curva_vig_desde,
        cv_vig.fecha_hasta AS curva_vig_hasta,

        cv_last.id AS curva_last_id,
        cv_last.nro_version AS curva_last_version,
        cv_last.created_at AS curva_last_created
    FROM obras o
    LEFT JOIN curva_version cv_vig
           ON cv_vig.obra_id = o.id AND cv_vig.es_vigente = 1
    LEFT JOIN curva_version cv_last
           ON cv_last.id = (
               SELECT cv2.id
               FROM curva_version cv2
               WHERE cv2.obra_id = o.id
               ORDER BY cv2.nro_version DESC, cv2.id DESC
               LIMIT 1
           )
    WHERE o.activo = 1
    ORDER BY o.denominacion ASC
";

    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $rows = [];
    $error = $e->getMessage();
}
?>

<div class="container my-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="mb-0">Curvas y Proyecciones</h3>

        <a href="../../public/menu.php" class="btn btn-secondary">
            Volver al menú
        </a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            Error al cargar datos: <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:110px;">Código</th>
                            <th>Obra</th>
                            <th style="width:170px;">Monto actualizado</th>
                            <th style="width:240px;">Curva vigente</th>
                            <th style="width:210px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr>
                            <td colspan="5" class="text-muted">No hay obras activas para mostrar.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $obraId = (int)$r['obra_id'];
                                $tieneVigente = !empty($r['curva_vig_id']);

                                $monto = (float)($r['monto_actualizado'] ?? 0);
                                $montoFmt = number_format($monto, 2, ',', '.');

                                if ($tieneVigente) {
                                    $vigTxt = "V" . (int)$r['curva_vig_version']
                                            . " · " . htmlspecialchars($r['curva_vig_modo'])
                                            . " · " . htmlspecialchars($r['curva_vig_desde'])
                                            . " a " . htmlspecialchars($r['curva_vig_hasta']);
                                    $badge = 'success';
                                } else {
                                    if (!empty($r['curva_last_id'])) {
                                        $vigTxt = "Sin vigente · Última: V" . (int)$r['curva_last_version']
                                                . " (" . htmlspecialchars($r['curva_last_created']) . ")";
                                        $badge = 'warning';
                                    } else {
                                        $vigTxt = "Sin curvas cargadas";
                                        $badge = 'secondary';
                                    }
                                }
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($r['obra_codigo'] ?? '') ?></td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($r['obra_denominacion'] ?? '') ?></div>
                                    <div class="text-muted small">ID: <?= $obraId ?></div>
                                </td>
                                <td>$ <?= $montoFmt ?></td>
                                <td>
                                    <span class="badge bg-<?= $badge ?>"><?= $vigTxt ?></span>
                                </td>
                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php if ($tieneVigente): ?>
                                            <a class="btn btn-primary btn-sm"
                                               href="curva_view.php?obra_id=<?= $obraId ?>">
                                                Ver curva / proyección
                                            </a>
                                        <?php else: ?>
                                            <a class="btn btn-outline-primary btn-sm"
                                               href="curva_form_generate.php?obra_id=<?= $obraId ?>">
                                                Generar curva
                                            </a>
                                        <?php endif; ?>

                                        <a class="btn btn-outline-secondary btn-sm"
                                           href="curva_versiones.php?obra_id=<?= $obraId ?>">
                                            Versiones
                                        </a>

                                        <a class="btn btn-outline-success btn-sm"
                                           href="curva_form_generate.php?obra_id=<?= $obraId ?>">
                                            Nueva versión
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="text-muted small mt-2">
        * “Nueva versión” genera una curva nueva y la deja como vigente.
    </div>
</div>

<?php
if ($hasFooter) {
    require_once $basePath . 'public/_footer.php';
}

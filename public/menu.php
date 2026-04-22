<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/database.php';
require_login();
include __DIR__ . '/_header.php';

// Config-driven menu (Opción B)
$menu_config = require __DIR__ . '/../config/menu.php';
$modulos = $menu_config['modules'] ?? [];

$roles_usuario = $_SESSION['user_roles'] ?? [];
$es_admin = is_admin();

function m_puede_ver_modulo(array $modulo): bool {
    if ($modulo['always_show'] ?? false) return true;
    if (isset($modulo['key'])) return can_access_module($modulo['key']);
    return false;
}

function m_puede_ver_item(array $item, bool $es_admin): bool {
    if (!isset($item['requires'])) return true;
    return match($item['requires']) {
        'admin' => $es_admin,
        'edit'  => can_edit(),
        default => true
    };
}

function m_badge(?array $badge): string {
    if (!$badge) return '';
    $clase = htmlspecialchars($badge['class'] ?? 'bg-light text-dark border');
    $texto = htmlspecialchars($badge['text'] ?? '');
    return "<span class=\"badge {$clase}\">{$texto}</span>";
}

function m_icon(string $icon, ?string $color = null): string {
    $c = $color ? " text-{$color}" : '';
    return "<i class=\"bi bi-{$icon} me-2{$c}\"></i>";
}

function m_header_style(string $color): string {
    return str_starts_with($color, '#') ? "style=\"background:{$color}\"" : '';
}

function m_header_class(string $color): string {
    if (str_starts_with($color, '#')) return 'card-header text-white fw-bold';
    return "card-header bg-{$color} text-white fw-bold";
}

// Separar módulos
$simples = [];
$complejos = [];
foreach ($modulos as $modulo) {
    if (!m_puede_ver_modulo($modulo)) continue;
    if ($modulo['is_complex'] ?? false) $complejos[] = $modulo;
    else $simples[] = $modulo;
}

$filas_simples = array_chunk($simples, 3);
?>

<div class="container-fluid my-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
        <div>
            <h2 class="mb-0 fw-bold">Panel de Gestión</h2>
            <small class="text-muted"><?= htmlspecialchars($_SESSION['user_nombre'] ?? '') ?></small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php foreach ($roles_usuario as $r): ?>
                <span class="badge fs-6 <?= match(strtolower($r)) {
                    'admin'    => 'bg-dark',
                    'editor'   => 'bg-primary',
                    'consulta' => 'bg-secondary',
                    default    => 'bg-secondary'
                } ?>"><?= htmlspecialchars($r) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Filas de módulos simples -->
    <?php foreach ($filas_simples as $fila): ?>
    <div class="row g-4 mb-4">
        <?php foreach ($fila as $modulo): ?>
        <div class="col-md-6 col-lg-<?= $modulo['columns'] ?? 4 ?>">
            <div class="card h-100 border-0 shadow-sm hover-card">
                <div class="<?= m_header_class($modulo['color'] ?? 'primary') ?>" <?= m_header_style($modulo['color'] ?? 'primary') ?>>
                    <?= m_icon($modulo['icon'] ?? 'grid') ?><?= htmlspecialchars($modulo['title']) ?>
                </div>

                <?php if ($modulo['has_subsections'] ?? false): ?>
                    <?php foreach ($modulo['subsections'] ?? [] as $sub): ?>
                        <?php if (isset($sub['requires']) && !m_puede_ver_item(['requires' => $sub['requires']], $es_admin)) continue; ?>
                        <div class="list-group-item bg-light border-0 py-1 px-3">
                            <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                                <?= m_icon($sub['title_icon'] ?? 'circle') ?><?= htmlspecialchars($sub['title']) ?>
                            </small>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($sub['items'] ?? [] as $item): ?>
                                <?php if (!m_puede_ver_item($item, $es_admin)) continue; ?>
                                <a href="<?= htmlspecialchars($item['href']) ?>" class="list-group-item list-group-item-action <?= $item['highlight'] ?? false ? 'bg-warning-subtle' : '' ?> d-flex justify-content-between align-items-center">
                                    <span><?= m_icon($item['icon'] ?? 'circle', $item['icon_color'] ?? null) ?><?= ($item['highlight'] ?? false) ? '<strong>'.htmlspecialchars($item['label']).'</strong>' : htmlspecialchars($item['label']) ?></span>
                                    <?= m_badge($item['badge'] ?? null) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($modulo['items'] ?? [] as $item): ?>
                            <?php if (!m_puede_ver_item($item, $es_admin)) continue; ?>
                            <a href="<?= htmlspecialchars($item['href']) ?>" class="list-group-item list-group-item-action <?= $item['highlight'] ?? false ? 'bg-warning-subtle' : '' ?> d-flex justify-content-between align-items-center">
                                <span><?= m_icon($item['icon'] ?? 'circle', $item['icon_color'] ?? null) ?><?= ($item['highlight'] ?? false) ? '<strong>'.htmlspecialchars($item['label']).'</strong>' : htmlspecialchars($item['label']) ?></span>
                                <?= m_badge($item['badge'] ?? null) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- Módulos complejos (Programas) -->
    <?php foreach ($complejos as $modulo): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="<?= m_header_class($modulo['color'] ?? 'success') ?>" <?= m_header_style($modulo['color'] ?? 'success') ?>>
                    <?= m_icon($modulo['icon'] ?? 'grid') ?><?= htmlspecialchars($modulo['title']) ?>
                </div>
                <div class="row g-0">
                    <?php foreach ($modulo['sections'] ?? [] as $section): ?>
                    <div class="<?= $section['column_class'] ?? 'col-md-4' ?>">
                        <div class="list-group list-group-flush <?= $section['border_class'] ?? '' ?>">
                            <?php if ($section['show_title'] ?? false): ?>
                            <div class="list-group-item bg-light py-1 px-3">
                                <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                                    <?= m_icon($section['title_icon'] ?? 'circle') ?><?= htmlspecialchars($section['title']) ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($section['items'] ?? [] as $item): ?>
                                <?php if (!m_puede_ver_item($item, $es_admin)) continue; ?>
                                <a href="<?= htmlspecialchars($item['href']) ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <span><?= m_icon($item['icon'] ?? 'circle', $item['icon_color'] ?? null) ?><?= htmlspecialchars($item['label']) ?></span>
                                    <?= m_badge($item['badge'] ?? null) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<style>
.hover-card { transition: transform 0.2s, box-shadow 0.2s; }
.hover-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1)!important; }
.list-group-item-action { transition: background-color 0.15s, padding-left 0.15s; }
.list-group-item-action:hover { padding-left: 1.5rem; }
</style>

<?php include __DIR__ . '/_footer.php'; ?>

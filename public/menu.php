<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/database.php';
require_login();
include __DIR__ . '/_header.php';

// Cargar configuración del menú
$menu_config = require __DIR__ . '/../config/menu.php';
$modulos = $menu_config['modules'] ?? [];

$roles_usuario = $_SESSION['user_roles'] ?? [];
$es_admin = is_admin();

/**
 * Verifica si el usuario tiene acceso a un módulo
 */
function puede_ver_modulo(array $modulo, bool $es_admin): bool {
    // Si tiene 'always_show', se muestra sin verificar permisos específicos
    if ($modulo['always_show'] ?? false) {
        return true;
    }
    // Verificar permiso por clave del módulo
    if (isset($modulo['key'])) {
        return can_access_module($modulo['key']);
    }
    return false;
}

/**
 * Verifica requisitos adicionales de un item
 */
function puede_ver_item(array $item, bool $es_admin): bool {
    if (!isset($item['requires'])) {
        return true;
    }
    switch ($item['requires']) {
        case 'admin':
            return $es_admin;
        case 'edit':
            return can_edit();
        default:
            return true;
    }
}

/**
 * Renderiza el badge de un item
 */
function render_badge(?array $badge): string {
    if (!$badge) return '';
    $clase = htmlspecialchars($badge['class'] ?? 'bg-light text-dark border');
    $texto = htmlspecialchars($badge['text'] ?? '');
    return "<span class=\"badge {$clase}\">{$texto}</span>";
}

/**
 * Renderiza el icono de Bootstrap Icons
 */
function render_icon(string $icon, ?string $color = null): string {
    $color_class = $color ? " text-{$color}" : '';
    return "<i class=\"bi bi-{$icon} me-2{$color_class}\"></i>";
}
?>

<div class="container my-4">
    <!-- Header del Panel -->
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

    <?php
    // Separar módulos por tipo de layout
    $simple_modules = [];
    $complex_modules = [];
    
    foreach ($modulos as $modulo) {
        if (!puede_ver_modulo($modulo, $es_admin)) {
            continue;
        }
        if ($modulo['is_complex'] ?? false) {
            $complex_modules[] = $modulo;
        } else {
            $simple_modules[] = $modulo;
        }
    }
    
    // Agrupar módulos simples en filas de máximo 3
    $rows = array_chunk($simple_modules, 3);
    ?>

    <!-- FILAS: Módulos simples -->
    <?php foreach ($rows as $row): ?>
    <div class="row g-4 mb-4">
        <?php foreach ($row as $modulo): ?>
        <div class="col-md-6 col-lg-<?= $modulo['columns'] ?? 4 ?>">
            <div class="card h-100 border-0 shadow-sm">
                <?php
                $color = $modulo['color'] ?? 'primary';
                $style = str_starts_with($color, '#') ? "style=\"background:{$color}\"" : "class=\"card-header bg-{$color} text-white fw-bold\"";
                ?>
                <div <?= $style ?> class="card-header text-white fw-bold">
                    <?= render_icon($modulo['icon'] ?? 'grid') ?><?= htmlspecialchars($modulo['title']) ?>
                </div>
                
                <?php if ($modulo['has_subsections'] ?? false): ?>
                    <!-- Módulo con sub-secciones (SICOPRO, Configuración) -->
                    <?php foreach ($modulo['subsections'] ?? [] as $subsection): ?>
                        <?php if (isset($subsection['requires']) && !puede_ver_item(['requires' => $subsection['requires']], $es_admin)) continue; ?>
                        
                        <div class="list-group-item bg-light border-0 py-1 px-3">
                            <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                                <?= render_icon($subsection['title_icon'] ?? 'circle') ?><?= htmlspecialchars($subsection['title']) ?>
                            </small>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($subsection['items'] ?? [] as $item): ?>
                                <?php if (!puede_ver_item($item, $es_admin)) continue; ?>
                                <a href="<?= htmlspecialchars($item['href']) ?>" 
                                   class="list-group-item list-group-item-action <?= $item['highlight'] ?? false ? 'bg-warning-subtle' : '' ?> d-flex justify-content-between align-items-center">
                                    <span><?= render_icon($item['icon'] ?? 'circle', $item['icon_color'] ?? null) ?><?= $item['highlight'] ?? false ? '<strong>' : '' ?><?= htmlspecialchars($item['label']) ?><?= $item['highlight'] ?? false ? '</strong>' : '' ?></span>
                                    <?= render_badge($item['badge'] ?? null) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    
                <?php else: ?>
                    <!-- Módulo simple con items directos -->
                    <div class="list-group list-group-flush">
                        <?php foreach ($modulo['items'] ?? [] as $item): ?>
                            <?php if (!puede_ver_item($item, $es_admin)) continue; ?>
                            <a href="<?= htmlspecialchars($item['href']) ?>" 
                               class="list-group-item list-group-item-action <?= $item['highlight'] ?? false ? 'bg-warning-subtle' : '' ?> d-flex justify-content-between align-items-center">
                                <span><?= render_icon($item['icon'] ?? 'circle', $item['icon_color'] ?? null) ?><?= $item['highlight'] ?? false ? '<strong>' : '' ?><?= htmlspecialchars($item['label']) ?><?= $item['highlight'] ?? false ? '</strong>' : '' ?></span>
                                <?= render_badge($item['badge'] ?? null) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- FILAS: Módulos complejos (Programas) -->
    <?php foreach ($complex_modules as $modulo): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <?php
                $color = $modulo['color'] ?? 'primary';
                $style = str_starts_with($color, '#') ? "style=\"background:{$color}\"" : "class=\"card-header bg-{$color} text-white fw-bold\"";
                ?>
                <div <?= $style ?> class="card-header text-white fw-bold">
                    <?= render_icon($modulo['icon'] ?? 'grid') ?><?= htmlspecialchars($modulo['title']) ?>
                </div>
                <div class="row g-0">
                    <?php foreach ($modulo['sections'] ?? [] as $section): ?>
                    <div class="<?= $section['column_class'] ?? 'col-md-4' ?>">
                        <div class="list-group list-group-flush <?= $section['border_class'] ?? '' ?>">
                            <?php if ($section['show_title'] ?? false): ?>
                            <div class="list-group-item bg-light py-1 px-3">
                                <small class="text-muted fw-bold text-uppercase" style="font-size:.7rem">
                                    <?= render_icon($section['title_icon'] ?? 'circle') ?><?= htmlspecialchars($section['title']) ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <?php foreach ($section['items'] ?? [] as $item): ?>
                                <?php if (!puede_ver_item($item, $es_admin)) continue; ?>
                                <a href="<?= htmlspecialchars($item['href']) ?>" 
                                   class="list-group-item list-group-item-action <?= $item['highlight'] ?? false ? 'bg-warning-subtle' : '' ?> d-flex justify-content-between align-items-center">
                                    <span><?= render_icon($item['icon'] ?? 'circle', $item['icon_color'] ?? null) ?><?= $item['highlight'] ?? false ? '<strong>' : '' ?><?= htmlspecialchars($item['label']) ?><?= $item['highlight'] ?? false ? '</strong>' : '' ?></span>
                                    <?= render_badge($item['badge'] ?? null) ?>
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

<?php include __DIR__ . '/_footer.php'; ?>

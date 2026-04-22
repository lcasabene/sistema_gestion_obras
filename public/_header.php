<?php
require_once __DIR__ . '/../config/config.php';

// Cargar config del menú para el sidebar
$__menu_config = require __DIR__ . '/../config/menu.php';
$__modulos_sidebar = $__menu_config['modules'] ?? [];
$__current_page = basename($_SERVER['PHP_SELF']);
$__es_admin = function_exists('is_admin') ? is_admin() : false;

function _sb_is_active(array $modulo, string $current_page): bool {
    $all_items = array_merge(
        $modulo['items'] ?? [],
        ...array_column($modulo['subsections'] ?? [], 'items'),
        ...array_column($modulo['sections'] ?? [], 'items')
    );
    foreach ($all_items as $item) {
        if (isset($item['href']) && basename(parse_url($item['href'], PHP_URL_PATH) ?? '') === $current_page) {
            return true;
        }
    }
    return false;
}

function _sb_puede_ver_item(array $item, bool $es_admin): bool {
    if (!isset($item['requires'])) return true;
    return match($item['requires']) {
        'admin' => $es_admin,
        'edit'  => function_exists('can_edit') && can_edit(),
        default => true
    };
}

function _sb_main_link(array $modulo, bool $es_admin): string {
    // Primer item visible del módulo (para el link del sidebar)
    foreach ($modulo['items'] ?? [] as $item) {
        if (_sb_puede_ver_item($item, $es_admin)) return $item['href'];
    }
    foreach ($modulo['subsections'] ?? [] as $s) {
        foreach ($s['items'] ?? [] as $item) {
            if (_sb_puede_ver_item($item, $es_admin)) return $item['href'];
        }
    }
    foreach ($modulo['sections'] ?? [] as $s) {
        foreach ($s['items'] ?? [] as $item) {
            if (_sb_puede_ver_item($item, $es_admin)) return $item['href'];
        }
    }
    return 'menu.php';
}

/**
 * Convierte URLs relativas del config (ej: "../modulos/obras/x.php")
 * a URLs absolutas basadas en BASE_URL, para que el sidebar funcione
 * desde cualquier profundidad de directorio.
 */
function _sb_abs_url(string $href): string {
    // Si ya es absoluta (http://, /, etc) la dejamos
    if (preg_match('#^(https?://|/)#', $href)) return $href;
    // Quitar "../" iniciales
    $clean = preg_replace('#^(\.\./)+#', '', $href);
    return rtrim(BASE_URL, '/') . '/' . ltrim($clean, '/');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo APP_NAME; ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo BASE_URL; ?>public/assets/css/sidebar.css" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">

  <!-- ===== SIDEBAR ===== -->
  <aside class="app-sidebar" id="appSidebar">
    <div class="app-sidebar-header">
      <a href="<?php echo BASE_URL; ?>public/index.php" class="app-sidebar-brand">
        <i class="bi bi-building-gear"></i>
        <span><?php echo APP_NAME; ?></span>
      </a>
    </div>

    <nav class="app-sidebar-nav">
      <div class="app-nav-section">
        <div class="app-nav-title">Principal</div>
        <a href="<?php echo BASE_URL; ?>public/index.php" class="app-nav-link <?= $__current_page === 'index.php' ? 'active' : '' ?>">
          <i class="bi bi-speedometer2"></i>
          <span class="nav-label">Tablero de Control</span>
        </a>
        <a href="<?php echo BASE_URL; ?>public/menu.php" class="app-nav-link <?= $__current_page === 'menu.php' ? 'active' : '' ?>">
          <i class="bi bi-grid-3x3-gap-fill"></i>
          <span class="nav-label">Menú General</span>
        </a>
      </div>

      <div class="app-nav-section">
        <div class="app-nav-title">Módulos</div>
        <?php foreach ($__modulos_sidebar as $mod): ?>
          <?php
          // Permisos
          $visible = ($mod['always_show'] ?? false) || (function_exists('can_access_module') && can_access_module($mod['key'] ?? ''));
          if (!$visible) continue;
          $link = _sb_main_link($mod, $__es_admin);
          $activo = _sb_is_active($mod, $__current_page);
          ?>
          <a href="<?= htmlspecialchars(_sb_abs_url($link)) ?>" class="app-nav-link <?= $activo ? 'active' : '' ?>">
            <i class="bi bi-<?= htmlspecialchars($mod['icon'] ?? 'circle') ?>"></i>
            <span class="nav-label"><?= htmlspecialchars($mod['title'] ?? '') ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </nav>

    <div class="app-sidebar-footer">
      <div class="app-user-box">
        <div class="app-user-avatar">
          <?= strtoupper(substr($_SESSION['user_nombre'] ?? 'U', 0, 1)) ?>
        </div>
        <div class="app-user-info">
          <div class="app-user-name"><?= htmlspecialchars($_SESSION['user_nombre'] ?? 'Usuario') ?></div>
          <div class="app-user-role"><?= htmlspecialchars(implode(', ', $_SESSION['user_roles'] ?? [])) ?></div>
        </div>
      </div>
      <a href="<?php echo BASE_URL; ?>auth/logout.php" class="app-logout-btn">
        <i class="bi bi-box-arrow-right"></i>
        <span>Cerrar Sesión</span>
      </a>
    </div>
  </aside>

  <div class="app-sidebar-overlay" id="appSidebarOverlay" onclick="document.getElementById('appSidebar').classList.remove('show');this.classList.remove('show');"></div>

  <!-- ===== MAIN ===== -->
  <div class="app-main">
    <header class="app-topbar">
      <div class="app-topbar-left">
        <button class="app-sidebar-toggle" onclick="document.getElementById('appSidebar').classList.toggle('show');document.getElementById('appSidebarOverlay').classList.toggle('show');">
          <i class="bi bi-list"></i>
        </button>
        <h1 class="app-topbar-title"><?php echo APP_NAME; ?></h1>
      </div>
      <div class="app-topbar-right">
        <span><i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y') ?></span>
      </div>
    </header>

    <div class="app-content">
<?php // A partir de aquí cada página usa su propio <div class="container ..."> ?>

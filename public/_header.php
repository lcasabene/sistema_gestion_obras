<?php
require_once __DIR__ . '/../config/config.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo APP_NAME; ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php"><?php echo APP_NAME; ?></a>
    <div class="d-flex align-items-center gap-3 text-white">
      <div class="small">
        <?php echo htmlspecialchars($_SESSION['user_nombre'] ?? ''); ?>
        <?php if (!empty($_SESSION['user_roles'])): ?>
          <span class="badge bg-secondary"><?php echo htmlspecialchars(implode(', ', $_SESSION['user_roles'])); ?></span>
        <?php endif; ?>
      </div>
      <a class="btn btn-outline-light btn-sm" href="../auth/logout.php">
        <i class="bi bi-box-arrow-right me-1"></i>Salir
      </a>
    </div>
  </div>
</nav>
<main class="container py-4">

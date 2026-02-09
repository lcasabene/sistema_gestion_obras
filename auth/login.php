<?php
require_once __DIR__ . '/../config/session_config.php';
secure_session_start();

if (!empty($_SESSION['user_id']) && is_session_valid()) {
    header("Location: " . (defined('BASE_URL') ? BASE_URL : '') . "public/index.php");
    exit;
}
require_once __DIR__ . '/../config/config.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo APP_NAME; ?> - Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-5">
        <div class="card shadow-sm">
          <div class="card-body p-4">
            <h4 class="mb-3"><?php echo APP_NAME; ?></h4>
            <?php if (!empty($_GET['err'])): ?>
              <div class="alert alert-danger">Usuario o contraseña incorrectos.</div>
            <?php endif; ?>
            <?php if (!empty($_GET['out'])): ?>
              <div class="alert alert-success">Sesión cerrada.</div>
            <?php endif; ?>
            <?php if (!empty($_GET['expired'])): ?>
              <div class="alert alert-warning">Tu sesión ha expirado. Por favor inicia sesión nuevamente.</div>
            <?php endif; ?>

            <form method="post" action="login_process.php" autocomplete="off">
              <div class="mb-3">
                <label class="form-label">Usuario</label>
                <input type="text" name="usuario" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password" name="password" class="form-control" required>
              </div>
              <button class="btn btn-primary w-100" type="submit">Ingresar</button>
            </form>

            <div class="text-muted mt-3 small">
              Versión <?php echo APP_VERSION; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

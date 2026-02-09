<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
include __DIR__ . '/../../public/_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Administración</h3>
  <a class="btn btn-secondary" href="../../public/menu.php"><i class="bi bi-arrow-left-circle me-1"></i> Volver</a>
</div>
<div class="alert alert-info">Administración - próximo: ABM usuarios/roles/catálogos.</div>
<?php include __DIR__ . '/../../public/_footer.php'; ?>

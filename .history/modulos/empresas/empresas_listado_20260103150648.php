<?php
require_once __DIR__ . '/../../config/database.php';
// ... (Include header, auth, etc) ...

// Formulario Rápido de Alta en el mismo listado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sql = "INSERT INTO empresas (razon_social, cuit, codigo_proveedor) VALUES (?, ?, ?)";
    $pdo->prepare($sql)->execute([$_POST['razon'], $_POST['cuit'], $_POST['proveedor']]);
    header("Location: empresas_listado.php"); exit;
}

$empresas = $pdo->query("SELECT * FROM empresas WHERE activo=1")->fetchAll();
?>
<div class="container my-4">
    <h3>Empresas Contratistas</h3>
    
    <div class="card p-3 mb-4 bg-light">
        <form method="POST" class="row g-3">
            <div class="col-md-4"><input type="text" name="razon" class="form-control" placeholder="Razón Social" required></div>
            <div class="col-md-3"><input type="text" name="cuit" class="form-control" placeholder="CUIT (sin guiones)" required></div>
            <div class="col-md-3"><input type="text" name="proveedor" class="form-control" placeholder="Cód. Proveedor"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-success w-100">Agregar</button></div>
        </form>
    </div>

    <table class="table table-bordered">
        <thead><tr><th>ID</th><th>Razón Social</th><th>CUIT</th><th>Proveedor</th></tr></thead>
        <tbody>
            <?php foreach($empresas as $e): ?>
            <tr>
                <td><?= $e['id'] ?></td>
                <td><?= $e['razon_social'] ?></td>
                <td><?= $e['cuit'] ?></td>
                <td><?= $e['codigo_proveedor'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
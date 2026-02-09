<?php
// organismos_guardar.php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

// Validar que la solicitud sea POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nombre_organismo = trim($_POST['nombre_organismo'] ?? '');
    $descripcion_programa = trim($_POST['descripcion_programa'] ?? '');

    // Validación básica de campos obligatorios
    if (empty($nombre_organismo) || empty($descripcion_programa)) {
        header("Location: organismos_form.php?id=$id&error=campos_vacios");
        exit;
    }

    try {
        if ($id > 0) {
            // --- ACTUALIZACIÓN ---
            $sql = "UPDATE organismos_financiadores 
                    SET nombre_organismo = ?, descripcion_programa = ? 
                    WHERE id = ? AND activo = 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre_organismo, $descripcion_programa, $id]);
            $mensaje = "updated";
        } else {
            // --- INSERCIÓN ---
            $sql = "INSERT INTO organismos_financiadores (nombre_organismo, descripcion_programa, activo) 
                    VALUES (?, ?, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre_organismo, $descripcion_programa]);
            $mensaje = "inserted";
        }

        // Redirigir al listado con éxito
        header("Location: organismos_lista.php?msg=$mensaje");
        exit;

    } catch (PDOException $e) {
        // En caso de error, podrías loguearlo y avisar al usuario
        error_log("Error en organismos_guardar: " . $e->getMessage());
        header("Location: organismos_form.php?id=$id&error=db");
        exit;
    }
} else {
    // Si se intenta acceder directamente por URL
    header("Location: organismos_lista.php");
    exit;
}
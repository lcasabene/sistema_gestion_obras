<?php
// ==========================================
// 1. CONFIGURACIÓN (Ajusta si es necesario)
// ==========================================
$host = 'localhost';
$db   = 'gestion_obra_1'; 
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("❌ Error de conexión: " . $e->getMessage());
}

echo "<h3>Iniciando instalación...</h3>";

// ==========================================
// 2. CREACIÓN DE ESTRUCTURA (DDL)
// Hacemos esto FUERA de la transacción para evitar el error de "Implicit Commit"
// ==========================================
try {
    // A. Crear tabla ROLES
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(50) NOT NULL UNIQUE
    )");
    echo "✔ Tabla 'roles' verificada.<br>";

    // B. Crear tabla INTERMEDIA usuarios_roles (Probablemente esto faltaba)
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios_roles (
        usuario_id INT(11) NOT NULL,
        rol_id INT(11) NOT NULL,
        PRIMARY KEY (usuario_id, rol_id)
    )");
    echo "✔ Tabla 'usuarios_roles' verificada.<br>";

} catch (Exception $e) {
    die("❌ Error creando tablas: " . $e->getMessage());
}

// ==========================================
// 3. INSERCIÓN DE DATOS (DML)
// Ahora sí iniciamos la transacción segura
// ==========================================
try {
    $pdo->beginTransaction();

    // --- Insertar Roles ---
    $roles = [
        1 => 'ADMIN',
        2 => 'PRESUPUESTO',
        3 => 'DEUDA',
        4 => 'OBRAS'
    ];
    $stmtRol = $pdo->prepare("INSERT IGNORE INTO roles (id, nombre) VALUES (?, ?)");
    foreach ($roles as $id => $nombre) {
        $stmtRol->execute([$id, $nombre]);
    }
    echo "✔ Roles insertados.<br>";

    // --- Insertar Usuarios ---
    $password = password_hash('123456', PASSWORD_DEFAULT);
    
    // usuario => [rol_id, nombre_real, email]
    $usuariosPrueba = [
        'admin' => [1, 'Administrador General', 'admin@prueba.com'],
        'pepe_presupuesto' => [2, 'Pepe Finanzas', 'pepe@prueba.com'],
        'dani_deuda' => [3, 'Daniel Deuda', 'dani@prueba.com'],
        'oscar_obras' => [4, 'Oscar Constructor', 'oscar@prueba.com']
    ];

    $stmtUser = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, email, password_hash, activo) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
    $stmtRel  = $pdo->prepare("INSERT IGNORE INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)");
    $stmtId   = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");

    foreach ($usuariosPrueba as $username => $datos) {
        $rolId = $datos[0];
        $nombre = $datos[1];
        $email = $datos[2];

        // 1. Insertar o actualizar usuario
        $stmtUser->execute([$username, $nombre, $email, $password]);
        
        // 2. Buscar su ID (necesario si ya existía)
        $stmtId->execute([$username]);
        $userId = $stmtId->fetchColumn();

        // 3. Asignar Rol
        if ($userId) {
            // Borramos rol previo para asegurar que quede limpio en esta prueba
            $pdo->exec("DELETE FROM usuarios_roles WHERE usuario_id = $userId");
            $stmtRel->execute([$userId, $rolId]);
            echo "✔ Usuario <strong>$username</strong> configurado con Rol ID: $rolId.<br>";
        }
    }

    $pdo->commit();
    echo "<hr><h2 style='color:green'>¡INSTALACIÓN COMPLETADA!</h2>";
    echo "<p>Ya puedes probar el formulario de asignación de roles.</p>";

} catch (Exception $e) {
    // Solo hacemos rollback si la transacción sigue activa
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Error insertando datos: " . $e->getMessage();
}
?>
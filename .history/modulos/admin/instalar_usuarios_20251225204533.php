<?php
// Ajusta esta ruta a tu archivo de conexión real
require_once 'conexion.php'; 
// O si prefieres poner la conexión aquí directo para probar rápido:
/*
$host = 'localhost';
$db   = 'gestion_obras_1';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
*/

// Verificar que $pdo exista (asumiendo que viene de conexion.php)
if (!isset($pdo)) {
    die("Error: No se encontró la variable de conexión \$pdo. Verifica el require.");
}

try {
    $pdo->beginTransaction();

    // 1. CREAR TABLA ROLES (Si no existe)
    // Asumimos estructura simple: id, nombre
    $sqlRolesTable = "CREATE TABLE IF NOT EXISTS roles (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(50) NOT NULL UNIQUE
    )";
    $pdo->exec($sqlRolesTable);
    echo "✔ Tabla 'roles' verificada.<br>";

    // 2. INSERTAR ROLES
    // Usamos INSERT IGNORE para no fallar si ya existen
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
    echo "✔ Roles insertados (Admin, Presupuesto, Deuda, Obras).<br>";

    // 3. CREAR USUARIOS DE PRUEBA
    // Contraseña para todos: "123456"
    $password = password_hash('123456', PASSWORD_DEFAULT);
    
    // Array de usuarios: usuario => [rol_id, nombre_real, email]
    $usuariosPrueba = [
        'admin' => [1, 'Administrador General', 'admin@prueba.com'],
        'pepe_presupuesto' => [2, 'Pepe Finanzas', 'pepe@prueba.com'],
        'dani_deuda' => [3, 'Daniel Deuda', 'dani@prueba.com'],
        'oscar_obras' => [4, 'Oscar Constructor', 'oscar@prueba.com']
    ];

    $stmtUser = $pdo->prepare("INSERT INTO usuarios (usuario, nombre, email, password_hash, activo) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
    $stmtRel  = $pdo->prepare("INSERT IGNORE INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)");

    foreach ($usuariosPrueba as $username => $datos) {
        $rolId = $datos[0];
        $nombre = $datos[1];
        $email = $datos[2];

        // Insertar Usuario
        $stmtUser->execute([$username, $nombre, $email, $password]);
        
        // Obtener ID del usuario recién insertado o actualizado
        // NOTA: Si ya existía, necesitamos buscar su ID.
        $stmtId = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmtId->execute([$username]);
        $userId = $stmtId->fetchColumn();

        // Asignar Rol en la tabla intermedia
        if ($userId) {
            $stmtRel->execute([$userId, $rolId]);
            echo "✔ Usuario creado/actualizado: <strong>$username</strong> (Rol ID: $rolId)<br>";
        }
    }

    $pdo->commit();
    echo "<hr><h3 style='color:green'>¡Listo! Datos de prueba cargados.</h3>";
    echo "<p>Contraseña para todos: <strong>123456</strong></p>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage();
}
?>
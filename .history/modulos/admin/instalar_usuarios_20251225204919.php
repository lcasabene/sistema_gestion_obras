<?php
// ==========================================
// CONFIGURACIÓN DE LA BASE DE DATOS
// ==========================================
// Edita estos 4 valores con los datos reales de tu servidor (ByetHost o Localhost)
$host = 'localhost';          // Suele ser localhost
$db   = 'gestion_obra_1';     // Puse este nombre porque vi que lo usaste antes, ¡confírmalo!
$user = 'root';               // Tu usuario de BD (en XAMPP suele ser root)
$pass = '';                   // Tu contraseña de BD (en XAMPP suele estar vacía)
$charset = 'utf8mb4';

// ==========================================
// INTENTO DE CONEXIÓN
// ==========================================
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Creamos la variable $pdo aquí mismo
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("❌ Error fatal de conexión: " . $e->getMessage() . "<br>Revisa los datos de \$user, \$pass y \$db al inicio del archivo.");
}

// ==========================================
// LÓGICA DE INSTALACIÓN
// ==========================================
try {
    $pdo->beginTransaction();

    // 1. CREAR TABLA ROLES (Si no existe)
    $sqlRolesTable = "CREATE TABLE IF NOT EXISTS roles (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(50) NOT NULL UNIQUE
    )";
    $pdo->exec($sqlRolesTable);
    echo "✔ Tabla 'roles' verificada.<br>";

    // 2. INSERTAR ROLES
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

    // 3. CREAR USUARIOS DE PRUEBA
    // La contraseña será "123456" para todos
    $password = password_hash('123456', PASSWORD_DEFAULT);
    
    // Lista: usuario => [rol_id, nombre_real, email]
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

        // Insertar/Actualizar Usuario
        $stmtUser->execute([$username, $nombre, $email, $password]);
        
        // Obtener ID
        $stmtId->execute([$username]);
        $userId = $stmtId->fetchColumn();

        // Asignar Rol
        if ($userId) {
            $stmtRel->execute([$userId, $rolId]);
            echo "✔ Usuario: <strong>$username</strong> asignado a Rol ID: $rolId<br>";
        }
    }

    $pdo->commit();
    echo "<hr><h3 style='color:green'>¡ÉXITO! Usuarios creados.</h3>";
    echo "<p>Puedes entrar con usuario: <strong>admin</strong> y contraseña: <strong>123456</strong></p>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error en la instalación: " . $e->getMessage();
}
?>
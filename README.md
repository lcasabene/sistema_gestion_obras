# Sistema de Gestión de Obras (PHP + MySQL + Bootstrap + PDO)

## Requisitos
- PHP 8.x (recomendado)
- MySQL/MariaDB
- Apache (XAMPP/LAMP/WAMP)

## 1) Crear base de datos
Recomendado:
```sql
CREATE DATABASE gestion_obras CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## 2) Importar SQL
1. Importar `sql/01_schema.sql`
2. Importar `sql/02_seed.sql` (crea catálogos mínimos + usuario admin)

Usuario inicial:
- Usuario: `admin`
- Clave: `Admin123!`  (cambiarla luego)

## 3) Configurar conexión
Editar:
- `config/database.php`

## 4) Arranque
- Abrir: `public/index.php`

## Seguridad
- `auth/middleware.php` protege pantallas.
- `.htaccess` bloquea acceso directo a `/config` y `/uploads` (si Apache lo permite).

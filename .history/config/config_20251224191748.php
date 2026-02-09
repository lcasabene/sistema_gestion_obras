<?php
// Configuración general
define('APP_NAME', 'Gestión de Obras');
define('APP_VERSION', 'v1');

// Base URL (si lo usás en subcarpeta, ajustar)
// Ejemplo: http://localhost/gestion_obras/public/
define('BASE_URL', '/sistema_gestion_obras/');

// Uploads
define('UPLOADS_DIR', dirname(__DIR__) . '/uploads');
define('UPLOADS_FACTURAS', UPLOADS_DIR . '/facturas');
define('UPLOADS_DOCUMENTOS', UPLOADS_DIR . '/documentos');

// Entorno
define('APP_ENV', 'dev'); // dev | prod

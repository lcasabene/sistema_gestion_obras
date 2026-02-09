<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualización de Empresas</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; display: flex; justify-content: center; padding: 50px; }
        .card { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h2 { color: #333; margin-top: 0; }
        input[type="file"] { margin: 20px 0; display: block; }
        button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .info { font-size: 0.9rem; color: #666; background: #e9ecef; padding: 10px; border-left: 4px solid #007bff; }
    </style>
</head>
<body>

<div class="card">
    <h2>Subir Listado de Proveedores</h2>
    <p class="info">El archivo debe ser un <strong>CSV</strong> delimitado por punto y coma (;) y contener las columnas: Razón Social, Titular y CUIT.</p>
    
    <form action="procesar_upload.php" method="post" enctype="multipart/form-data">
        <label>Seleccione el archivo CSV:</label>
        <input type="file" name="archivo_csv" accept=".csv" required>
        <button type="submit">Procesar e Importar</button>
    </form>
</div>

</body>
</html>
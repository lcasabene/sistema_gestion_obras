<?php
// obras_mapa.php - Visor de Mapa Pantalla Completa
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT denominacion, latitud, longitud, geojson_data FROM obras WHERE id = ?");
$stmt->execute([$id]);
$obra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$obra) die("Obra no encontrada");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mapa de Obra: <?= htmlspecialchars($obra['denominacion']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body, html { margin: 0; padding: 0; height: 100%; width: 100%; font-family: sans-serif; }
        #map { height: 100vh; width: 100%; }
        .info-box {
            position: absolute; top: 10px; left: 50px; z-index: 1000;
            background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            max-width: 300px;
        }
        .legend-item { margin-bottom: 5px; display: flex; align-items: center; }
        .color-box { width: 20px; height: 5px; margin-right: 10px; display: inline-block; }
    </style>
</head>
<body>

<div class="info-box">
    <h3 style="margin: 0 0 10px 0; font-size: 16px;"><?= htmlspecialchars($obra['denominacion']) ?></h3>
    <div class="legend-item"><span class="color-box" style="background:green"></span> Ejecutado</div>
    <div class="legend-item"><span class="color-box" style="background:orange"></span> En Ejecución</div>
    <div class="legend-item"><span class="color-box" style="background:red"></span> Pendiente</div>
    <div style="margin-top: 10px;">
        <a href="#" onclick="window.close()" style="text-decoration:none; font-size:12px;">Cerrar</a>
    </div>
</div>

<div id="map"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    var lat = <?= $obra['latitud'] ?: -38.9516 ?>;
    var lng = <?= $obra['longitud'] ?: -68.0591 ?>;
    var rawGeoJSON = <?= json_encode($obra['geojson_data']) ?>;

    var map = L.map('map').setView([lat, lng], 13);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    if(lat && lng && lat != -38.9516) {
        L.marker([lat, lng]).addTo(map).bindPopup("Ubicación Principal");
    }

    function estilo(feature) {
        var c = "red"; // Pendiente por defecto
        if (feature.properties && feature.properties.estado) {
            switch(feature.properties.estado) {
                case 'ejecutado': c = "green"; break;
                case 'en_ejecucion': c = "orange"; break;
                case 'pendiente': c = "red"; break;
            }
        }
        return { color: c, weight: 5, opacity: 0.8 };
    }

    if (rawGeoJSON) {
        try {
            var data = JSON.parse(rawGeoJSON);
            var layer = L.geoJSON(data, {
                style: estilo,
                onEachFeature: function(f, l) {
                    if(f.properties) {
                        var txt = "";
                        if(f.properties.nombre) txt += "<b>"+f.properties.nombre+"</b><br>";
                        if(f.properties.estado) txt += "Estado: "+f.properties.estado;
                        l.bindPopup(txt);
                    }
                }
            }).addTo(map);
            map.fitBounds(layer.getBounds());
        } catch(e) { console.error(e); }
    }
</script>
</body>
</html>
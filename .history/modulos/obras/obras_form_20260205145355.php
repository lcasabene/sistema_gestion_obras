<?php
// obras_form.php - Versión con pestaña de Georeferenciación Independiente
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$obra = [
    'id' => 0, 'empresa_id' => 0, 'organismo_financiador_id' => 0, 'codigo_interno' => '', 
    'expediente' => '', 'denominacion' => '', 'tipo_obra_id' => 0, 'estado_obra_id' => 0, 
    'ubicacion' => '', 'region' => '', 'organismo_requirente' => '', 'titularidad_terreno' => '', 
    'superficie_desarrollo' => '', 'caracteristicas_obra' => '', 'memoria_objetivo' => '', 
    'fecha_inicio' => '', 'fecha_fin_prevista' => '', 'plazo_dias_original' => '', 
    'moneda' => 'ARS', 'periodo_base' => '', 'monto_original' => 0, 'monto_actualizado' => 0,
    'anticipo_pct' => 0, 'anticipo_monto' => 0, 'observaciones' => '',
    'latitud' => '', 'longitud' => '', 'geojson_data' => ''
];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM obras WHERE id = ? AND activo=1");
    $stmt->execute([$id]);
    if ($row = $stmt->fetch()) $obra = array_merge($obra, $row);
}

// Catálogos
$tipos = $pdo->query("SELECT id, nombre FROM tipos_obra WHERE activo=1 ORDER BY nombre")->fetchAll();
$estados = $pdo->query("SELECT id, nombre FROM estados_obra WHERE activo=1 ORDER BY nombre")->fetchAll();
$empresas = $pdo->query("SELECT id, razon_social FROM empresas WHERE activo=1 ORDER BY razon_social ASC")->fetchAll();
$organismos = $pdo->query("SELECT id, nombre_organismo FROM organismos_financiadores WHERE activo=1 ORDER BY nombre_organismo ASC")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    #map { height: 500px; width: 100%; border-radius: 8px; border: 1px solid #ccc; }
</style>

<div class="container-fluid py-3">
    <form action="obras_guardar.php" method="POST" id="formObra">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fw-bold text-primary"><?= $id > 0 ? 'Editar Obra' : 'Nueva Obra' ?></h3>
            <div>
                <a href="obras_listado.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save"></i> Guardar</button>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <ul class="nav nav-tabs card-header-tabs" id="obraTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab_general">1. Datos Generales</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_tecnica">2. Memoria Técnica</a></li>
                    <li class="nav-item"><a class="nav-link text-danger fw-bold" data-bs-toggle="tab" href="#tab_mapa">3. Georeferenciación</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_presupuesto">4. Presupuesto</a></li>
                </ul>
            </div>
            
            <div class="card-body">
                <div class="tab-content">
                    
                    <div class="tab-pane fade show active" id="tab_general">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold">Denominación *</label>
                                <input type="text" name="denominacion" class="form-control" required value="<?= htmlspecialchars($obra['denominacion']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Expediente</label>
                                <input type="text" name="expediente" class="form-control" value="<?= htmlspecialchars($obra['expediente']) ?>">
                            </div>
                            </div>
                    </div>

                    <div class="tab-pane fade" id="tab_tecnica">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Memoria Descriptiva</label>
                                <textarea name="memoria_objetivo" class="form-control" rows="5"><?= htmlspecialchars($obra['memoria_objetivo']) ?></textarea>
                            </div>
                            <div class="col-md-12 mt-3">
                                <label class="form-label fw-bold">Características de la Obra</label>
                                <textarea name="caracteristicas_obra" class="form-control" rows="3"><?= htmlspecialchars($obra['caracteristicas_obra']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab_mapa">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Latitud</label>
                                <input type="text" name="latitud" id="lat" class="form-control" value="<?= $obra['latitud'] ?>" placeholder="-38.9...">
                                
                                <label class="form-label fw-bold mt-2">Longitud</label>
                                <input type="text" name="longitud" id="lng" class="form-control" value="<?= $obra['longitud'] ?>" placeholder="-68.1...">
                                
                                <button type="button" class="btn btn-outline-danger w-100 mt-3" onclick="window.open('https://www.google.com/maps?q=${document.getElementById(\'lat\').value},${document.getElementById(\'lng\').value}', '_blank')">
                                    <i class="bi bi-google"></i> Google Maps
                                </button>
                                
                                <hr>
                                <label class="form-label fw-bold">GeoJSON (Rutas)</label>
                                <textarea name="geojson_data" id="geojson_input" class="form-control font-monospace" style="font-size: 11px;" rows="10" placeholder='{"type":"Feature", ...}'><?= htmlspecialchars($obra['geojson_data']) ?></textarea>
                                <small class="text-muted">Pega aquí el código GeoJSON de la ruta provincial.</small>
                            </div>
                            <div class="col-md-9">
                                <div id="map"></div>
                                <div class="alert alert-info py-1 mt-2 small">
                                    <i class="bi bi-info-circle"></i> Haz clic en el mapa para ubicar el punto principal de la obra.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab_presupuesto">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Monto Actualizado</label>
                                <input type="text" name="monto_actualizado" class="form-control monto" value="<?= number_format($obra['monto_actualizado'], 2, ',', '.') ?>">
                            </div>
                            </div>
                    </div>

                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const latInit = <?= !empty($obra['latitud']) ? $obra['latitud'] : -38.95 ?>;
    const lngInit = <?= !empty($obra['longitud']) ? $obra['longitud'] : -68.06 ?>;
    
    var map = L.map('map').setView([latInit, lngInit], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    var marker = L.marker([latInit, lngInit], {draggable: true}).addTo(map);

    map.on('click', function(e) {
        marker.setLatLng(e.latlng);
        document.getElementById('lat').value = e.latlng.lat.toFixed(8);
        document.getElementById('lng').value = e.latlng.lng.toFixed(8);
    });

    // Cargar GeoJSON si existe
    const gData = document.getElementById('geojson_input').value;
    if(gData.trim() !== "") {
        try {
            L.geoJSON(JSON.parse(gData), { style: { color: "red", weight: 4 } }).addTo(map);
        } catch(e) { console.log("GeoJSON no válido"); }
    }

    // Fix de tamaño al abrir la pestaña
    document.querySelector('a[href="#tab_mapa"]').addEventListener('shown.bs.tab', function () {
        map.invalidateSize();
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
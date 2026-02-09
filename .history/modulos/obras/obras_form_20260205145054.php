<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_login();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Inicialización de todas las variables para evitar errores de "Undefined index"
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

// Carga de catálogos para combos
$tipos = $pdo->query("SELECT id, nombre FROM tipos_obra WHERE activo=1 ORDER BY nombre")->fetchAll();
$estados = $pdo->query("SELECT id, nombre FROM estados_obra WHERE activo=1 ORDER BY nombre")->fetchAll();
$empresas = $pdo->query("SELECT id, razon_social FROM empresas WHERE activo=1 ORDER BY razon_social ASC")->fetchAll();
$organismos = $pdo->query("SELECT id, nombre_organismo FROM organismos_financiadores WHERE activo=1 ORDER BY nombre_organismo ASC")->fetchAll();

include __DIR__ . '/../../public/_header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    #map { height: 450px; width: 100%; border-radius: 12px; border: 2px solid #dee2e6; }
    .nav-tabs .nav-link { font-weight: 600; color: #6c757d; }
    .nav-tabs .nav-link.active { color: #0d6efd; border-bottom: 3px solid #0d6efd; }
    .form-section-title { border-left: 4px solid #0d6efd; padding-left: 10px; margin-bottom: 20px; color: #0d6efd; }
</style>

<div class="container-fluid py-4">
    <form action="obras_guardar.php" method="POST" id="formObra">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold"><i class="bi bi-cone-striped"></i> <?= $id > 0 ? 'Gestión de Obra: ' . htmlspecialchars($obra['denominacion']) : 'Registro de Nueva Obra' ?></h2>
            <div>
                <a href="obras_listado.php" class="btn btn-outline-secondary me-2">Cancelar</a>
                <button type="submit" class="btn btn-primary px-4 shadow"><i class="bi bi-save"></i> Guardar Todo</button>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white">
                <ul class="nav nav-tabs card-header-tabs" id="obraTabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#general">1. Datos Generales</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tecnica">2. Memoria Técnica</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#georef">3. Geo-Localización (Leaflet/GeoJSON)</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#presupuesto">4. Datos Económicos</a></li>
                </ul>
            </div>
            
            <div class="card-body">
                <div class="tab-content" id="obraTabsContent">
                    
                    <div class="tab-pane fade show active" id="general">
                        <h5 class="form-section-title">Identificación y Actores</h5>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold">Denominación de la Obra *</label>
                                <input type="text" name="denominacion" class="form-control form-control-lg" required value="<?= htmlspecialchars($obra['denominacion']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Expediente Administrativo</label>
                                <input type="text" name="expediente" class="form-control" value="<?= htmlspecialchars($obra['expediente']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Empresa Contratista</label>
                                <select name="empresa_id" class="form-select select2">
                                    <option value="">-- Seleccionar Empresa --</option>
                                    <?php foreach($empresas as $e): ?>
                                        <option value="<?= $e['id'] ?>" <?= $obra['empresa_id'] == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['razon_social']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Organismo Financiador</label>
                                <select name="organismo_financiador_id" class="form-select">
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach($organismos as $org): ?>
                                        <option value="<?= $org['id'] ?>" <?= $obra['organismo_financiador_id'] == $org['id'] ? 'selected' : '' ?>><?= htmlspecialchars($org['nombre_organismo']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Estado Actual</label>
                                <select name="estado_obra_id" class="form-select" required>
                                    <?php foreach($estados as $est): ?>
                                        <option value="<?= $est['id'] ?>" <?= $obra['estado_obra_id'] == $est['id'] ? 'selected' : '' ?>><?= htmlspecialchars($est['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tecnica">
                        <h5 class="form-section-title">Detalles Constructivos</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Ubicación / Domicilio</label>
                                <input type="text" name="ubicacion" class="form-control" value="<?= htmlspecialchars($obra['ubicacion']) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Región de Neuquén</label>
                                <select name="region" class="form-select">
                                    <option value="">-- Seleccionar Región --</option>
                                    <?php foreach(['Alto Neuquén', 'Pehuén', 'Lagos del Sur', 'Limay', 'Comarca', 'Confluencia', 'Vaca Muerta'] as $r): ?>
                                        <option value="<?= $r ?>" <?= $obra['region'] == $r ? 'selected' : '' ?>><?= $r ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Superficie (m2 / km)</label>
                                <input type="text" name="superficie_desarrollo" class="form-control" value="<?= htmlspecialchars($obra['superficie_desarrollo']) ?>">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Memoria Descriptiva / Objetivo</label>
                                <textarea name="memoria_objetivo" class="form-control" rows="4"><?= htmlspecialchars($obra['memoria_objetivo']) ?></textarea>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Características de la Obra (Tramos, calzadas, materiales)</label>
                                <textarea name="caracteristicas_obra" class="form-control" rows="3"><?= htmlspecialchars($obra['caracteristicas_obra']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="georef">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded border">
                                    <h6 class="fw-bold mb-3">Coordenadas de Referencia</h6>
                                    <div class="mb-2">
                                        <label class="small fw-bold">Latitud</label>
                                        <input type="text" name="latitud" id="lat" class="form-control" value="<?= $obra['latitud'] ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="small fw-bold">Longitud</label>
                                        <input type="text" name="longitud" id="lng" class="form-control" value="<?= $obra['longitud'] ?>">
                                    </div>
                                    <button type="button" class="btn btn-outline-danger btn-sm w-100 mb-2" onclick="window.open('https://www.google.com/maps?q='+document.getElementById('lat').value+','+document.getElementById('lng').value)">
                                        <i class="bi bi-google"></i> Ver en Google Maps
                                    </button>
                                    <hr>
                                    <label class="small fw-bold">Datos GeoJSON (Para Rutas Provinciales)</label>
                                    <textarea name="geojson_data" id="geojson_input" class="form-control text-xs" rows="6" placeholder='{"type": "Feature", ...}'><?= htmlspecialchars($obra['geojson_data']) ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div id="map"></div>
                                <div class="mt-2 small text-muted">
                                    <i class="bi bi-info-circle"></i> Haz clic en el mapa para situar el punto de inicio o el edificio. El GeoJSON dibujará automáticamente el trazado de la ruta si los datos son válidos.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="presupuesto">
                        <h5 class="form-section-title">Valores Contractuales</h5>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Monto Original</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" name="monto_original" class="form-control monto" value="<?= number_format($obra['monto_original'], 2, ',', '.') ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold text-primary">Monto Actualizado</label>
                                <div class="input-group border border-primary rounded">
                                    <span class="input-group-text bg-primary text-white">$</span>
                                    <input type="text" name="monto_actualizado" class="form-control monto fw-bold" value="<?= number_format($obra['monto_actualizado'], 2, ',', '.') ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Fecha Inicio Real</label>
                                <input type="date" name="fecha_inicio" class="form-control" value="<?= $obra['fecha_inicio'] ?>">
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
    // 1. Inicializar Mapa
    const latDefault = <?= !empty($obra['latitud']) ? $obra['latitud'] : -38.95 ?>;
    const lngDefault = <?= !empty($obra['longitud']) ? $obra['longitud'] : -68.06 ?>;
    
    var map = L.map('map').setView([latDefault, lngDefault], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);

    var marker = L.marker([latDefault, lngDefault], {draggable: true}).addTo(map);

    // 2. Eventos de Mapa
    map.on('click', function(e) {
        marker.setLatLng(e.latlng);
        document.getElementById('lat').value = e.latlng.lat.toFixed(8);
        document.getElementById('lng').value = e.latlng.lng.toFixed(8);
    });

    marker.on('dragend', function() {
        const pos = marker.getLatLng();
        document.getElementById('lat').value = pos.lat.toFixed(8);
        document.getElementById('lng').value = pos.lng.toFixed(8);
    });

    // 3. Cargar GeoJSON de Rutas
    const geoJsonRaw = document.getElementById('geojson_input').value;
    if(geoJsonRaw.trim() !== "") {
        try {
            const geoData = JSON.parse(geoJsonRaw);
            L.geoJSON(geoData, { style: { color: "#ff4444", weight: 5, opacity: 0.7 } }).addTo(map);
        } catch(e) { console.error("GeoJSON inválido"); }
    }

    // Fix para Leaflet al cambiar de pestaña
    document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', () => { map.invalidateSize(); });
    });
});
</script>

<?php include __DIR__ . '/../../public/_footer.php'; ?>
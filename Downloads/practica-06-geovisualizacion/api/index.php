<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    echo json_encode(['error' => 'Faltan dependencias. Ejecuta composer install dentro de api/.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require $autoload;
$config = require __DIR__ . '/config.php';
Flight::set('flight.log_errors', true);
Flight::set('flight.handle_errors', true);

final class GeoApiException extends RuntimeException {
    public int $status;
    public function __construct(string $message, int $status = 400) { parent::__construct($message); $this->status = $status; }
}

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    return $pdo;
}

function jsonEndpoint(callable $handler): void {
    try { Flight::json($handler()); }
    catch (GeoApiException $e) { http_response_code($e->status); Flight::json(['error' => $e->getMessage()]); }
    catch (PDOException $e) { error_log('[practica06-db] ' . $e->getMessage()); http_response_code(503); Flight::json(['error' => 'Base de datos no disponible. Revisa el servicio MySQL y database/schema.sql.']); }
    catch (Throwable $e) { error_log('[practica06-api] ' . $e->getMessage()); http_response_code(500); Flight::json(['error' => 'Error interno al procesar la solicitud. Revisa los logs del servidor.']); }
}

function queryCoordinates(): array {
    $latRaw = $_GET['lat'] ?? null; $lonRaw = $_GET['lon'] ?? null;
    if (!is_numeric($latRaw) || !is_numeric($lonRaw)) throw new GeoApiException('Se requieren lat y lon numéricos.');
    $lat = (float)$latRaw; $lon = (float)$lonRaw;
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) throw new GeoApiException('Coordenadas fuera del rango WGS84.');
    return [$lat, $lon];
}

function getJson(string $url, int $timeout = 18): array {
    if (!function_exists('curl_init')) throw new RuntimeException('La extensión PHP cURL no está habilitada.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Practica06-ObservatorioEducativo/1.0'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    // El detalle técnico se escribe en el log local; la respuesta pública sigue siendo genérica.
    if ($raw === false || $curlErrno !== 0) {
        throw new RuntimeException(sprintf('Error cURL %d al consultar proveedor: %s', $curlErrno, $err !== '' ? $err : 'sin detalle'));
    }
    if ($status < 200 || $status >= 300) throw new RuntimeException('El proveedor externo respondió HTTP ' . $status . '.');
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) throw new RuntimeException('El proveedor externo no devolvió JSON válido.');
    return $data;
}

function cachedProvider(string $provider, string $identity, int $ttlSeconds, callable $loader): array {
    $hash = hash('sha256', $provider . '|' . $identity);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT payload_json, expires_at FROM api_cache WHERE cache_key = :k LIMIT 1');
    $stmt->execute(['k' => $hash]);
    $row = $stmt->fetch();
    if ($row && strtotime((string)$row['expires_at']) > time()) {
        $decoded = json_decode((string)$row['payload_json'], true);
        if (is_array($decoded)) { $decoded['_cache'] = 'hit'; return $decoded; }
    }
    try {
        $payload = $loader();
        if (!is_array($payload)) $payload = ['available' => false, 'message' => 'Respuesta vacía del proveedor.'];
        $ttl = !empty($payload['available']) ? $ttlSeconds : 600;
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $upsert = $pdo->prepare('INSERT INTO api_cache (cache_key,provider,payload_json,fetched_at,expires_at) VALUES (:k,:p,:j,CURRENT_TIMESTAMP,:e) ON DUPLICATE KEY UPDATE provider=VALUES(provider),payload_json=VALUES(payload_json),fetched_at=CURRENT_TIMESTAMP,expires_at=VALUES(expires_at)');
        $upsert->execute(['k'=>$hash,'p'=>$provider,'j'=>$encoded,'e'=>$expires]);
        $payload['_cache'] = 'miss';
        return $payload;
    } catch (Throwable $e) {
        error_log('[practica06-provider:' . $provider . '] ' . $e->getMessage());
        $payload = ['available' => false, 'provider' => $provider, 'message' => providerUnavailableMessage($provider)];
        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $upsert = $pdo->prepare('INSERT INTO api_cache (cache_key,provider,payload_json,fetched_at,expires_at) VALUES (:k,:p,:j,CURRENT_TIMESTAMP,DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)) ON DUPLICATE KEY UPDATE provider=VALUES(provider),payload_json=VALUES(payload_json),fetched_at=CURRENT_TIMESTAMP,expires_at=VALUES(expires_at)');
            $upsert->execute(['k'=>$hash,'p'=>$provider,'j'=>$encoded]);
        } catch (Throwable $ignored) {}
        $payload['_cache'] = 'miss-error';
        return $payload;
    }
}

function providerUnavailableMessage(string $provider): string {
    return match ($provider) {
        'soilgrids' => 'SoilGrids no respondió. El servicio REST puede estar temporalmente fuera de servicio; no se inventan valores de suelo.',
        'gbif' => 'GBIF no respondió a la consulta. Intenta nuevamente más tarde; se muestra la disponibilidad de datos por separado.',
        'nasa-power' => 'NASA POWER no respondió a la consulta histórica. Los indicadores anuales quedan sin dato.',
        default => 'La fuente externa no está disponible en este momento.'
    };
}

function speciesById(int $id): array {
    $stmt = db()->prepare('SELECT id,nombre,nombre_cientifico,categoria,temp_min,temp_max,temp_opt,agua_opt,luz_opt,nutrientes_opt,co2_opt,precip_opt_mm_year,solar_opt_kwh_m2_day,ph_opt,soil_pref,color_hex,descripcion FROM especies WHERE id=:id AND activa=1 LIMIT 1');
    $stmt->execute(['id'=>$id]); $row = $stmt->fetch();
    if (!$row) throw new GeoApiException('La especie no existe o está inactiva.', 404);
    return $row;
}

function openMeteoWeather(float $lat, float $lon): array {
    $rlat = round($lat, 2); $rlon = round($lon, 2);
    return cachedProvider('open-meteo', sprintf('forecast:%.2f:%.2f',$rlat,$rlon), 1800, function () use ($rlat,$rlon) {
        $params = [
            'latitude'=>$rlat, 'longitude'=>$rlon,
            'current'=>'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m',
            'daily'=>'temperature_2m_mean,precipitation_sum,shortwave_radiation_sum,et0_fao_evapotranspiration',
            'forecast_days'=>7, 'timezone'=>'auto'
        ];
        $j = getJson('https://api.open-meteo.com/v1/forecast?' . http_build_query($params));
        if (!isset($j['current'])) throw new RuntimeException('Open-Meteo no devolvió current.');
        $daily = $j['daily'] ?? [];
        $temps = array_values(array_filter($daily['temperature_2m_mean'] ?? [], 'is_numeric'));
        $rain = array_values(array_filter($daily['precipitation_sum'] ?? [], 'is_numeric'));
        $solar = array_values(array_filter($daily['shortwave_radiation_sum'] ?? [], 'is_numeric'));
        $et0 = array_values(array_filter($daily['et0_fao_evapotranspiration'] ?? [], 'is_numeric'));
        return [
            'available'=>true, 'provider'=>'Open-Meteo Forecast API', 'latitude'=>$j['latitude'] ?? $rlat, 'longitude'=>$j['longitude'] ?? $rlon,
            'timezone'=>$j['timezone'] ?? 'UTC',
            'current'=>[
                'time'=>$j['current']['time'] ?? null,
                'temperature_c'=>$j['current']['temperature_2m'] ?? null,
                'relative_humidity_pct'=>$j['current']['relative_humidity_2m'] ?? null,
                'precipitation_mm'=>$j['current']['precipitation'] ?? null,
                'wind_speed_kmh'=>$j['current']['wind_speed_10m'] ?? null,
            ],
            'forecast_7d'=>[
                'days'=>$daily['time'] ?? [], 'temperature_mean_c'=>$temps,
                'precipitation_sum_mm'=>count($rain) ? array_sum($rain) : null,
                'solar_radiation_sum_mj_m2'=>count($solar) ? array_sum($solar) : null,
                'et0_sum_mm'=>count($et0) ? array_sum($et0) : null,
            ],
            'units'=>$j['current_units'] ?? [],
            'note'=>'Pronóstico de corto plazo, no sustituye una serie climática normal de décadas.'
        ];
    });
}

function nasaPowerClimate(float $lat, float $lon): array {
    $rlat = round($lat, 1); $rlon = round($lon, 1);
    $year = (int)date('Y') - 1; $start = sprintf('%04d0101',$year); $end = sprintf('%04d1231',$year);
    return cachedProvider('nasa-power', sprintf('annual:%d:%.1f:%.1f',$year,$rlat,$rlon), 86400 * 30, function () use ($lat,$lon,$rlat,$rlon,$start,$end,$year) {
        $params = ['parameters'=>'T2M,PRECTOTCORR,ALLSKY_SFC_SW_DWN','community'=>'AG','longitude'=>$rlon,'latitude'=>$rlat,'start'=>$start,'end'=>$end,'format'=>'JSON'];
        $j = getJson('https://power.larc.nasa.gov/api/temporal/daily/point?' . http_build_query($params), 25);
        $all = $j['properties']['parameter'] ?? null;
        if (!is_array($all)) throw new RuntimeException('NASA POWER no devolvió properties.parameter.');
        $getSeries = static function (array $data, string $key): array {
            $series = $data[$key] ?? [];
            if (isset($series['data']) && is_array($series['data'])) $series = $series['data'];
            if (!is_array($series)) return [];
            $values = [];
            foreach ($series as $date=>$value) if (preg_match('/^\d{8}$/',(string)$date) && is_numeric($value) && (float)$value > -900) $values[] = (float)$value;
            return $values;
        };
        $temps = $getSeries($all,'T2M'); $rain = $getSeries($all,'PRECTOTCORR'); $solar = $getSeries($all,'ALLSKY_SFC_SW_DWN');
        $mean = static fn(array $a): ?float => count($a) ? array_sum($a)/count($a) : null;
        if (!$temps && !$rain && !$solar) throw new RuntimeException('NASA POWER no devolvió valores válidos.');
        return [
            'available'=>true,'provider'=>'NASA POWER Daily API','period'=>['start'=>$start,'end'=>$end,'year'=>$year],
            'annual_mean_temperature_c'=>$mean($temps),
            'annual_precipitation_mm'=>count($rain) ? array_sum($rain) : null,
            'mean_solar_radiation_kwh_m2_day'=>$mean($solar),
            'days_with_temperature'=>count($temps), 'days_with_precipitation'=>count($rain), 'days_with_radiation'=>count($solar),
            'note'=>'Resumen calculado desde los datos diarios del año calendario anterior; los productos NASA POWER pueden tener retraso de publicación.'
        ];
    });
}

function climateAt(float $lat, float $lon): array {
    return [
        'open_meteo'=>openMeteoWeather($lat,$lon),
        'nasa_power'=>nasaPowerClimate($lat,$lon),
        'units'=>['temperature'=>'°C','precipitation'=>'mm','solar_radiation'=>'kWh/m²/day']
    ];
}

function soilAt(float $lat, float $lon): array {
    $rlat = round($lat, 3); $rlon = round($lon, 3);
    return cachedProvider('soilgrids', sprintf('surface:%.3f:%.3f',$rlat,$rlon), 86400 * 30, function () use ($rlat,$rlon) {
        $params = ['lon'=>$rlon,'lat'=>$rlat,'depth'=>'0-5cm','value'=>'mean'];
        $url = 'https://rest.isric.org/soilgrids/v2.0/properties/query?' . http_build_query($params) . '&property=phh2o&property=soc&property=clay&property=sand';
        $j = getJson($url, 8);
        $layers = $j['properties']['layers'] ?? $j['layers'] ?? null;
        if (!is_array($layers)) throw new RuntimeException('Respuesta de SoilGrids sin capas de propiedades.');
        $out = [];
        foreach ($layers as $layer) {
            $name = (string)($layer['name'] ?? '');
            $dFactor = (float)($layer['unit_measure']['d_factor'] ?? 1);
            $depths = $layer['depths'] ?? [];
            $raw = $depths[0]['values']['mean'] ?? null;
            $out[$name] = ['mean_raw'=>$raw,'mean'=>is_numeric($raw) ? (float)$raw / max(1,$dFactor) : null,'unit'=>$layer['unit_measure']['target_units'] ?? $layer['unit_measure']['mapped_units'] ?? null,'depth'=>$depths[0]['label'] ?? '0-5cm'];
        }
        $clay = $out['clay']['mean'] ?? null; $sand = $out['sand']['mean'] ?? null; $ph = $out['phh2o']['mean'] ?? null;
        $texture = 'No determinada';
        if (is_numeric($clay) && is_numeric($sand)) {
            if ($clay >= 400) $texture = 'Probablemente arcilloso';
            elseif ($sand >= 700) $texture = 'Probablemente arenoso';
            else $texture = 'Textura intermedia (proxy clay/sand)';
        }
        return ['available'=>true,'provider'=>'ISRIC SoilGrids v2.0','depth'=>'0-5cm','properties'=>$out,'pH'=>$ph,'clay_g_kg'=>$clay,'sand_g_kg'=>$sand,'texture_proxy'=>$texture,'note'=>'Las clases de textura aquí son aproximaciones educativas a partir de arcilla y arena; no reemplazan un análisis de laboratorio.'];
    });
}

function gbifTaxon(string $scientificName): array {
    return cachedProvider('gbif-match', 'name:' . mb_strtolower(trim($scientificName)), 86400 * 30, function () use ($scientificName) {
        $j = getJson('https://api.gbif.org/v1/species/match?' . http_build_query(['name'=>$scientificName,'strict'=>'true']), 15);
        $key = $j['usageKey'] ?? null;
        if (!$key) return ['available'=>false,'provider'=>'GBIF Species Match','message'=>'GBIF no encontró una clave taxonómica para esta especie.','scientific_name'=>$scientificName];
        return ['available'=>true,'provider'=>'GBIF Species Match','taxon_key'=>(int)$key,'scientific_name'=>$j['scientificName'] ?? $scientificName,'match_type'=>$j['matchType'] ?? null,'rank'=>$j['rank'] ?? null];
    });
}

function gbifGlobalOccurrences(array $species, int $limit = 100): array {
    $limit = max(1,min(300,$limit));
    $taxon = gbifTaxon((string)$species['nombre_cientifico']);
    if (empty($taxon['available'])) return ['available'=>false,'provider'=>'GBIF Occurrence API','count'=>0,'points'=>[],'message'=>$taxon['message'] ?? providerUnavailableMessage('gbif')];
    $key = (int)$taxon['taxon_key'];
    return cachedProvider('gbif', 'global:' . $key . ':limit:' . $limit, 86400 * 7, function () use ($key,$limit,$taxon) {
        $params = ['taxonKey'=>$key,'hasCoordinate'=>'true','limit'=>$limit,'offset'=>0];
        $j = getJson('https://api.gbif.org/v1/occurrence/search?' . http_build_query($params), 20);
        $points = [];
        foreach (($j['results'] ?? []) as $r) {
            if (!is_numeric($r['decimalLatitude'] ?? null) || !is_numeric($r['decimalLongitude'] ?? null)) continue;
            $points[] = ['lat'=>(float)$r['decimalLatitude'],'lon'=>(float)$r['decimalLongitude'],'country'=>$r['countryCode'] ?? null,'date'=>$r['eventDate'] ?? null,'scientific_name'=>$r['scientificName'] ?? $taxon['scientific_name'],'gbif_id'=>$r['gbifID'] ?? null,'basis_of_record'=>$r['basisOfRecord'] ?? null];
        }
        return ['available'=>true,'provider'=>'GBIF Occurrence API','taxon_key'=>$key,'taxon_name'=>$taxon['scientific_name'],'count'=>(int)($j['count'] ?? count($points)),'returned'=>count($points),'points'=>$points,'note'=>'La cantidad total es el recuento de GBIF; el mapa dibuja solo una muestra limitada para no saturar el navegador. Los registros dependen del esfuerzo de muestreo y no prueban ausencia biológica.'];
    });
}

function gbifLocalOccurrences(array $species, float $lat, float $lon): array {
    $taxon = gbifTaxon((string)$species['nombre_cientifico']);
    if (empty($taxon['available'])) return ['available'=>false,'provider'=>'GBIF Occurrence API','count'=>null,'message'=>$taxon['message'] ?? providerUnavailableMessage('gbif')];
    // Ventana rectangular de ±2°; cerca de los polos/meridianos se acota para producir un WKT válido.
    $south = max(-89.0, $lat - 2.0); $north = min(89.0, $lat + 2.0);
    $west = max(-179.0, $lon - 2.0); $east = min(179.0, $lon + 2.0);
    $geom = sprintf('POLYGON((%.4f %.4f,%.4f %.4f,%.4f %.4f,%.4f %.4f,%.4f %.4f))',$west,$south,$east,$south,$east,$north,$west,$north,$west,$south);
    $id = sprintf('local:%d:%.2f:%.2f',$taxon['taxon_key'],round($lat,2),round($lon,2));
    return cachedProvider('gbif', $id, 86400 * 7, function () use ($taxon,$geom) {
        $j = getJson('https://api.gbif.org/v1/occurrence/search?' . http_build_query(['taxonKey'=>$taxon['taxon_key'],'hasCoordinate'=>'true','geometry'=>$geom,'limit'=>1,'offset'=>0]), 18);
        return ['available'=>true,'provider'=>'GBIF Occurrence API','count'=>(int)($j['count'] ?? 0),'window'=>'caja espacial aproximada de ±2°','taxon_key'=>$taxon['taxon_key'],'note'=>'Un recuento cero significa que la búsqueda no encontró registros en esta ventana; no demuestra que la especie no exista allí.'];
    });
}

function clampScore(float $value): float { return max(0.0,min(100.0,$value)); }
function suitability(array $species, array $climate, array $soil, array $biodiversity): array {
    $components = []; $weighted = 0.0; $weightTotal = 0.0;
    $currentTemp = $climate['open_meteo']['current']['temperature_c'] ?? $climate['nasa_power']['annual_mean_temperature_c'] ?? null;
    if (is_numeric($currentTemp)) {
        $score = clampScore(100 - abs((float)$currentTemp - (float)$species['temp_opt']) * 5.0);
        $components[] = ['key'=>'temperatura','label'=>'Temperatura','score'=>round($score,1),'weight'=>30,'value'=>round((float)$currentTemp,1),'unit'=>'°C','reference'=>(float)$species['temp_opt']]; $weighted += $score*30; $weightTotal+=30;
    }
    $rain = $climate['nasa_power']['annual_precipitation_mm'] ?? null;
    if (is_numeric($rain)) {
        $opt = max(100.0,(float)$species['precip_opt_mm_year']);
        $score = clampScore(100 - abs((float)$rain-$opt)/$opt*75.0);
        $components[]=['key'=>'precipitacion','label'=>'Precipitación anual','score'=>round($score,1),'weight'=>25,'value'=>round((float)$rain,1),'unit'=>'mm/año','reference'=>$opt]; $weighted += $score*25; $weightTotal+=25;
    }
    $solar = $climate['nasa_power']['mean_solar_radiation_kwh_m2_day'] ?? null;
    if (is_numeric($solar)) {
        $opt = max(1.0,(float)$species['solar_opt_kwh_m2_day']);
        $score = clampScore(100 - abs((float)$solar-$opt)/$opt*55.0);
        $components[]=['key'=>'radiacion','label'=>'Radiación solar','score'=>round($score,1),'weight'=>20,'value'=>round((float)$solar,2),'unit'=>'kWh/m²/día','reference'=>$opt]; $weighted += $score*20; $weightTotal+=20;
    }
    $ph = (($soil['available'] ?? false) === true) ? ($soil['pH'] ?? null) : null;
    if (is_numeric($ph)) {
        $score = clampScore(100 - abs((float)$ph-(float)$species['ph_opt'])*25.0);
        $components[]=['key'=>'ph','label'=>'pH del suelo','score'=>round($score,1),'weight'=>15,'value'=>round((float)$ph,2),'unit'=>'pH','reference'=>(float)$species['ph_opt']]; $weighted += $score*15; $weightTotal+=15;
    }
    $count = $biodiversity['count'] ?? null;
    if (($biodiversity['available'] ?? false) && is_numeric($count)) {
        // Proxy de disponibilidad de evidencia, no una medida directa de rendimiento agronómico.
        $score = (int)$count === 0 ? 25.0 : clampScore(45 + 12 * log10((float)$count + 1));
        $components[]=['key'=>'gbif','label'=>'Evidencia GBIF local','score'=>round($score,1),'weight'=>10,'value'=>(int)$count,'unit'=>'registros en ventana ±2°','reference'=>'evidencia observacional']; $weighted += $score*10; $weightTotal+=10;
    }
    $final = $weightTotal > 0 ? round($weighted/$weightTotal,1) : null;
    return ['score'=>$final,'components'=>$components,'weights_used'=>array_sum(array_column($components,'weight')),'method'=>'Media ponderada normalizada sobre las variables disponibles; las variables ausentes se excluyen y se repondera. Es un índice educativo, no una predicción de rendimiento validada.'];
}

function geoRecommendations(array $species, array $climate, array $soil, array $biodiversity): array {
    $recs=[];
    $rain=$climate['nasa_power']['annual_precipitation_mm'] ?? null;
    $temp=$climate['open_meteo']['current']['temperature_c'] ?? null;
    $solar=$climate['nasa_power']['mean_solar_radiation_kwh_m2_day'] ?? null;
    $ph=($soil['available'] ?? false) ? ($soil['pH'] ?? null) : null;
    if (is_numeric($rain) && (float)$rain < 400) $recs[]=['severity'=>'alert','rule'=>'precipitacion_baja','message'=>'NASA POWER estima menos de 400 mm de precipitación anual en el año consultado.','recommendation'=>'Evaluar riego suplementario y confirmar disponibilidad de agua local.','value'=>round((float)$rain,1),'threshold'=>400];
    if (is_numeric($temp) && (float)$temp > 35) $recs[]=['severity'=>'alert','rule'=>'temperatura_alta','message'=>'La temperatura actual supera 35 °C.','recommendation'=>'Evaluar sombra, ventilación y riego con base en la especie y observaciones locales.','value'=>round((float)$temp,1),'threshold'=>35];
    if (is_numeric($ph) && (float)$ph < 5.5) $recs[]=['severity'=>'warning','rule'=>'suelo_acido','message'=>'SoilGrids indica pH superficial por debajo de 5.5.','recommendation'=>'Confirmar el pH con análisis local antes de considerar enmiendas como encalado.','value'=>round((float)$ph,2),'threshold'=>5.5];
    if (is_numeric($solar) && (float)$solar > 7.0) $recs[]=['severity'=>'warning','rule'=>'radiacion_alta','message'=>'La radiación media supera 7 kWh/m²/día (aprox. 25.2 MJ/m²/día).','recommendation'=>'Evaluar estrés radiante y posible sombra parcial según el cultivo.','value'=>round((float)$solar,2),'threshold'=>7.0];
    if (($biodiversity['available'] ?? false) && (int)($biodiversity['count'] ?? 0) === 0) $recs[]=['severity'=>'warning','rule'=>'sin_registros_gbif','message'=>'GBIF no devolvió ocurrencias georreferenciadas de esta especie en la ventana consultada.','recommendation'=>'Interpretar como falta de registros, no como ausencia confirmada; revisar muestreo y fuentes locales.','value'=>0,'threshold'=>1];
    if (!($soil['available'] ?? false)) $recs[]=['severity'=>'warning','rule'=>'suelo_no_disponible','message'=>'No hay datos de SoilGrids para esta consulta.','recommendation'=>'No inferir la clase de suelo; reintentar cuando el servicio esté disponible o aportar un análisis de laboratorio.','value'=>null,'threshold'=>null];
    if (!$recs) $recs[]=['severity'=>'good','rule'=>'sin_alertas_activadas','message'=>'No se activaron las reglas de alerta implementadas con los datos disponibles.','recommendation'=>'Contrastar los indicadores con datos locales y requisitos agronómicos antes de tomar decisiones.','value'=>null,'threshold'=>null];
    return $recs;
}

function performGeoAnalysis(float $lat, float $lon, int $speciesId, ?string $place = null): array {
    $species = speciesById($speciesId);
    $climate = climateAt($lat,$lon);
    $soil = soilAt($lat,$lon);
    $biodiversity = gbifLocalOccurrences($species,$lat,$lon);
    $suit = suitability($species,$climate,$soil,$biodiversity);
    $recs = geoRecommendations($species,$climate,$soil,$biodiversity);
    $risk = $suit['score'] === null ? 'sin_datos' : ($suit['score'] >= 75 ? 'bajo' : ($suit['score'] >= 50 ? 'moderado' : 'alto'));
    return [
        'available'=>true,
        'location'=>['name'=>$place ?: sprintf('%.4f°, %.4f°',$lat,$lon),'lat'=>$lat,'lon'=>$lon],
        'species'=>['id'=>(int)$species['id'],'name'=>$species['nombre'],'scientific_name'=>$species['nombre_cientifico'],'temperature_opt_c'=>(float)$species['temp_opt'],'annual_precipitation_opt_mm'=>(float)$species['precip_opt_mm_year'],'solar_opt_kwh_m2_day'=>(float)$species['solar_opt_kwh_m2_day'],'soil_ph_opt'=>(float)$species['ph_opt'],'soil_preference'=>$species['soil_pref']],
        'climate'=>$climate,'soil'=>$soil,'biodiversity'=>$biodiversity,
        'suitability'=>$suit,'risk_level'=>$risk,'recommendations'=>$recs,
        'source_quality'=>[
            'climate_current'=>!empty($climate['open_meteo']['available']) ? 'available' : 'unavailable',
            'climate_annual'=>!empty($climate['nasa_power']['available']) ? 'available' : 'unavailable',
            'soil'=>!empty($soil['available']) ? 'available' : 'unavailable',
            'biodiversity'=>!empty($biodiversity['available']) ? 'available' : 'unavailable'
        ],
        'generated_at'=>gmdate('c'),
        'disclaimer'=>'Las APIs describen sus propios conjuntos de datos y resoluciones; el índice ponderado es una heurística educativa. Un registro GBIF no demuestra aptitud agrícola y cero registros no demuestra ausencia. SoilGrids puede estar temporalmente fuera de servicio.'
    ];
}

Flight::route('GET /api/health', function () {
    jsonEndpoint(function () {
        db()->query('SELECT 1');
        return ['ok'=>true,'database'=>'connected','service'=>'FlightPHP REST API','application'=>'Observatorio Global de Cultivos','version'=>'1.0'];
    });
});

Flight::route('GET /api/especies', function () {
    jsonEndpoint(function () {
        $rows = db()->query('SELECT id,nombre,nombre_cientifico,categoria,temp_min,temp_max,temp_opt,agua_opt,luz_opt,nutrientes_opt,co2_opt,precip_opt_mm_year,solar_opt_kwh_m2_day,ph_opt,soil_pref,color_hex,descripcion FROM especies WHERE activa=1 ORDER BY nombre')->fetchAll();
        return ['data'=>$rows];
    });
});

Flight::route('GET /api/geo/buscar', function () {
    jsonEndpoint(function () {
        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw new GeoApiException('Introduce un nombre de lugar (1–120 caracteres).');
        return cachedProvider('open-meteo-geocoding','search:'.mb_strtolower($name),86400,function () use ($name) {
            $j = getJson('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query(['name'=>$name,'count'=>8,'language'=>'es','format'=>'json']),12);
            $results=[];
            foreach (($j['results'] ?? []) as $r) $results[]=['name'=>$r['name'] ?? 'Lugar','admin1'=>$r['admin1'] ?? null,'country'=>$r['country'] ?? null,'country_code'=>$r['country_code'] ?? null,'latitude'=>(float)$r['latitude'],'longitude'=>(float)$r['longitude'],'population'=>$r['population'] ?? null,'timezone'=>$r['timezone'] ?? null];
            return ['available'=>true,'provider'=>'Open-Meteo Geocoding API','results'=>$results];
        });
    });
});

Flight::route('GET /api/geo/clima', function () {
    jsonEndpoint(function () { [$lat,$lon]=queryCoordinates(); return ['lat'=>$lat,'lon'=>$lon,'data'=>climateAt($lat,$lon)]; });
});

Flight::route('GET /api/geo/suelo', function () {
    jsonEndpoint(function () { [$lat,$lon]=queryCoordinates(); return ['lat'=>$lat,'lon'=>$lon,'data'=>soilAt($lat,$lon)]; });
});

Flight::route('GET /api/geo/ocurrencias', function () {
    jsonEndpoint(function () {
        $speciesId=(int)($_GET['species_id'] ?? 0); if ($speciesId < 1) throw new GeoApiException('Selecciona species_id.');
        $limit=(int)($_GET['limit'] ?? 100); $species=speciesById($speciesId);
        return gbifGlobalOccurrences($species,$limit);
    });
});

Flight::route('GET /api/geo/analisis', function () {
    jsonEndpoint(function () {
        [$lat,$lon]=queryCoordinates(); $speciesId=(int)($_GET['species_id'] ?? 0);
        if ($speciesId < 1) throw new GeoApiException('Selecciona species_id.');
        $place=trim((string)($_GET['place'] ?? '')); if (mb_strlen($place)>180) $place=mb_substr($place,0,180);
        return performGeoAnalysis($lat,$lon,$speciesId,$place ?: null);
    });
});

Flight::route('POST /api/geo/analisis', function () {
    jsonEndpoint(function () {
        $raw=file_get_contents('php://input'); if (!$raw || strlen($raw)>700000) throw new GeoApiException('Cuerpo JSON vacío o demasiado grande.');
        $body=json_decode($raw,true); if (!is_array($body)) throw new GeoApiException('El cuerpo debe ser JSON válido.');
        $lat=$body['latitude'] ?? null; $lon=$body['longitude'] ?? null; $speciesId=(int)($body['species_id'] ?? 0);
        if (!is_numeric($lat)||!is_numeric($lon)||(float)$lat < -90||(float)$lat > 90||(float)$lon < -180||(float)$lon > 180) throw new GeoApiException('Coordenadas inválidas.');
        $speciesById = speciesById($speciesId);
        $name=trim((string)($body['location_name'] ?? 'Ubicación guardada')); if ($name==='') $name='Ubicación guardada'; $name=mb_substr($name,0,180);
        $analysis=is_array($body['analysis'] ?? null)?$body['analysis']:[];
        $json=static function($v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); };
        $climate=$analysis['climate'] ?? []; $soil=$analysis['soil'] ?? []; $bio=$analysis['biodiversity'] ?? []; $metrics=$analysis['suitability'] ?? []; $recs=$analysis['recommendations'] ?? [];
        $score=$metrics['score'] ?? null; if ($score!==null && (!is_numeric($score)||(float)$score<0||(float)$score>100)) throw new GeoApiException('Índice de idoneidad fuera de rango.');
        $risk=(string)($analysis['risk_level'] ?? 'sin_datos'); if (!in_array($risk,['bajo','moderado','alto','sin_datos'],true)) $risk='sin_datos';
        $stmt=db()->prepare('INSERT INTO geo_analisis (species_id,location_name,latitude,longitude,suitability_score,risk_level,climate_json,soil_json,biodiversity_json,metrics_json,recommendations_json) VALUES (:sid,:name,:lat,:lon,:score,:risk,:climate,:soil,:bio,:metrics,:recs)');
        $stmt->execute(['sid'=>$speciesId,'name'=>$name,'lat'=>(float)$lat,'lon'=>(float)$lon,'score'=>$score===null?null:(float)$score,'risk'=>$risk,'climate'=>$json($climate),'soil'=>$json($soil),'bio'=>$json($bio),'metrics'=>$json($metrics),'recs'=>$json($recs)]);
        return ['ok'=>true,'id'=>(int)db()->lastInsertId(),'message'=>'Análisis geoespacial guardado.','species'=>$speciesById['nombre'],'location_name'=>$name];
    });
});

Flight::route('GET /api/geo/analisis/@id:[0-9]+', function ($id) {
    jsonEndpoint(function () use ($id) {
        $stmt = db()->prepare('SELECT g.*, e.nombre AS species_name, e.nombre_cientifico AS scientific_name FROM geo_analisis g JOIN especies e ON e.id=g.species_id WHERE g.id=:id LIMIT 1');
        $stmt->execute(['id'=>(int)$id]);
        $row = $stmt->fetch();
        if (!$row) throw new GeoApiException('El análisis guardado no existe.', 404);
        $decode = static function ($value): array {
            if (!is_string($value) || $value === '') return [];
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        };
        $climate = $decode($row['climate_json'] ?? null);
        $soil = $decode($row['soil_json'] ?? null);
        $biodiversity = $decode($row['biodiversity_json'] ?? null);
        $suitability = $decode($row['metrics_json'] ?? null);
        $recommendations = $decode($row['recommendations_json'] ?? null);
        return [
            'available'=>true,
            'id'=>(int)$row['id'],
            'species_id'=>(int)$row['species_id'],
            'species'=>[
                'id'=>(int)$row['species_id'],
                'name'=>$row['species_name'],
                'scientific_name'=>$row['scientific_name']
            ],
            'location'=>[
                'name'=>$row['location_name'],
                'lat'=>(float)$row['latitude'],
                'lon'=>(float)$row['longitude']
            ],
            'climate'=>$climate,
            'soil'=>$soil,
            'biodiversity'=>$biodiversity,
            'suitability'=>$suitability,
            'risk_level'=>$row['risk_level'],
            'recommendations'=>$recommendations,
            'source_quality'=>[
                'climate_current'=>!empty($climate['open_meteo']['available']) ? 'available' : 'unavailable',
                'climate_annual'=>!empty($climate['nasa_power']['available']) ? 'available' : 'unavailable',
                'soil'=>!empty($soil['available']) ? 'available' : 'unavailable',
                'biodiversity'=>!empty($biodiversity['available']) ? 'available' : 'unavailable'
            ],
            'saved_at'=>$row['created_at'],
            'snapshot'=>true,
            'disclaimer'=>'Snapshot de un análisis guardado. Los datos representan la consulta almacenada originalmente y no se han actualizado al abrir el historial.'
        ];
    });
});

Flight::route('GET /api/geo/historial', function () {
    jsonEndpoint(function () {
        $limit=max(1,min(100,(int)($_GET['limit'] ?? 30)));
        $stmt=db()->query('SELECT g.id,g.species_id,e.nombre AS species_name,e.nombre_cientifico,g.location_name,g.latitude,g.longitude,g.suitability_score,g.risk_level,g.created_at FROM geo_analisis g JOIN especies e ON e.id=g.species_id ORDER BY g.created_at DESC,g.id DESC LIMIT '.$limit);
        return ['data'=>$stmt->fetchAll()];
    });
});

Flight::route('GET /api/geo/reportes', function () {
    jsonEndpoint(function () {
        $pdo=db();
        $top=$pdo->query('SELECT g.id,g.location_name,g.latitude,g.longitude,g.suitability_score,g.risk_level,g.created_at,e.nombre AS species_name,e.nombre_cientifico FROM geo_analisis g JOIN especies e ON e.id=g.species_id WHERE g.suitability_score IS NOT NULL ORDER BY g.suitability_score DESC,g.created_at DESC LIMIT 5')->fetchAll();
        $bySpecies=$pdo->query('SELECT e.id,e.nombre,e.nombre_cientifico,COUNT(g.id) AS analyses_count,ROUND(AVG(g.suitability_score),1) AS average_score,MAX(g.suitability_score) AS best_score FROM especies e LEFT JOIN geo_analisis g ON g.species_id=e.id GROUP BY e.id,e.nombre,e.nombre_cientifico ORDER BY average_score DESC, analyses_count DESC')->fetchAll();
        return ['top5'=>$top,'by_species'=>$bySpecies,'note'=>'Los reportes se basan en análisis guardados en esta base local. Una base recién creada puede tener resultados vacíos.'];
    });
});

Flight::route('GET /api', function () {
    Flight::json(['service'=>'Observatorio Global de Cultivos API','routes'=>[
        'GET /api/health','GET /api/especies','GET /api/geo/buscar?name=Jutiapa','GET /api/geo/clima?lat=14.29&lon=-89.90',
        'GET /api/geo/suelo?lat=14.29&lon=-89.90','GET /api/geo/ocurrencias?species_id=1&limit=100',
        'GET /api/geo/analisis?lat=14.29&lon=-89.90&species_id=1','GET /api/geo/analisis/{id} (snapshot guardado)','POST /api/geo/analisis','GET /api/geo/historial','GET /api/geo/reportes'
    ]]);
});

Flight::start();

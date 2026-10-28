# Respuestas para Classroom — Práctica 06

> Borradores para revisar después de probar la aplicación en tu computadora. Distingue datos recibidos de los proveedores y métricas heurísticas calculadas por el proyecto.

## 1. ¿Qué APIs públicas integraste y por qué las elegiste? ¿Qué datos aporta cada una?

Integré cuatro fuentes. Open-Meteo entrega temperatura y humedad actuales, precipitación, viento y un pronóstico breve con precipitación, radiación solar y evapotranspiración de referencia. NASA POWER aporta un resumen anual de temperatura media, precipitación acumulada y radiación media diaria para el último año calendario completo consultado. GBIF aporta la clave taxonómica y registros de ocurrencia georreferenciados, útiles para visualizar evidencia de que una especie ha sido observada en determinadas regiones. SoilGrids se consulta para pH, carbono orgánico, arcilla y arena de la capa superficial. La API REST de SoilGrids puede estar temporalmente fuera de servicio; cuando eso ocurre, la aplicación lo informa y no inventa datos.

## 2. ¿Cómo implementaste el proxy PHP para proteger las API keys? Describe el flujo.

El frontend llama rutas propias, por ejemplo `GET /api/geo/clima`. FlightPHP valida los parámetros y decide qué proveedor fijo consultar. PHP realiza la petición HTTPS con cURL, verifica TLS, define timeouts, normaliza los campos y guarda la respuesta en `api_cache` mediante PDO. Finalmente responde JSON al navegador. No existe un endpoint donde el cliente pueda pasar una URL arbitraria. Las fuentes de esta práctica no requieren una clave para las consultas públicas configuradas; si en el futuro se añade una fuente con API key, se guardará como variable de entorno del servidor, nunca en JavaScript.

Flujo: **frontend → fetch → FlightPHP → cURL → API externa → caché MySQL → JSON → frontend**.

## 3. ¿Cómo construiste el globo 3D y cómo se proyectan las coordenadas?

Usé Three.js con `SphereGeometry` y `OrbitControls`, una textura equirectangular local, iluminación, atmósfera visual, estrellas y marcadores. No usé three-globe ni Mapbox. Las coordenadas se reciben como latitud y longitud WGS84 en grados y se transforman a una posición XYZ sobre una esfera usando las funciones `latLonVector` y `vectorLatLon`. La selección del globo se obtiene con `Raycaster`; el punto de intersección se convierte nuevamente en latitud/longitud.

## 4. ¿Cómo georreferenciaste los marcadores y ocurrencias GBIF?

Las ubicaciones A y B se guardan como pares latitud/longitud y se dibujan ligeramente por encima de la superficie. GBIF entrega ocurrencias con `decimalLatitude` y `decimalLongitude`; el cliente transforma esos pares a vectores 3D. Para no saturar el navegador, se muestra una muestra limitada de registros mientras la API informa el total reportado. El recuento regional usa una ventana rectangular aproximada de ±2° alrededor de la coordenada. El esfuerzo de muestreo varía entre regiones: tener muchos registros no equivale a mayor abundancia real, y cero registros no prueba que una especie esté ausente.

## 5. ¿Cómo calculas el índice de idoneidad y qué pesos usa?

Es una media ponderada explicable, con pesos de referencia: temperatura 30 %, precipitación anual 25 %, radiación solar 20 %, pH superficial 15 % y evidencia local de GBIF 10 %. Cada variable se compara con un valor de referencia del catálogo de especies. Las variables no disponibles se excluyen y los pesos restantes se normalizan. Esta puntuación permite comparar las ubicaciones dentro de la práctica, pero no es un modelo agronómico validado ni una predicción de rendimiento.

## 6. ¿Qué reglas geo-agronómicas implementaste?

- Si la precipitación anual de NASA POWER es menor de 400 mm, se recomienda evaluar riego suplementario.
- Si la temperatura actual de Open-Meteo supera 35 °C, se recomienda evaluar estrés térmico, sombra o ventilación según la especie.
- Si el pH superficial disponible de SoilGrids es menor de 5.5, se recomienda verificar la acidez con un análisis local antes de aplicar enmiendas.
- Si la radiación media diaria supera 7 kWh/m²/día (aproximadamente 25 MJ/m²/día), se recomienda evaluar radiación excesiva según el cultivo.
- Si GBIF no devuelve ocurrencias georreferenciadas dentro de la ventana consultada, se recomienda consultar otras fuentes y muestreos locales, sin concluir que la especie no existe allí.

## 7. ¿Cómo manejas la caché y qué expiración se usó?

`api_cache` usa una clave SHA-256 construida a partir del proveedor y la identidad de la consulta, almacena el JSON, la hora de consulta y su vencimiento. Open-Meteo usa 30 minutos; NASA POWER 30 días; SoilGrids 30 días; ocurrencias GBIF 7 días; geocodificación 24 horas. Las respuestas de proveedores fallidos se conservan 10 minutos para evitar repetir peticiones a un servicio que no responde. Las coordenadas se redondean según la resolución práctica de cada fuente para permitir reutilizar resultados cercanos.

## 8. ¿Cómo lanzas un análisis de crecimiento usando datos de ubicación?

El usuario elige una especie y un punto en el globo o busca un lugar. El frontend envía la coordenada y el identificador de especie a `GET /api/geo/analisis`. FlightPHP obtiene clima Open-Meteo, resumen anual NASA POWER, propiedades SoilGrids y ocurrencias locales de GBIF a través de funciones que consultan la caché. El backend normaliza las variables, compara las que están disponibles con los valores de referencia de la especie, calcula el índice y produce recomendaciones. El usuario puede guardar la instantánea, que queda registrada con especie, coordenadas, fuentes consultadas, métricas y recomendaciones en MySQL.

## 9. ¿Cómo funciona el comparador A/B y cómo declara un ganador?

El usuario guarda una ubicación como A y otra como B. El sistema consulta los datos para ambas coordenadas usando la misma especie, y presenta sus puntuaciones, componentes disponibles y un gráfico comparativo. Gana provisionalmente la ubicación con el mayor índice heurístico si ambos índices están disponibles; una diferencia inferior a un punto se trata como empate práctico. Si faltan datos suficientes, la interfaz no declara ganador. Esto compara el índice del prototipo, no un rendimiento agrícola medido.

## 10. ¿Qué dificultades surgieron al integrar fuentes reales con el modelo?

Las fuentes usan diferentes variables, unidades, resoluciones, tiempos de actualización y límites. Por eso el backend normaliza las respuestas, conserva unidades, aplica caché y distingue `available` de `unavailable`. También limita la cantidad de puntos GBIF dibujados. SoilGrids tiene estado beta y puede sufrir interrupciones; el sistema muestra esa falta de disponibilidad en lugar de sustituirla con valores simulados. Otra dificultad es interpretar los datos con cuidado: los registros de biodiversidad reflejan también el esfuerzo de observación.

## 11. ¿Qué valor aporta el mapa 3D frente a un formulario de coordenadas?

El globo permite explorar la distribución geográfica, elegir coordenadas directamente, ver visualmente dos ubicaciones y observar las ocurrencias sobre la misma esfera. Eso ayuda a detectar distancias y patrones espaciales, y convierte la selección de coordenadas en una interacción intuitiva. No sustituye un SIG especializado cuando se requieren análisis cartográficos, límites administrativos o precisión de parcelas.

## 12. ¿Cómo conectarías sensores IoT e imágenes satelitales NDVI/Sentinel-2?

Agregaría un servicio receptor autenticado para sensores (por ejemplo, MQTT con un broker y un worker que escriba lecturas validadas en MySQL), guardando timestamp, ubicación, identificador y unidades. Para Sentinel-2 consumiría un proveedor compatible o el catálogo de Copernicus, almacenaría metadatos y estadísticas del índice NDVI por parcela/fecha, y enlazaría la serie temporal al análisis geoespacial. Se validarían calidad, nubes, calibración y sincronización horaria. Esta integración es una propuesta futura, no una función incluida en este prototipo.

## 13. ¿Cómo escalarías a miles de ubicaciones sin saturar las APIs?

Usaría caché distribuida con TTL (por ejemplo Redis), cuotas por proveedor, peticiones por lotes donde estén permitidas, trabajos asíncronos con una cola y workers limitados, reintentos con backoff exponencial, límites de concurrencia, métricas de errores y una política de degradación cuando una fuente esté caída. Añadiría índices para proveedor/vencimiento y para especie/fecha/score; usaría migraciones versionadas y paginación. Para GBIF, grandes descargas deberían usar su proceso asíncrono oficial en vez de desplazar offsets muy altos en `/occurrence/search`.

## 14. ¿Qué decisiones puede apoyar un agrónomo o investigador frente a la Práctica 05?

Puede comparar dos sitios con variables ambientales y evidencia de ocurrencias reales, detectar una señal de precipitación escasa o temperatura alta, identificar cuándo faltan datos de suelo y ordenar análisis guardados por su índice de referencia. El resultado sirve para priorizar visitas, muestreos y mediciones. No debe usarse de forma autónoma para recomendar siembra o tratamiento sin validar los datos con estaciones, muestras de suelo y conocimiento local.

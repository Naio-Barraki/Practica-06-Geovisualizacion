# Práctica 06 — Observatorio Global de Cultivos

**Proyecto:** `practica-06-geovisualizacion`  
**Stack:** Three.js + OrbitControls, JavaScript `fetch`/`async-await`, PHP 8.1+, FlightPHP, PDO/MySQL, APIs públicas y caché SQL.

## ¿Qué contiene?

- Globo 3D con textura local equirectangular, órbita con ratón y zoom, selección de coordenadas WGS84 y ubicaciones A/B conectadas por un arco.
- Búsqueda de ciudades con Open-Meteo Geocoding; el nombre de lugar es opcional y las coordenadas se pueden elegir directamente en el globo.
- Proxy PHP mediante rutas FlightPHP para clima, datos históricos NASA POWER, SoilGrids e incidencias taxonómicas/ocurrencias GBIF. No se permite que el cliente indique URL arbitrarias.
- Datos reales de clima actual y pronóstico a 7 días desde Open-Meteo; resumen del año calendario anterior desde NASA POWER; puntos globales de ocurrencia y conteo de una ventana espacial GBIF de ±2° alrededor del punto elegido.
- Cálculo de idoneidad educativo usando las variables disponibles, reglas geo-agronómicas explicables, comparación A/B, ranking e historial persistidos en MySQL.
- Caché de proveedores externos en `api_cache`: Open-Meteo 30 min, NASA POWER 30 días, SoilGrids 30 días, GBIF global/local 7 días, geocodificación 24 h; respuestas de error de proveedor se retienen 10 minutos para evitar repetir llamadas a una fuente caída.

## Requisitos

- PHP 8.1+ con extensiones `curl`, `json`, `pdo`, `pdo_mysql`, `mbstring` y OpenSSL.
- Composer 2.x.
- MySQL 8.x (o MariaDB compatible para pruebas locales).
- Conexión a Internet para Three.js, Chart.js y APIs externas.

## Instalación en Windows con Git Bash y MySQL Server

### 1. Crear la base

Abre MySQL Workbench y ejecuta primero `database/schema.sql` y después `database/seed.sql`. El esquema está diseñado para una base nueva llamada `practica06_geovisualizacion` y recrea las tablas de esa práctica. No lo ejecutes sobre una base con datos que quieras conservar.

Las tablas principales son:

- `especies`: catálogo y óptimos de referencia educativos.
- `plantas`, `simulaciones`, `condiciones_ambientales`, `mediciones`, `decisiones`: tablas reutilizadas de la Práctica 05 para mantener el contexto del laboratorio.
- `api_cache`: respuestas JSON de proveedores, clave del proveedor y vencimiento.
- `geo_analisis`: coordenadas consultadas, especie, índice calculado, riesgo y snapshots JSON de clima, suelo, biodiversidad y recomendaciones.

### 2. Instalar FlightPHP

Desde Git Bash en la raíz del proyecto:

```bash
cd api
composer install
cd ..
```

### 3. Configurar credenciales de MySQL

Las credenciales se leen de variables de entorno; no se guardan en `config.php`. En Git Bash, introduce los valores de tu propia instalación:

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=practica06_geovisualizacion
export DB_USER=root
read -s -p "Contraseña de MySQL: " DB_PASS
printf '\n'
export DB_PASS
```

La contraseña no se muestra al escribir. Estas variables duran solo en esa terminal. No escribas credenciales reales en archivos que subas a GitHub.

### 4. Arrancar el backend y el visor

Comprueba que MySQL esté iniciado y luego ejecuta desde la raíz:

```bash
php -S 127.0.0.1:8000 -t . router.php
```

Abre <http://127.0.0.1:8000/>. Mantén abierta la terminal mientras usas la aplicación.

## Rutas de la API

- `GET /api/health` — estado de la API y conexión a MySQL.
- `GET /api/especies` — catálogo con umbrales/óptimos.
- `GET /api/geo/buscar?name=Jutiapa` — búsqueda de localidades de Open-Meteo.
- `GET /api/geo/clima?lat=14.29&lon=-89.89` — Open-Meteo y resumen anual NASA POWER.
- `GET /api/geo/suelo?lat=14.29&lon=-89.89` — propiedades SoilGrids si el servicio responde.
- `GET /api/geo/ocurrencias?species_id=1&limit=100` — ocurrencias GBIF con coordenadas para el taxón del catálogo.
- `GET /api/geo/analisis?lat=14.29&lon=-89.89&species_id=1` — fuentes cruzadas, métricas y recomendaciones.
- `GET /api/geo/analisis/{id}` — recupera el snapshot JSON de un análisis guardado, incluidos clima, suelo, biodiversidad, idoneidad y recomendaciones, sin volver a consultar los proveedores.
- `POST /api/geo/analisis` — persiste un snapshot de análisis en MySQL.
- `GET /api/geo/historial` — últimos análisis almacenados.
- `GET /api/geo/reportes` — top 5 por idoneidad y resumen por especie.

Prueba en este orden: `/api/health`, `/api/especies`, luego `/api/geo/clima`, `/api/geo/ocurrencias` y finalmente `/api/geo/analisis`.

## Proveedores externos y limitaciones

- **Open-Meteo**: condiciones actuales y previsión a 7 días, sin clave para el uso abierto no comercial. El resumen de pronóstico no debe confundirse con una normal climática de décadas.
- **NASA POWER**: el backend solicita datos diarios del último año calendario completo disponible (año previo), resume temperatura media, precipitación acumulada y radiación media diaria. La publicación puede presentar retraso.
- **GBIF**: resuelve el nombre científico mediante Species Match y recupera hasta 300 ocurrencias georreferenciadas por página; el visor limita el dibujo a una muestra (por defecto 120) para no saturar Three.js. GBIF advierte sobre límites de consulta, por lo que se cachean las respuestas.
- **SoilGrids / ISRIC**: se consulta pH, carbono orgánico, arcilla y arena a 0–5 cm. El servicio REST v2.0 es beta y puede estar temporalmente fuera de servicio. Si no responde, la aplicación muestra “sin dato”; no sustituye valores reales por valores inventados ni calcula una textura simulada.

## Índice de idoneidad

El índice es una heurística explicable, no un modelo agronómico validado. Compara temperatura actual con el óptimo de catálogo (peso 30), precipitación anual NASA POWER con un objetivo referencial (25), radiación solar media (20), pH de SoilGrids cuando esté disponible (15) y evidencia GBIF local (10). Si una fuente/variable falta, se excluye y los pesos disponibles se normalizan. La evidencia GBIF mide presencia de registros, no rendimiento ni abundancia real; cero registros no es prueba de ausencia.

Reglas implementadas: precipitación anual inferior a 400 mm (evaluar riego suplementario), temperatura actual superior a 35 °C (alerta térmica), pH SoilGrids menor a 5.5 (verificar acidez antes de enmiendas), radiación media superior a 7 kWh/m²/día (aprox. 25 MJ/m²/día; evaluar sombra según especie) y cero registros GBIF en una ventana rectangular aproximada de ±2° (revisar otras fuentes, sin concluir ausencia).

## Seguridad y respeto de límites

- Las URL de proveedores están fijas en el servidor; el cliente no puede utilizar el proxy para consultar cualquier dominio.
- Todas las consultas SQL usan PDO y parámetros preparados donde hay valores aportados por el usuario.
- Coordenadas y tamaños de petición se validan. Las respuestas externas tienen timeout, SSL verificado y caché.
- No hay API keys en frontend. Para estas APIs públicas el prototipo no necesita claves; si se agrega una API que las requiera, guardarla solo en variable de entorno del servidor.
- SoilGrids mantiene política de uso justo y el código cachea cada coordenada; no hacer barridos automatizados de miles de puntos.

## Evidencia antes de Classroom

1. Captura el globo 3D con A/B y algunas ocurrencias GBIF.
2. Captura el panel de análisis mostrando las fuentes que respondieron y el índice calculado.
3. Captura la comparación A/B después de analizar ambos puntos.
4. Guarda al menos dos análisis para mostrar el ranking e historial con datos en MySQL.
5. Añade las capturas bajo `Capturas/` y una imagen principal `captura.png` en la raíz. Las capturas no se generan automáticamente.

## Aviso académico

Los datos de clima, suelo y ocurrencias provienen de los proveedores y sus resoluciones correspondientes. El índice de idoneidad y sus recomendaciones son reglas didácticas para la actividad, no sustituyen estudios de campo, análisis de laboratorio del suelo ni criterio agronómico profesional.


## Globo con textura terrestre realista

El visor utiliza una textura equirectangular Blue Marble derivada de imágenes satelitales de NASA Earth Observatory, más una capa de nubes ilustrativa desde un recurso público. Ambas se cargan por HTTPS; la capa de nubes se omite si la fuente remota no está disponible. Se conserva `assets/earth-texture.svg` como textura local de respaldo. La capa de nubes es visual y **no representa nubosidad meteorológica actual**. Crédito de la textura terrestre: NASA Earth Observatory / Blue Marble Next Generation.

Fuentes: [NASA Blue Marble Next Generation](https://science.nasa.gov/earth/earth-observatory/blue-marble-next-generation/base-map/), [textura de tierra 2048×1024 (derivada de NASA)](https://raw.githubusercontent.com/MaxwellLee/physics-lab/main/assets/textures/earth_2048.jpg), [textura de nubes](https://raw.githubusercontent.com/Depal-Jakub/orbital-bloom/main/public/assets/earth-clouds-2048.jpg). Se necesita internet para cargar las texturas remotas y consultar las API.

## Prueba funcional recomendada

1. Abre `/api/health`: debe devolver `ok: true` y `database: connected`.
2. Busca una ciudad con **Buscar lugar**, elige un resultado y pulsa **Analizar ubicación**. Espera que se llenen temperatura, precipitación, radiación, idoneidad, suelo (si responde el proveedor) y reglas.
3. Pulsa **Mostrar ocurrencias GBIF** para dibujar una muestra de registros; el total es el número reportado por GBIF, no abundancia real.
4. Guarda la ubicación como A, busca otra ubicación, guárdala como B y pulsa **Comparar ubicación A vs. B**.
5. Guarda un análisis; confirma que aparezca en historial y ranking tras recargar la página.
6. Haz clic sobre la superficie del globo para elegir coordenadas y arrastra para rotarlo; usa la rueda del ratón para acercarte.

## Abrir desde otra computadora mediante Escritorio remoto

Si te conectas mediante Escritorio remoto de Windows al equipo que aloja el proyecto, inicia los servicios en **ese equipo anfitrión**. Con MySQL Server independiente: inicia el servicio `MySQL84`, abre Git Bash en la raíz del proyecto, define las variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`, y ejecuta `php -S 127.0.0.1:8000 -t . router.php`. Dentro de esa sesión remota abre `http://127.0.0.1:8000`. No es necesario iniciar XAMPP en ese caso.

Si el anfitrión usará XAMPP, recuerda que sus versiones actuales incluyen MariaDB y no el servicio MySQL Server 8.4. Usa solo un servidor de base de datos en el puerto configurado, importa el esquema y el seed en ese motor y ajusta las credenciales; no inicies simultáneamente `MySQL84` y MariaDB en el mismo puerto. Para la configuración actual que usa `router.php`, el método más sencillo es el servidor PHP integrado indicado arriba. Para permitir acceso desde otra PC por navegador en la LAN (sin Escritorio remoto) la configuración y el cortafuegos son diferentes; no expongas el servidor de desarrollo directamente a Internet. XAMPP está orientado al desarrollo y requiere medidas de seguridad antes de permitir acceso remoto.


## Recuperar análisis guardados y reconstruir una comparación A/B

Cada fila del historial es un **snapshot de una ubicación**, no una comparación A/B completa. El botón **Ver** recupera ese snapshot y lo carga en el escenario activo. Los botones **A** y **B** cargan el registro explícitamente en cada lado de la comparación. Para ver una comparación histórica en la gráfica, carga el snapshot que quieras usar para A en A y el segundo snapshot para B en B. Las tarjetas, el mensaje de ganador y la gráfica se actualizan usando los datos guardados, sin volver a consultar Open-Meteo, NASA POWER, SoilGrids o GBIF. No se requiere migración de base de datos porque los snapshots ya se almacenan en las columnas JSON de `geo_analisis`.

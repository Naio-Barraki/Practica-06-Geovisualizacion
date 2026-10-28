<?php
declare(strict_types=1);

// Router para el servidor integrado de PHP en desarrollo.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Todas las rutas /api se resuelven desde FlightPHP.
if ($path === '/api' || str_starts_with($path, '/api/')) {
    // Evita que Flight interprete /api como base de URL.
    $_SERVER['SCRIPT_NAME'] = '/router.php';
    $_SERVER['PHP_SELF'] = '/router.php';
    require __DIR__ . '/api/index.php';
    return true;
}

// Permite servir directamente los archivos estáticos existentes.
$relative = ltrim(str_replace('\\', '/', $path), '/');
$requested = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
if ($path !== '/' && is_file($requested)) {
    return false;
}

// Servir la interfaz principal.
if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 - Recurso no encontrado';
return true;

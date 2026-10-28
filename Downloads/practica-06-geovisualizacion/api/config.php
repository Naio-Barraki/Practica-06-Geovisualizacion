<?php
declare(strict_types=1);

// No guardes credenciales reales en este archivo ni en GitHub.
// La conexión se configura mediante variables de entorno de la terminal.
$getEnv = static function (string $key, string $default): string {
    $value = getenv($key);
    return $value === false ? $default : $value;
};

return [
    'db_host' => $getEnv('DB_HOST', '127.0.0.1'),
    'db_port' => $getEnv('DB_PORT', '3306'),
    'db_name' => $getEnv('DB_NAME', 'practica06_geovisualizacion'),
    'db_user' => $getEnv('DB_USER', 'root'),
    'db_pass' => $getEnv('DB_PASS', ''),
];

<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../src/services/ip_location.php';
require __DIR__ . '/../src/services/weather.php';

// Este endpoint no modifica la sesión. Liberar el bloqueo evita que una
// consulta lenta a proveedores externos demore otras acciones del usuario.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Cache-Control: no-store, max-age=0');

// Obtener ubicación del usuario desde su IP
$location = location_from_ip();

$latitude = (float)($location['latitude'] ?? -34.6037);
$longitude = (float)($location['longitude'] ?? -58.3816);
$result = weather_get_current($latitude, $longitude);
$locationUsesFallback = ($location['source'] ?? 'fallback') === 'fallback';
$locationMessage = $locationUsesFallback
    ? ($location['error'] ?? 'No se pudo detectar la ubicación por IP; se usa Buenos Aires.')
    : 'Ubicación detectada correctamente.';

if ($result['success'] === true) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $result['data'],
        'location' => [
            'city' => $location['city'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'source' => $location['source'] ?? 'fallback',
        ],
        'status' => [
            'geolocation' => [
                'success' => !$locationUsesFallback,
                'message' => $locationMessage,
            ],
            'weather' => [
                'success' => true,
                'message' => $result['message'] ?? 'Clima actualizado correctamente.',
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(502);
echo json_encode([
    'success' => false,
    'error' => $result['error'],
    'location' => [
        'city' => $location['city'],
        'source' => $location['source'] ?? 'fallback',
    ],
    'status' => [
        'geolocation' => [
            'success' => !$locationUsesFallback,
            'message' => $locationMessage,
        ],
        'weather' => [
            'success' => false,
            'message' => $result['error'] ?? 'No se pudo consultar el clima.',
        ],
    ],
], JSON_UNESCAPED_UNICODE);

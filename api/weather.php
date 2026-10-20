<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../src/services/ip_location.php';
require __DIR__ . '/../src/services/weather.php';

// Obtener ubicación del usuario desde su IP
$location = location_from_ip();

$result = weather_get_current($location['latitude'], $location['longitude']);

if ($result['success'] === true) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $result['data'],
        'location' => [
            'city' => $location['city'],
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'source' => $location['source'],
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
        'source' => $location['source'],
    ],
], JSON_UNESCAPED_UNICODE);

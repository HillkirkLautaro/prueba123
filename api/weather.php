<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$latitude = (float)($_GET['lat'] ?? -34.6037);
$longitude = (float)($_GET['lon'] ?? -58.3816);

require __DIR__ . '/../src/services/weather.php';

$result = weather_get_current($latitude, $longitude);

if ($result['success'] === true) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $result['data'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(502);
echo json_encode([
    'success' => false,
    'error' => $result['error'],
], JSON_UNESCAPED_UNICODE);

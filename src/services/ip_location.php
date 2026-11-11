<?php

declare(strict_types=1);

/**
 * Geolocalización por IP usando servicios gratuitos sin API key.
 * Se llama desde el BACKEND (PHP).
 *
 * Degradación elegante (R5): cualquier error conserva coordenadas válidas de
 * fallback. De esa forma el endpoint del clima nunca intenta invocar el
 * servicio meteorológico con índices inexistentes ni rompe la aplicación.
 */
function location_fallback(string $error = ''): array
{
    $location = [
        'success' => $error === '',
        'latitude' => -34.6037,
        'longitude' => -58.3816,
        'city' => 'Buenos Aires',
        'source' => 'fallback',
    ];

    if ($error !== '') {
        $location['error'] = $error;
    }

    return $location;
}

/**
 * Obtiene una respuesta JSON sin asumir que la extensión cURL está instalada.
 */
function ip_location_request(string $url): ?array
{
    $body = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);
            $body = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        }
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $httpCode = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)
            ? (int)$matches[1]
            : 0;
    }

    if (!is_string($body) || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function location_from_ip(?string $ip = null): array
{
    if ($ip === null || trim($ip) === '') {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
    $ip = trim($ip);

    // Las IP locales, privadas o inválidas no se pueden geolocalizar.
    $validIp = filter_var($ip, FILTER_VALIDATE_IP);
    $publicIp = $validIp !== false
        ? filter_var($validIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        : false;
    if ($publicIp === false) {
        // En desarrollo se conserva el comportamiento anterior: el fallback
        // es una ubicación utilizable, no un error del endpoint.
        return location_fallback();
    }

    // Se intenta primero HTTPS y luego ip-api para conservar un proveedor
    // alternativo sin exigir ninguna clave.
    $providers = [
        'https://ipwho.is/' . rawurlencode($ip),
        'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,message,lat,lon,city',
    ];

    foreach ($providers as $index => $url) {
        $data = ip_location_request($url);
        if (!is_array($data)) {
            continue;
        }

        if ($index === 0) {
            $isValidResponse = ($data['success'] ?? false) === true;
            $latitude = $data['latitude'] ?? null;
            $longitude = $data['longitude'] ?? null;
            $city = $data['city'] ?? '';
        } else {
            $isValidResponse = ($data['status'] ?? '') === 'success';
            $latitude = $data['lat'] ?? null;
            $longitude = $data['lon'] ?? null;
            $city = $data['city'] ?? '';
        }

        if ($isValidResponse
            && is_numeric($latitude)
            && is_numeric($longitude)
            && (float)$latitude >= -90
            && (float)$latitude <= 90
            && (float)$longitude >= -180
            && (float)$longitude <= 180) {
            return [
                'success' => true,
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
                'city' => is_string($city) && trim($city) !== '' ? trim($city) : 'Ubicación detectada',
                'source' => 'ip',
            ];
        }
    }

    return location_fallback('Geolocalización por IP no disponible.');
}

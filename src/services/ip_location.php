<?php

declare(strict_types=1);

/**
 * Geolocalización por IP usando ip-api.com (gratuito, sin API key).
 * Se llama desde el BACKEND (PHP).
 *
 * Degradación elegante (R5): si falla, devuelve coordenadas de Buenos Aires
 * como fallback y la app sigue funcionando.
 */
function location_from_ip(?string $ip = null): array
{
    if ($ip === null || $ip === '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }

    // Ignorar IPs locales/privadas en desarrollo
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
        return [
            'success' => true,
            'latitude' => -34.6037,
            'longitude' => -58.3816,
            'city' => 'Buenos Aires',
            'source' => 'fallback',
        ];
    }

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,message,lat,lon,city';

    $ch = curl_init($url);
    if ($ch === false) {
        return ['success' => false, 'error' => 'cURL no disponible.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        return ['success' => false, 'error' => 'Geolocalización por IP no disponible (HTTP ' . $httpCode . ').'];
    }

    $data = json_decode($body, true);
    if ($data === null || !isset($data['status']) || $data['status'] !== 'success') {
        return ['success' => false, 'error' => 'Respuesta de geolocalización inválida.'];
    }

    return [
        'success' => true,
        'latitude' => (float)($data['lat'] ?? -34.6037),
        'longitude' => (float)($data['lon'] ?? -58.3816),
        'city' => $data['city'] ?? 'Desconocida',
        'source' => 'ip',
    ];
}

<?php

declare(strict_types=1);

/**
 * Servicio externo: Open-Meteo (clima).
 * Se llama desde el BACKEND (PHP).
 *
 * Ventaja: no requiere API key, gratuito y sin registro.
 * Configuración: ninguna variable de entorno necesaria.
 *
 * Degradación elegante (R5):
 *   - Si el servicio falla o responde mal, devuelve success => false
 *     y la app principal sigue funcionando sin el clima.
 */
function weather_get_current(float $latitude, float $longitude): array
{
    $url = 'https://api.open-meteo.com/v1/forecast'
        . '?latitude=' . urlencode((string)$latitude)
        . '&longitude=' . urlencode((string)$longitude)
        . '&current=temperature_2m,weather_code&timezone=auto';

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
        return ['success' => false, 'error' => 'Servicio de clima no disponible (HTTP ' . $httpCode . ').'];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Respuesta del clima inválida.'];
    }

    // Compatibilidad con la respuesta antigua de Open-Meteo y la actual.
    if (isset($data['current_weather']) && is_array($data['current_weather'])) {
        return [
            'success' => true,
            'data' => $data['current_weather'],
            'message' => 'Clima actualizado correctamente.',
        ];
    }

    $current = $data['current'] ?? null;
    if (!is_array($current)
        || !is_numeric($current['temperature_2m'] ?? null)
        || !is_numeric($current['weather_code'] ?? null)) {
        return ['success' => false, 'error' => 'Respuesta del clima inválida.'];
    }

    return [
        'success' => true,
        'data' => [
            'temperature' => (float)$current['temperature_2m'],
            'weathercode' => (int)$current['weather_code'],
            'time' => (string)($current['time'] ?? ''),
        ],
        'message' => 'Clima actualizado correctamente.',
    ];
}

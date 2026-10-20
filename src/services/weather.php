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
        . '&current_weather=true&timezone=auto';

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
        return ['success' => false, 'error' => 'Servicio de clima no disponible (HTTP ' . $httpCode . ').'];
    }

    $data = json_decode($body, true);
    if ($data === null || !isset($data['current_weather'])) {
        return ['success' => false, 'error' => 'Respuesta del clima inválida.'];
    }

    return ['success' => true, 'data' => $data['current_weather']];
}

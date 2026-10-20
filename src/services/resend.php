<?php

declare(strict_types=1);

/**
 * Servicio externo: Resend (email transaccional).
 * Se llama desde el BACKEND (PHP).
 *
 * Configuración:
 *   - Variable de entorno: RESEND_API_KEY (pon tu clave en .env, nunca en el código).
 *
 * Degradación elegante (R5):
 *   - Si la clave no está configurada o la petición falla, la función devuelve
 *     un array con success => false y NO interrumpe el flujo principal de la app.
 */
function resend_send_email(string $to, string $subject, string $html, string $from = 'App <onboarding@resend.dev>'): array
{
    $apiKey = getenv('RESEND_API_KEY');
    if ($apiKey === false || trim($apiKey) === '') {
        return ['success' => false, 'error' => 'RESEND_API_KEY no configurada en el entorno.'];
    }

    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    if ($ch === false) {
        return ['success' => false, 'error' => 'cURL no disponible.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'error' => 'Error al enviar email (HTTP ' . $httpCode . '): ' . $curlError,
        ];
    }

    return ['success' => true, 'data' => json_decode($body, true)];
}

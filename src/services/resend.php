<?php

declare(strict_types=1);

/**
 * Servicio externo: Resend (email transaccional).
 * Se llama desde el BACKEND (PHP).
 *
 * Configuración:
 *   - RESEND_API_KEY: clave privada de Resend.
 *   - RESEND_FROM: remitente autorizado, por ejemplo
 *     "Comentarios <notificaciones@tu-dominio.com>".
 *
 * Degradación elegante (R5):
 *   - Si la clave no está configurada o la petición falla, la función devuelve
 *     un array con success => false y NO interrumpe el flujo principal de la app.
 */
function resend_setting(string $name, string $default = ''): string
{
    global $env;

    if (isset($env) && is_array($env) && function_exists('env_value')) {
        return env_value($env, $name, $default);
    }

    $value = getenv($name);
    return $value === false || trim((string)$value) === '' ? $default : trim((string)$value);
}

function resend_send_email(string $to, string $subject, string $html, ?string $from = null): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Destinatario de email inválido.'];
    }

    $apiKey = resend_setting('RESEND_API_KEY');
    if ($apiKey === '') {
        return ['success' => false, 'error' => 'RESEND_API_KEY no configurada en el entorno.'];
    }

    $from = $from ?? resend_setting('RESEND_FROM', 'Comentarios <onboarding@resend.dev>');
    if (trim($from) === '') {
        return ['success' => false, 'error' => 'RESEND_FROM no configurada en el entorno.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'La extensión cURL no está disponible.'];
    }

    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ]);

    if ($payload === false) {
        return ['success' => false, 'error' => 'No se pudo preparar el correo.'];
    }

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
            'Accept: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'error' => 'Error al enviar email (HTTP ' . $httpCode . ')' . ($curlError !== '' ? ': ' . $curlError : ''),
        ];
    }

    return ['success' => true, 'data' => json_decode($body, true)];
}

function resend_send_verification_email(string $to, string $username, string $verificationUrl): array
{
    $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return resend_send_email(
        $to,
        'Verificá tu correo electrónico',
        '<h1>Hola, ' . $safeUsername . '</h1>'
        . '<p>Confirmá tu correo electrónico para activar tu cuenta.</p>'
        . '<p><a href="' . $safeUrl . '">Verificar correo electrónico</a></p>'
        . '<p>Este enlace vence en 24 horas.</p>'
    );
}

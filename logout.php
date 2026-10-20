<?php

declare(strict_types=1);

/**
 * Cierre de sesión robusto e independiente.
 *
 * No requiere config.php para evitar que un error de configuración de la base
 * de datos (credenciales faltantes, HTTPS no configurado, etc.) devuelva 500
 * e impida que el usuario cierre sesión. El logout es una operación segura e
 * idempotente: solo destruye la sesión del usuario actual.
 */

// Iniciar sesión si no está iniciada (necesario para poder destruirla).
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 0) == 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on';

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $isHttps,
    ]);
}

// Vaciar todos los datos de sesión.
$_SESSION = [];

// Eliminar la cookie de sesión del navegador.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'] ?? '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

// Destruir la sesión en el servidor.
session_destroy();
session_write_close();

// Redirigir al login.
header('Location: login.php');
exit;

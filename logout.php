<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

// Si no hay sesión activa, ya está cerrado: redirigir sin error.
if (!is_logged_in()) {
    redirect('login.php');
}

// Logout es una operación idempotente y segura (solo destruye la sesión del usuario).
// Se acepta POST con CSRF válido, pero si falla (sesión expirada, fingerprint cambiado,
// token desincronizado) se cierra la sesión de todas formas para no dejar al usuario
// atrapado con un mensaje de error.
$csrfOk = csrf_valid($_POST['csrf_token'] ?? null);
$originOk = validate_request_origin();

if (!$csrfOk || !$originOk) {
    log_security_event('logout_invalid_request', "csrf=" . ($csrfOk ? 'ok' : 'fail') . ", origin=" . ($originOk ? 'ok' : 'fail'));
}

// Destruir sesión y cookie sin importar si el CSRF/origen fallaron (operación segura/idempotente).
$_SESSION = [];

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

session_destroy();
redirect('login.php');

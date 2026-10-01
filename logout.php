<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

if (!validate_request_origin()) {
    log_security_event('invalid_logout_origin', "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(403);
    exit('Origen no permitido');
}

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

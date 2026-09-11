<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect('index.php');
}

if ($googleClientId === '' || $googleClientSecret === '') {
    http_response_code(503);
    exit('Google OAuth no está configurado.');
}

if ($appEnv === 'production' && $googleRedirectUri === '') {
    http_response_code(503);
    exit('La URL de retorno de Google no está configurada.');
}

$redirectUri = $googleRedirectUri !== '' ? $googleRedirectUri : ((
    $isHttps ? 'https://' : 'http://'
) . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/google_callback.php');

if (!filter_var($redirectUri, FILTER_VALIDATE_URL)) {
    http_response_code(500);
    exit('La URL de retorno de Google no está configurada.');
}

$state = bin2hex(random_bytes(32));
$codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_code_verifier'] = $codeVerifier;
$_SESSION['google_oauth_redirect_uri'] = $redirectUri;
$_SESSION['google_oauth_return_to'] = ($_GET['return'] ?? '') === 'register' ? 'register.php' : 'login.php';

$params = [
    'client_id' => $googleClientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'code_challenge' => $codeChallenge,
    'code_challenge_method' => 'S256',
    'access_type' => 'online',
    'prompt' => 'select_account',
];

redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

function oauth_error(string $message): never
{
    $returnTo = ($_SESSION['google_oauth_return_to'] ?? 'login.php') === 'register.php'
        ? 'register.php'
        : 'login.php';
    unset($_SESSION['google_oauth_return_to']);
    $_SESSION['flash'] = ['type' => 'error', 'message' => $message];
    redirect($returnTo);
}

if (is_logged_in()) {
    redirect('index.php');
}

$state = (string)($_GET['state'] ?? '');
$savedState = (string)($_SESSION['google_oauth_state'] ?? '');
$codeVerifier = (string)($_SESSION['google_oauth_code_verifier'] ?? '');
$redirectUri = (string)($_SESSION['google_oauth_redirect_uri'] ?? $googleRedirectUri);
unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_code_verifier'], $_SESSION['google_oauth_redirect_uri']);

if ($googleClientId === '' || $googleClientSecret === '' || $state === '' || $savedState === '' || !hash_equals($savedState, $state) || $codeVerifier === '') {
    oauth_error('No se pudo validar la solicitud de Google.');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '' || isset($_GET['error'])) {
    oauth_error('La autenticación con Google fue cancelada o no es válida.');
}

$tokenResponse = google_request('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => $googleClientId,
    'client_secret' => $googleClientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
    'code_verifier' => $codeVerifier,
]);

if (($tokenResponse['status'] ?? 0) !== 200 || empty($tokenResponse['json']['access_token'])) {
    error_log('Google OAuth token exchange failed.');
    oauth_error('No se pudo completar la autenticación con Google.');
}

$accessToken = (string)$tokenResponse['json']['access_token'];
$userResponse = google_request('https://openidconnect.googleapis.com/v1/userinfo', [], [
    'Authorization: Bearer ' . $accessToken,
]);
$googleUser = $userResponse['json'];

if (($userResponse['status'] ?? 0) !== 200
    || !is_array($googleUser)
    || !filter_var($googleUser['email'] ?? '', FILTER_VALIDATE_EMAIL)
    || ($googleUser['email_verified'] ?? false) !== true
    || (string)($googleUser['sub'] ?? '') === '') {
    oauth_error('Google no devolvió una identidad verificada.');
}

$email = strtolower(trim((string)$googleUser['email']));
$stmt = $pdo->prepare('SELECT id, username, email FROM usuarios WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user) {
    $baseUsername = preg_replace('/[^A-Za-z0-9_]/', '_', (string)strstr($email, '@', true));
    $baseUsername = trim((string)$baseUsername, '_');
    $baseUsername = substr($baseUsername, 0, 42);
    if (strlen($baseUsername) < 3) {
        $baseUsername = 'google_user';
    }
    $username = $baseUsername;

    for ($suffix = 1; ; $suffix++) {
        $check = $pdo->prepare('SELECT id FROM usuarios WHERE username = :username LIMIT 1');
        $check->execute([':username' => $username]);
        if (!$check->fetch()) {
            break;
        }
        $username = substr($baseUsername, 0, 50 - strlen((string)$suffix) - 1) . '_' . $suffix;
    }

    try {
        $insert = $pdo->prepare('INSERT INTO usuarios (username, email, password, created_at) VALUES (:username, :email, :password, NOW())');
        $insert->execute([
            ':username' => $username,
            ':email' => $email,
            ':password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
        ]);
    } catch (PDOException $exception) {
        error_log('Google OAuth user creation failed [' . $exception->getCode() . ']: ' . $exception->getMessage());
        oauth_error('No se pudo crear la cuenta con Google. Inténtalo de nuevo.');
    }
    $user = ['id' => $pdo->lastInsertId(), 'username' => $username, 'email' => $email];
}

session_regenerate_id(true);
unset($_SESSION['google_oauth_return_to']);
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['email'] = $user['email'];
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Sesión iniciada con Google.'];
redirect('index.php');

function google_request(string $url, array $fields = [], array $headers = []): array
{
    $body = false;
    $status = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST => $fields !== [],
                CURLOPT_POSTFIELDS => $fields !== [] ? http_build_query($fields, '', '&', PHP_QUERY_RFC3986) : null,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        }
    }

    $json = is_string($body) ? json_decode($body, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : []];
}
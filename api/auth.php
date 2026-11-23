<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido. Usa POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function json_error(int $code, string $message): void
{
    json_response($code, ['success' => false, 'error' => $message]);
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Genera un JWT local firmado con HMAC-SHA256 (válido por 7 días)
 * usando el secreto de sesión/app del servidor.
 */
function issue_local_jwt(int $userId, string $username, string $email): string
{
    $secret = defined('APP_SECRET') ? (string)APP_SECRET : hash('sha256', __FILE__ . '|' . (string)($GLOBALS['db_pass'] ?? 'prueba123_secret'));
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $now = time();
    $payload = base64url_encode(json_encode([
        'iss' => 'prueba123.gt.tc',
        'sub' => $userId,
        'username' => $username,
        'email' => $email,
        'iat' => $now,
        'exp' => $now + (7 * 86400),
    ], JSON_UNESCAPED_UNICODE));

    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$signature";
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = strtolower(trim((string)($data['action'] ?? 'login')));
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    // =================================================================
    // REGISTRO: action = "register" (username, email, password)
    // =================================================================
    if ($action === 'register') {
        if (!check_rate_limit('api_register_' . $ip, 5, 600)) {
            json_error(429, 'Demasiados intentos de registro. Esperá unos minutos.');
        }

        $username = trim((string)($data['username'] ?? ''));
        $email = mb_strtolower(trim((string)($data['email'] ?? '')), 'UTF-8');
        $password = (string)($data['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            json_error(422, 'El usuario debe tener entre 3 y 50 caracteres (letras, números o guión bajo).');
        }
        if ($email === '' || mb_strlen($email, 'UTF-8') > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_error(422, 'Ingresá un correo electrónico válido.');
        }
        if (strlen($password) < 8 || strlen($password) > 128) {
            json_error(422, 'La contraseña debe tener entre 8 y 128 caracteres.');
        }

        // Verificar duplicados en username o email
        $dupStmt = $pdo->prepare('SELECT username, email FROM usuarios WHERE username = :u OR email = :e LIMIT 1');
        $dupStmt->execute([':u' => $username, ':e' => $email]);
        $existing = $dupStmt->fetch();
        if ($existing) {
            if (strcasecmp((string)$existing['username'], $username) === 0) {
                json_error(409, 'Ese nombre de usuario ya está en uso.');
            }
            json_error(409, 'Ese correo electrónico ya está registrado.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO usuarios (username, email, password, email_verified_at)
                              VALUES (:u, :e, :p, UTC_TIMESTAMP())');
        $ins->execute([
            ':u' => $username,
            ':e' => $email,
            ':p' => $hash,
        ]);
        $userId = (int)$pdo->lastInsertId();
        $token = issue_local_jwt($userId, $username, $email);

        json_response(201, [
            'success' => true,
            'message' => 'Cuenta creada correctamente.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'username' => $username,
                    'email' => $email,
                    'avatar_url' => 'https://prueba123.gt.tc/uploads/avatars/default.png',
                ],
            ],
        ]);
    }

    // =================================================================
    // LOGIN: action = "login" (login [username o email], password)
    // =================================================================
    if ($action === 'login') {
        if (!check_rate_limit('api_login_' . $ip, 10, 300)) {
            json_error(429, 'Demasiados intentos de inicio de sesión. Esperá unos minutos.');
        }

        $login = trim((string)($data['login'] ?? ($data['username'] ?? ($data['email'] ?? ''))));
        $password = (string)($data['password'] ?? '');

        if ($login === '' || $password === '') {
            json_error(422, 'Completá tu usuario/correo y contraseña.');
        }

        $stmt = $pdo->prepare('SELECT id, username, email, password, avatar FROM usuarios WHERE username = :u OR email = :e LIMIT 1');
        $stmt->execute([':u' => $login, ':e' => $login]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password'])) {
            json_error(401, 'Usuario o contraseña incorrectos.');
        }

        $userId = (int)$user['id'];
        $username = (string)$user['username'];
        $email = (string)$user['email'];
        $token = issue_local_jwt($userId, $username, $email);

        $rawAvatar = (string)($user['avatar'] ?? '');
        $avatarUrl = preg_match('#^https://#i', $rawAvatar)
            ? $rawAvatar
            : ($rawAvatar !== '' ? 'https://prueba123.gt.tc/' . ltrim($rawAvatar, '/') : 'https://prueba123.gt.tc/uploads/avatars/default.png');

        json_response(200, [
            'success' => true,
            'message' => 'Sesión iniciada correctamente.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'username' => $username,
                    'email' => $email,
                    'avatar_url' => $avatarUrl,
                ],
            ],
        ]);
    }

    json_error(400, 'Acción inválida. Usá "login" o "register".');
} catch (Throwable $e) {
    error_log('API auth error: ' . $e->getMessage());
    json_error(500, 'Error interno en autenticación.');
}

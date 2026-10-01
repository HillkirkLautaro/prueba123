<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
}
error_reporting(E_ALL);
date_default_timezone_set('UTC');

if (!function_exists('load_env_file')) {
    function load_env_file(string $path): array
    {
        $values = [];
        if (!is_readable($path)) {
            return $values;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            $hasDoubleQuotes = strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"';
            $hasSingleQuotes = strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'";
            if ($value !== '' && ($hasDoubleQuotes || $hasSingleQuotes)) {
                $value = substr($value, 1, -1);
            }

            if ($name !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}

$env = load_env_file(__DIR__ . '/.env');

if (!function_exists('env_value')) {
    function env_value(array $env, string $name, string $default = ''): string
    {
        $processValue = getenv($name);
        if ($processValue !== false && trim((string)$processValue) !== '') {
            return trim((string)$processValue);
        }

        if (isset($env[$name]) && trim((string)$env[$name]) !== '') {
            return trim((string)$env[$name]);
        }

        return $default;
    }
}

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? 0) == 443
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on';
$dbHost = env_value($env, 'DB_HOST', '127.0.0.1');
$dbPort = (int)env_value($env, 'DB_PORT', '3306');
$dbName = env_value($env, 'DB_NAME', 'mi_crud_local');
$dbUser = env_value($env, 'DB_USER', 'root');
$dbPassword = env_value($env, 'DB_PASSWORD', env_value($env, 'DB_PASS', ''));
$appEnv = strtolower(env_value($env, 'APP_ENV', 'production'));
$hcaptchaSitekey = env_value($env, 'HCAPTCHA_SITEKEY');
$hcaptchaSecret = env_value($env, 'HCAPTCHA_SECRET');
$googleClientId = env_value($env, 'GOOGLE_CLIENT_ID_OAUTH');
$googleClientSecret = env_value($env, 'GOOGLE_SECRET_CLIENT');
$googleRedirectUri = env_value($env, 'GOOGLE_OAUTH_REDIRECT_URI');

if (!in_array($appEnv, ['local', 'staging', 'production'], true)) {
    error_log('Invalid APP_ENV value.');
    http_response_code(500);
    exit('Application configuration unavailable.');
}

if ($dbPort < 1 || $dbPort > 65535) {
    error_log('Invalid database port.');
    http_response_code(500);
    exit('Application configuration unavailable.');
}

if ($appEnv === 'production') {
    $invalidProductionDatabase = $dbHost === ''
        || in_array(strtolower($dbHost), ['127.0.0.1', 'localhost'], true)
        || $dbName === ''
        || $dbName === 'mi_crud_local'
        || $dbUser === ''
        || strtolower($dbUser) === 'root'
        || $dbPassword === '';

    if ($invalidProductionDatabase || !$isHttps) {
        error_log('Production database credentials or HTTPS are not configured.');
        http_response_code(500);
        exit('Application configuration unavailable.');
    }

    if ($hcaptchaSitekey === '' || $hcaptchaSecret === '') {
        error_log('Production hCaptcha credentials are not configured.');
        http_response_code(500);
        exit('Application configuration unavailable.');
    }

    if ($googleRedirectUri !== '' && !str_starts_with(strtolower($googleRedirectUri), 'https://')) {
        error_log('Production Google OAuth redirect URI must use HTTPS.');
        http_response_code(500);
        exit('Application configuration unavailable.');
    }
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com https://js.hcaptcha.com 'nonce-{$cspNonce}'; style-src 'self' https://cdnjs.cloudflare.com 'nonce-{$cspNonce}'; img-src 'self' data: blob:; font-src 'self' https://cdnjs.cloudflare.com; frame-src https://hcaptcha.com https://*.hcaptcha.com; connect-src 'self' https://hcaptcha.com https://*.hcaptcha.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Cabeceras de seguridad adicionales
header('X-XSS-Protection: 1; mode=block');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');

try {
    $pdo = new PDO(
        'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed. PDO code: ' . $e->getCode());
    http_response_code(503);
    $isAjaxRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || ($_GET['ajax'] ?? '') === '1';
    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['success' => false, 'ok' => false, 'error' => 'Servicio de base de datos no disponible.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    exit('Servicio temporalmente no disponible.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $isHttps,
    ]);
    
    // Validar integridad de sesión (solo User-Agent para evitar problemas con IPs dinámicas)
    if (isset($_SESSION['user_id'])) {
        $currentFingerprint = md5(
            ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        
        if (!isset($_SESSION['session_fingerprint'])) {
            $_SESSION['session_fingerprint'] = $currentFingerprint;
        } elseif (!hash_equals($_SESSION['session_fingerprint'], $currentFingerprint)) {
            // Posible session hijacking, destruir sesión
            log_security_event('session_hijacking_attempt', "User: {$_SESSION['user_id']}");
            $_SESSION = [];
            session_destroy();
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure' => $isHttps,
            ]);
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Sesión invalidada por seguridad.'];
            redirect('login.php');
        }
    }
}

const SESSION_IDLE_TIMEOUT = 1800;

if (isset($_SESSION['user_id'])) {
    $lastActivity = $_SESSION['last_activity'] ?? null;
    $sessionExpired = !is_int($lastActivity)
        || $lastActivity < 1
        || time() - $lastActivity > SESSION_IDLE_TIMEOUT;

    if ($sessionExpired) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        session_id('');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => $isHttps,
        ]);
    } else {
        $_SESSION['last_activity'] = time();
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('utc_datetime_iso')) {
    function utc_datetime_iso(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), new DateTimeZone('UTC'));
        return $dateTime instanceof DateTimeImmutable ? $dateTime->format(DATE_ATOM) : '';
    }
}

if (!function_exists('redirect')) {
    function redirect(string $location): void
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('verify_hcaptcha')) {
    function verify_hcaptcha(string $response, ?string $remoteIp = null): bool
    {
        global $hcaptchaSecret;

        if ($hcaptchaSecret === '' || $response === '') {
            return false;
        }

        $fields = [
            'secret' => $hcaptchaSecret,
            'response' => $response,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }

        $payload = http_build_query($fields);
        $body = false;
        $status = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.hcaptcha.com/siteverify');
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                ]);
                $body = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
            }
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents('https://api.hcaptcha.com/siteverify', false, $context);
            $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)
                ? (int)$matches[1]
                : 0;
        }

        if (!is_string($body) || $status < 200 || $status >= 300) {
            return false;
        }

        $result = json_decode($body, true);
        return is_array($result) && ($result['success'] ?? false) === true;
    }
}

if (!function_exists('check_rate_limit')) {
    function check_rate_limit(string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool
    {
        $key = 'rate_limit_' . md5($identifier);
        $attempts = $_SESSION[$key]['count'] ?? 0;
        $lastAttempt = $_SESSION[$key]['time'] ?? 0;
        
        if (time() - $lastAttempt > $windowSeconds) {
            $_SESSION[$key] = ['count' => 1, 'time' => time()];
            return true;
        }
        
        if ($attempts >= $maxAttempts) {
            return false;
        }
        
        $_SESSION[$key]['count'] = $attempts + 1;
        return true;
    }
}

if (!function_exists('validate_ajax_origin')) {
    function validate_ajax_origin(): bool
    {
        // Lista de orígenes permitidos (configurar según entorno)
        $allowedOrigins = [
            'https://' . ($_SERVER['HTTP_HOST'] ?? ''),
            'http://' . ($_SERVER['HTTP_HOST'] ?? ''),
        ];
        
        // Agregar orígenes adicionales desde configuración
        $additionalOrigins = env_value($env, 'ALLOWED_ORIGINS', '');
        if ($additionalOrigins !== '') {
            $allowedOrigins = array_merge($allowedOrigins, explode(',', $additionalOrigins));
        }
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        
        // Si no hay Origin ni Referer, rechazar (para peticiones AJAX)
        if ($origin === '' && $referer === '') {
            return false;
        }
        
        // Validar Origin
        if ($origin !== '') {
            $originValid = false;
            foreach ($allowedOrigins as $allowed) {
                if (hash_equals($allowed, $origin)) {
                    $originValid = true;
                    break;
                }
            }
            if (!$originValid) {
                return false;
            }
        }
        
        // Validar Referer como fallback
        if ($referer !== '') {
            $refererValid = false;
            foreach ($allowedOrigins as $allowed) {
                if (str_starts_with($referer, $allowed)) {
                    $refererValid = true;
                    break;
                }
            }
            if (!$refererValid) {
                return false;
            }
        }
        
        return true;
    }
}

if (!function_exists('validate_content_type')) {
    function validate_content_type(array $allowedTypes): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if ($contentType === '') {
            return true; // No hay Content-Type, permitir (algunos clientes no lo envían)
        }
        
        foreach ($allowedTypes as $allowed) {
            if (str_contains($contentType, $allowed)) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('log_security_event')) {
    function log_security_event(string $eventType, string $details, ?int $userId = null): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        
        $logEntry = sprintf(
            "[%s] [SECURITY] [%s] [IP: %s] [User: %s] [URI: %s] [UA: %s] %s",
            $timestamp,
            strtoupper($eventType),
            $ip,
            $userId ?? 'guest',
            $requestUri,
            substr($userAgent, 0, 100),
            $details
        );
        
        error_log($logEntry);
    }
}

if (!function_exists('sanitize_html')) {
    function sanitize_html(string $html): string
    {
        // Permitir solo etiquetas básicas de forma segura
        $allowedTags = '<p><br><strong><em><u><s><a><ul><ol><li>';
        return strip_tags($html, $allowedTags);
    }
}

if (!function_exists('validate_request_origin')) {
    function validate_request_origin(): bool
    {
        $allowedOrigins = [
            'https://' . ($_SERVER['HTTP_HOST'] ?? ''),
            'http://' . ($_SERVER['HTTP_HOST'] ?? ''),
        ];
        
        $additionalOrigins = env_value($env, 'ALLOWED_ORIGINS', '');
        if ($additionalOrigins !== '') {
            $allowedOrigins = array_merge($allowedOrigins, explode(',', $additionalOrigins));
        }
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        
        // Si no hay Origin ni Referer, permitir (navegación directa)
        if ($origin === '' && $referer === '') {
            return true;
        }
        
        // Validar Origin
        if ($origin !== '') {
            foreach ($allowedOrigins as $allowed) {
                if (hash_equals($allowed, $origin)) {
                    return true;
                }
            }
        }
        
        // Validar Referer como fallback
        if ($referer !== '') {
            foreach ($allowedOrigins as $allowed) {
                if (str_starts_with($referer, $allowed)) {
                    return true;
                }
            }
        }
        
        return false;
    }
}

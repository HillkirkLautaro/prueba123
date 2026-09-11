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
        if (isset($env[$name]) && trim((string)$env[$name]) !== '') {
            return trim((string)$env[$name]);
        }

        $processValue = getenv($name);
        if ($processValue !== false && trim((string)$processValue) !== '') {
            return trim((string)$processValue);
        }

        return $default;
    }
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$dbHost = env_value($env, 'DB_HOST', '127.0.0.1');
$dbPort = (int)env_value($env, 'DB_PORT', '3306');
$dbName = env_value($env, 'DB_NAME', 'mi_crud_local');
$dbUser = env_value($env, 'DB_USER', 'root');
$dbPassword = env_value($env, 'DB_PASSWORD', env_value($env, 'DB_PASS', ''));
$appEnv = env_value($env, 'APP_ENV', 'local');
$hcaptchaSitekey = env_value($env, 'HCAPTCHA_SITEKEY');
$hcaptchaSecret = env_value($env, 'HCAPTCHA_SECRET');
$googleClientId = env_value($env, 'GOOGLE_CLIENT_ID_OAUTH');
$googleClientSecret = env_value($env, 'GOOGLE_SECRET_CLIENT');
$googleRedirectUri = env_value($env, 'GOOGLE_OAUTH_REDIRECT_URI');

if ($appEnv === 'production' && ($dbHost === '127.0.0.1' || $dbName === 'mi_crud_local' || $dbUser === 'root')) {
    error_log('Production database environment variables are not configured.');
    http_response_code(500);
    exit('Application configuration unavailable.');
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com https://js.hcaptcha.com 'nonce-{$cspNonce}'; style-src 'self' https://cdnjs.cloudflare.com; style-src-attr 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' https://cdnjs.cloudflare.com; frame-src https://hcaptcha.com https://*.hcaptcha.com; connect-src 'self' https://hcaptcha.com https://*.hcaptcha.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

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
    error_log('DB connection failed [' . $e->getCode() . '] for host ' . $dbHost . ', database ' . $dbName . ': ' . $e->getMessage());
    http_response_code(503);
    exit('Error PDO: [' . $e->getCode() . '] ' . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $isHttps,
    ]);
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
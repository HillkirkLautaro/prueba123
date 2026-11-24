<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function json_error(int $code, string $message, ?array $extra = null): void
{
    $body = ['success' => false, 'error' => $message];
    if ($extra !== null) {
        $body = array_merge($body, $extra);
    }
    json_response($code, $body);
}

function extract_bearer_token(): string
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');

    if ($authHeader === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $key => $val) {
                if (strcasecmp((string)$key, 'Authorization') === 0 || strcasecmp((string)$key, 'X-Auth-Token') === 0) {
                    $authHeader = (string)$val;
                    break;
                }
            }
        }
    }

    $trimmed = trim((string)$authHeader);
    if (preg_match('/^Bearer\s+(.+)$/i', $trimmed, $matches)) {
        return trim($matches[1]);
    }
    return $trimmed;
}

function base64url_decode_strict(string $data): string|false
{
    $remainder = strlen($data) % 4;
    if ($remainder !== 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function verify_local_jwt_user_id(string $token): ?int
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $sigB64] = $parts;
    $headerJson = base64url_decode_strict($headerB64);
    $payloadJson = base64url_decode_strict($payloadB64);
    $sigRaw = base64url_decode_strict($sigB64);

    if ($headerJson === false || $payloadJson === false || $sigRaw === false) {
        return null;
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload) || ($payload['iss'] ?? '') !== 'prueba123.gt.tc') {
        return null; // No es un JWT emitido por api/auth.php (es un token de Google)
    }

    if (($header['alg'] ?? '') !== 'HS256') {
        return 0;
    }

    $authFile = __DIR__ . '/auth.php';
    $secret = defined('APP_SECRET')
        ? (string)APP_SECRET
        : hash('sha256', $authFile . '|' . (string)($GLOBALS['db_pass'] ?? 'prueba123_secret'));

    $expectedSig = hash_hmac('sha256', "$headerB64.$payloadB64", $secret, true);
    if (!hash_equals($expectedSig, $sigRaw)) {
        return 0; // Firma manipulada
    }

    $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
    if ($exp > 0 && time() >= $exp) {
        return 0; // Token expirado
    }

    $sub = isset($payload['sub']) ? (int)$payload['sub'] : 0;
    return $sub > 0 ? $sub : 0;
}

function verify_google_token_payload(string $idToken): ?array
{
    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);

    if (function_exists('curl_init')) {
        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (is_string($resp) && $resp !== '') {
            $decoded = json_decode($resp, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 6,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $resp = @file_get_contents($verifyUrl, false, $ctx);
    $decoded = is_string($resp) ? json_decode($resp, true) : null;
    return is_array($decoded) ? $decoded : null;
}

function resolve_authenticated_user_id(PDO $pdo, bool $strict): int
{
    $idToken = extract_bearer_token();

    if ($idToken !== '') {
        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $idToken)) {
            if ($strict) {
                json_error(401, 'Formato de token de autenticación inválido.');
            }
            return 0;
        }

        // 1. Verificar si es un JWT local emitido por api/auth.php
        $localUserId = verify_local_jwt_user_id($idToken);
        if ($localUserId !== null) {
            if ($localUserId < 1) {
                if ($strict) {
                    json_error(401, 'Token de sesión local inválido o expirado.');
                }
                return 0;
            }
            $chk = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id LIMIT 1');
            $chk->execute([':id' => $localUserId]);
            if (!$chk->fetch()) {
                if ($strict) {
                    json_error(401, 'Usuario no encontrado.');
                }
                return 0;
            }
            return $localUserId;
        }

        // 2. Caso contrario, validar como Google ID Token
        $payload = verify_google_token_payload($idToken);
        $emailVerified = $payload['email_verified'] ?? false;
        $isVerified = ($emailVerified === true || $emailVerified === 'true' || $emailVerified === 1 || $emailVerified === '1');

        if (!is_array($payload) || empty($payload['email']) || !$isVerified) {
            if ($strict) {
                json_error(401, 'Token de Google inválido o expirado.');
            }
            return 0;
        }

        $email = mb_substr(trim((string)$payload['email']), 0, 254);
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            return (int)$user['id'];
        }

        $baseUser = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]);
        if ($baseUser === '') {
            $baseUser = 'usuario';
        }
        $username = substr($baseUser, 0, 42) . '_' . random_int(1000, 9999);
        $randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $avatar = (isset($payload['picture']) && is_string($payload['picture']) && filter_var($payload['picture'], FILTER_VALIDATE_URL))
            ? substr($payload['picture'], 0, 500)
            : null;

        $ins = $pdo->prepare('INSERT INTO usuarios (username, email, password, avatar, email_verified_at)
                              VALUES (:u, :e, :p, :a, UTC_TIMESTAMP())');
        $ins->execute([
            ':u' => $username,
            ':e' => $email,
            ':p' => $randomPass,
            ':a' => $avatar,
        ]);
        return (int)$pdo->lastInsertId();
    }

    if (function_exists('is_logged_in') && is_logged_in()) {
        return (int)$_SESSION['user_id'];
    }

    if ($strict) {
        json_error(401, 'Debes iniciar sesión para acceder o modificar este perfil.');
    }
    return 0;
}

function resolve_safe_avatar_url(?string $rawAvatar): string
{
    $baseUrl = 'https://prueba123.gt.tc/';
    $defaultRel = 'uploads/avatars/default.png';

    if (is_string($rawAvatar) && $rawAvatar !== '') {
        if (preg_match('#^https://#i', $rawAvatar) && filter_var($rawAvatar, FILTER_VALIDATE_URL)) {
            return $rawAvatar;
        }

        $filename = basename($rawAvatar);
        $localPath = __DIR__ . '/../uploads/avatars/' . $filename;
        if ($filename !== '' && is_file($localPath) && @getimagesize($localPath) !== false) {
            return $baseUrl . 'uploads/avatars/' . rawurlencode($filename);
        }
    }

    if (is_file(__DIR__ . '/../' . $defaultRel)) {
        return $baseUrl . $defaultRel;
    }

    return '';
}

if ($method === 'GET') {
    try {
        $requestedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $requireAuth = ($requestedId === null || $requestedId === false);
        $viewerId = resolve_authenticated_user_id($pdo, $requireAuth);

        $targetUserId = ($requestedId !== false && $requestedId !== null && $requestedId >= 1)
            ? (int)$requestedId
            : $viewerId;

        if ($targetUserId < 1) {
            json_error(400, 'ID de usuario inválido.');
        }

        $userStmt = $pdo->prepare('SELECT id, username, email, avatar, email_verified_at, created_at
                                   FROM usuarios
                                   WHERE id = :id
                                   LIMIT 1');
        $userStmt->execute([':id' => $targetUserId]);
        $profile = $userStmt->fetch();

        if (!$profile) {
            json_error(404, 'Usuario no encontrado.');
        }

        $isOwner = ($viewerId > 0 && $viewerId === (int)$profile['id']);

        $statsStmt = $pdo->prepare("SELECT
                                        (SELECT COUNT(*) FROM comentarios WHERE usuario_id = :u1) AS comment_count,
                                        (SELECT COUNT(*) FROM comentarios WHERE usuario_id = :u2 AND parent_id IS NOT NULL) AS replies_sent_count,
                                        (SELECT COUNT(*)
                                         FROM votos v
                                         INNER JOIN comentarios c ON c.id = v.comentario_id
                                         WHERE c.usuario_id = :u3 AND v.tipo_voto = 'like') AS received_likes");
        $statsStmt->execute([
            ':u1' => $targetUserId,
            ':u2' => $targetUserId,
            ':u3' => $targetUserId,
        ]);
        $stats = $statsStmt->fetch() ?: [
            'comment_count' => 0,
            'replies_sent_count' => 0,
            'received_likes' => 0,
        ];

        $unreadNotifications = 0;
        if ($isOwner) {
            $notifStmt = $pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = :uid AND leido = 0');
            $notifStmt->execute([':uid' => $targetUserId]);
            $unreadNotifications = (int)$notifStmt->fetchColumn();
        }

        $commentsStmt = $pdo->prepare("SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                              u.username,
                                              (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'like') AS likes,
                                              (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'dislike') AS dislikes,
                                              (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = :viewer_vote LIMIT 1) AS viewer_vote,
                                              (SELECT COUNT(*) FROM comentarios r WHERE r.parent_id = c.id) AS replies_count,
                                              CASE WHEN c.usuario_id = :viewer_owner AND :viewer_owner2 > 0 THEN 1 ELSE 0 END AS is_owner
                                       FROM comentarios c
                                       LEFT JOIN usuarios u ON u.id = c.usuario_id
                                       WHERE c.usuario_id = :usuario_id
                                       ORDER BY c.created_at DESC
                                       LIMIT 30");
        $commentsStmt->bindValue(':viewer_vote', $viewerId, PDO::PARAM_INT);
        $commentsStmt->bindValue(':viewer_owner', $viewerId, PDO::PARAM_INT);
        $commentsStmt->bindValue(':viewer_owner2', $viewerId, PDO::PARAM_INT);
        $commentsStmt->bindValue(':usuario_id', $targetUserId, PDO::PARAM_INT);
        $commentsStmt->execute();
        $userComments = $commentsStmt->fetchAll();

        json_response(200, [
            'success' => true,
            'data' => [
                'id' => (int)$profile['id'],
                'username' => (string)$profile['username'],
                'email' => $isOwner ? (string)$profile['email'] : null,
                'email_verified' => $profile['email_verified_at'] !== null,
                'created_at' => (string)$profile['created_at'],
                'avatar_url' => resolve_safe_avatar_url($profile['avatar'] ?? null),
                'is_owner' => $isOwner,
                'unread_notifications' => $unreadNotifications,
                'stats' => [
                    'comment_count' => (int)($stats['comment_count'] ?? 0),
                    'replies_sent_count' => (int)($stats['replies_sent_count'] ?? 0),
                    'received_likes' => (int)($stats['received_likes'] ?? 0),
                ],
                'comentarios' => $userComments,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('API perfil GET error: ' . $e->getMessage());
        json_error(500, 'Error del servidor al obtener el perfil.');
    }
}

if ($method === 'POST') {
    try {
        $hasBearer = extract_bearer_token() !== '';
        $userId = resolve_authenticated_user_id($pdo, true);

        if (!$hasBearer) {
            if (!validate_request_origin()) {
                log_security_event('invalid_profile_origin', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                json_error(403, 'Origen no autorizado.');
            }
            if (!csrf_valid($_POST['csrf_token'] ?? null)) {
                log_security_event('csrf_attempt', 'API perfil avatar update attempt');
                json_error(403, 'Token CSRF inválido.');
            }
        }

        $rateLimitKey = 'avatar_upload_' . $userId;
        if (!check_rate_limit($rateLimitKey, 5, 300)) {
            json_error(429, 'Demasiados cambios de avatar. Espera unos minutos.');
        }

        $file = $_FILES['avatar'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_error(422, 'No se recibió una imagen válida en el campo avatar.');
        }

        if (!isset($file['tmp_name'], $file['size'])
            || !is_string($file['tmp_name'])
            || !is_uploaded_file($file['tmp_name'])
            || (int)$file['size'] <= 0
            || (int)$file['size'] > 2 * 1024 * 1024) {
            json_error(422, 'La imagen debe pesar entre 1 byte y 2 MB.');
        }

        $mime = false;
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $mime = @mime_content_type($file['tmp_name']);
        }
        if ($mime === false) {
            $imageInfo = @getimagesize($file['tmp_name']);
            $mime = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : false;
        }

        $allowedTypes = [
            'image/png' => 'png',
            'image/x-png' => 'png',
            'image/jpeg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ];

        if ($mime === false || !isset($allowedTypes[$mime])) {
            json_error(422, 'Formato no permitido. Solo PNG, JPG, GIF, WebP o AVIF.');
        }

        if (@getimagesize($file['tmp_name']) === false) {
            json_error(422, 'El archivo no contiene una imagen válida.');
        }

        $prevStmt = $pdo->prepare('SELECT avatar FROM usuarios WHERE id = :id LIMIT 1');
        $prevStmt->execute([':id' => $userId]);
        $prevAvatar = $prevStmt->fetchColumn();

        $extReal = $allowedTypes[$mime];
        $uploadDir = __DIR__ . '/../uploads/avatars';
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            json_error(500, 'No se pudo preparar el directorio de avatares.');
        }
        if (!is_writable($uploadDir)) {
            json_error(500, 'El directorio de avatares no tiene permisos de escritura.');
        }

        $safeName = 'profile_' . $userId . '_' . bin2hex(random_bytes(16)) . '.' . $extReal;
        $targetPath = $uploadDir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            json_error(500, 'No se pudo guardar la imagen en el servidor.');
        }

        $avatarPath = 'uploads/avatars/' . $safeName;
        try {
            $update = $pdo->prepare('UPDATE usuarios SET avatar = :avatar WHERE id = :id LIMIT 1');
            $update->execute([
                ':avatar' => $avatarPath,
                ':id' => $userId,
            ]);
        } catch (PDOException $e) {
            @unlink($targetPath);
            error_log('API perfil avatar update failed: ' . $e->getMessage());
            json_error(500, 'Error al actualizar el avatar en la base de datos.');
        }

        if (is_string($prevAvatar) && $prevAvatar !== '' && !preg_match('#^https?://#i', $prevAvatar)) {
            $oldFilename = basename($prevAvatar);
            $oldPath = $uploadDir . '/' . $oldFilename;
            if ($oldFilename !== 'default.png' && $oldFilename !== $safeName && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        json_response(200, [
            'success' => true,
            'message' => 'Foto de perfil actualizada correctamente.',
            'data' => [
                'avatar_url' => resolve_safe_avatar_url($avatarPath),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('API perfil POST error: ' . $e->getMessage());
        json_error(500, 'Error del servidor al actualizar el perfil.');
    }
}

json_error(405, 'Método HTTP no permitido.');

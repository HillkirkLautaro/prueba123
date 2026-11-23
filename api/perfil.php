<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Envía una respuesta JSON estructurada y finaliza la ejecución.
 */
function json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Envía una respuesta JSON de error estandarizada.
 */
function json_error(int $code, string $message, ?array $extra = null): void
{
    $body = ['success' => false, 'error' => $message];
    if ($extra !== null) {
        $body = array_merge($body, $extra);
    }
    json_response($code, $body);
}

/**
 * Extrae el token Bearer / X-Auth-Token de forma compatible con Apache/InfinityFree.
 */
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

/**
 * Valida opcionalmente el token de Google y retorna el user_id local (0 si no hay token).
 * Si hay token pero es inválido, aborta con 401 si $strict = true.
 */
function resolve_authenticated_user_id(PDO $pdo, bool $strict): int
{
    $idToken = extract_bearer_token();

    if ($idToken !== '') {
        // Validación básica de formato JWT (3 segmentos base64url) antes de consultar externamente
        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $idToken)) {
            if ($strict) {
                json_error(401, 'Formato de token de autenticación inválido.');
            }
            return 0;
        }

        $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $resp = @file_get_contents($verifyUrl, false, $ctx);
        $payload = is_string($resp) ? json_decode($resp, true) : null;

        if (!is_array($payload) || empty($payload['email']) || ($payload['email_verified'] ?? '') !== 'true') {
            if ($strict) {
                json_error(401, 'Token de Google inválido o expirado.');
            }
            return 0;
        }

        $email = (string)$payload['email'];
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            return (int)$user['id'];
        }

        // Provisionamiento automático si el usuario de Google aún no existía en MySQL
        $baseUser = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]);
        if ($baseUser === '') {
            $baseUser = 'usuario';
        }
        $username = substr($baseUser, 0, 40) . '_' . random_int(100, 999);
        $randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $avatar = isset($payload['picture']) && is_string($payload['picture']) && filter_var($payload['picture'], FILTER_VALIDATE_URL)
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

    if (is_logged_in()) {
        return (int)$_SESSION['user_id'];
    }

    if ($strict) {
        json_error(401, 'Debes iniciar sesión para acceder o modificar este perfil.');
    }
    return 0;
}

/**
 * Resuelve la URL pública segura del avatar evitando Path Traversal.
 */
function resolve_safe_avatar_url(?string $rawAvatar): string
{
    $baseUrl = 'https://prueba123.gt.tc/';
    $defaultRel = 'uploads/avatars/default.png';

    if (is_string($rawAvatar) && $rawAvatar !== '') {
        // Si es una URL externa segura (ej. foto de perfil de Google), devolverla validada
        if (preg_match('#^https://#i', $rawAvatar) && filter_var($rawAvatar, FILTER_VALIDATE_URL)) {
            return $rawAvatar;
        }

        // Si es archivo local, sanitizar con basename() contra Path Traversal
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

// =====================================================================
// GET: Obtener datos del perfil propio (sin ?id) o público (?id=X)
// =====================================================================
if ($method === 'GET') {
    $requestedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $viewerId = resolve_authenticated_user_id($pdo, $requestedId === null || $requestedId === false);

    $targetUserId = ($requestedId !== false && $requestedId !== null && $requestedId >= 1)
        ? (int)$requestedId
        : $viewerId;

    if ($targetUserId < 1) {
        json_error(400, 'ID de usuario inválido.');
    }

    $userStmt = $pdo->prepare('SELECT id, username, email, avatar FROM usuarios WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $targetUserId]);
    $profile = $userStmt->fetch();

    if (!$profile) {
        json_error(404, 'Usuario no encontrado.');
    }

    $isOwner = ($viewerId > 0 && $viewerId === (int)$profile['id']);

    $statsStmt = $pdo->prepare('SELECT COUNT(DISTINCT c.id) AS comment_count,
                                       COUNT(v.id) AS received_likes
                                FROM comentarios c
                                LEFT JOIN votos v ON v.comentario_id = c.id AND v.tipo_voto = 'like'
                                WHERE c.usuario_id = :usuario_id');
    $statsStmt->execute([':usuario_id' => $targetUserId]);
    $stats = $statsStmt->fetch() ?: ['comment_count' => 0, 'received_likes' => 0];

    $commentsStmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                          u.username,
                                          (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'like') AS likes,
                                          (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'dislike') AS dislikes,
                                          (SELECT COUNT(*) FROM comentarios r WHERE r.parent_id = c.id) AS replies_count,
                                          CASE WHEN c.usuario_id = :viewer_id AND :viewer_id2 > 0 THEN 1 ELSE 0 END AS is_owner
                                   FROM comentarios c
                                   LEFT JOIN usuarios u ON u.id = c.usuario_id
                                   WHERE c.usuario_id = :usuario_id
                                   ORDER BY c.created_at DESC
                                   LIMIT 30');
    $commentsStmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
    $commentsStmt->bindValue(':viewer_id2', $viewerId, PDO::PARAM_INT);
    $commentsStmt->bindValue(':usuario_id', $targetUserId, PDO::PARAM_INT);
    $commentsStmt->execute();
    $userComments = $commentsStmt->fetchAll();

    json_response(200, [
        'success' => true,
        'data' => [
            'id' => (int)$profile['id'],
            'username' => (string)$profile['username'],
            'email' => $isOwner ? (string)$profile['email'] : null,
            'avatar_url' => resolve_safe_avatar_url($profile['avatar'] ?? null),
            'is_owner' => $isOwner,
            'stats' => [
                'comment_count' => (int)($stats['comment_count'] ?? 0),
                'received_likes' => (int)($stats['received_likes'] ?? 0),
            ],
            'comentarios' => $userComments,
        ],
    ]);
}

// =====================================================================
// POST: Actualizar foto de perfil (multipart/form-data, máx 2 MB)
// =====================================================================
if ($method === 'POST') {
    $hasBearer = extract_bearer_token() !== '';
    $userId = resolve_authenticated_user_id($pdo, true);

    // Si se invoca desde sesión web sin Bearer token, exigir origin + CSRF
    if (!$hasBearer) {
        if (!validate_request_origin()) {
            log_security_event('invalid_profile_origin', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            json_error(403, 'Origen no autorizado.');
        }
        if (!csrf_valid($_POST['csrf_token'] ?? null)) {
            log_security_event('csrf_attempt', 'API profile avatar update attempt');
            json_error(403, 'Token CSRF inválido.');
        }
    }

    $rateLimitKey = 'avatar_upload_' . $userId;
    if (!check_rate_limit($rateLimitKey, 5, 300)) {
        json_error(429, 'Demasiados cambios de avatar. Esperá unos minutos.');
    }

    $file = $_FILES['avatar'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error(422, 'No se recibió una imagen válida.');
    }

    if (!isset($file['tmp_name'], $file['size'])
        || !is_string($file['tmp_name'])
        || !is_uploaded_file($file['tmp_name'])
        || (int)$file['size'] <= 0
        || (int)$file['size'] > 2 * 1024 * 1024) {
        json_error(422, 'La imagen debe pesar entre 1 byte y 2 MB.');
    }

    // Validación estricta de MIME real en el servidor (no confiar en Content-Type ni extensión)
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

    // Obtener avatar anterior para limpieza posterior
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
        error_log('API profile avatar update failed: ' . $e->getMessage());
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
}

json_error(405, 'Método HTTP no permitido.');

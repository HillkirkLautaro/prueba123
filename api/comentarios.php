<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $override = strtoupper(trim((string)$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']));
    if (in_array($override, ['PUT', 'DELETE'], true)) {
        $method = $override;
    }
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

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

function request_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return $_POST;
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

function base64url_decode_strict(string $data)
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
        return null;
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
        return 0;
    }

    $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
    if ($exp > 0 && time() >= $exp) {
        return 0;
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
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
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
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $resp = @file_get_contents($verifyUrl, false, $ctx);
    $decoded = is_string($resp) ? json_decode($resp, true) : null;
    return is_array($decoded) ? $decoded : null;
}

function resolve_authenticated_user_id(PDO $pdo, bool $strict, array $bodyData = [], bool $requireCsrfForWeb = false): int
{
    $idToken = extract_bearer_token();

    if ($idToken !== '') {
        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $idToken)) {
            if ($strict) {
                json_error(401, 'Formato de token de autenticación inválido.');
            }
            return 0;
        }

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
        if ($strict && $requireCsrfForWeb) {
            $token = $_POST['csrf_token'] ?? ($bodyData['csrf_token'] ?? null);
            if (!csrf_valid($token)) {
                json_error(403, 'Token CSRF inválido.');
            }
        }
        return (int)$_SESSION['user_id'];
    }

    if ($strict) {
        json_error(401, 'Debes iniciar sesión para realizar esta acción.');
    }
    return 0;
}

/**
 * Carga todo el árbol de respuestas por niveles usando consultas IN (...)
 * en lugar de recursión SQL, evitando bloqueos y ciclos infinitos en MySQL.
 */
function fetch_reply_tree_iterative(PDO $pdo, int $rootId, int $viewerId): array
{
    $allRows = [];
    $visited = [$rootId => true];
    $frontier = [$rootId];

    for ($level = 0; $level < 6 && !empty($frontier); $level++) {
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $sql = "SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                       u.username, u.avatar,
                       (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'like') AS likes,
                       (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'dislike') AS dislikes,
                       (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = ? LIMIT 1) AS viewer_vote,
                       (SELECT COUNT(*) FROM comentarios r WHERE r.parent_id = c.id) AS replies_count,
                       CASE WHEN c.usuario_id = ? AND ? > 0 THEN 1 ELSE 0 END AS is_owner
                FROM comentarios c
                LEFT JOIN usuarios u ON u.id = c.usuario_id
                WHERE c.parent_id IN ($placeholders)
                ORDER BY c.created_at ASC
                LIMIT 200";

        $stmt = $pdo->prepare($sql);
        $params = array_merge([$viewerId, $viewerId, $viewerId], $frontier);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $nextFrontier = [];
        foreach ($rows as $row) {
            $rid = (int)$row['id'];
            if (isset($visited[$rid])) {
                continue;
            }
            $visited[$rid] = true;
            $row['respuestas'] = [];
            $allRows[$rid] = $row;
            $nextFrontier[] = $rid;
        }
        $frontier = $nextFrontier;
    }

    // Reconstruir árbol en memoria de abajo hacia arriba
    $childrenByParent = [];
    foreach ($allRows as $rid => $row) {
        $pid = (int)$row['parent_id'];
        $childrenByParent[$pid][] = $rid;
    }

    $buildBranch = function (int $parentId) use (&$buildBranch, &$allRows, &$childrenByParent): array {
        if (empty($childrenByParent[$parentId])) {
            return [];
        }
        $branch = [];
        foreach ($childrenByParent[$parentId] as $childId) {
            $node = $allRows[$childId];
            $node['respuestas'] = $buildBranch($childId);
            $branch[] = $node;
        }
        return $branch;
    };

    return $buildBranch($rootId);
}

// --------------------- GET: listar o obtener uno ---------------------
if ($method === 'GET') {
    try {
        $viewerId = resolve_authenticated_user_id($pdo, false);

        if ($id !== false && $id !== null && $id >= 1) {
            $stmt = $pdo->prepare("SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                          u.username, u.avatar,
                                          (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'like') AS likes,
                                          (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'dislike') AS dislikes,
                                          (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = :viewer_id LIMIT 1) AS viewer_vote,
                                          (SELECT COUNT(*) FROM comentarios r WHERE r.parent_id = c.id) AS replies_count,
                                          CASE WHEN c.usuario_id = :viewer_owner AND :viewer_owner2 > 0 THEN 1 ELSE 0 END AS is_owner
                                   FROM comentarios c
                                   LEFT JOIN usuarios u ON u.id = c.usuario_id
                                   WHERE c.id = :id LIMIT 1");
            $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
            $stmt->bindValue(':viewer_owner', $viewerId, PDO::PARAM_INT);
            $stmt->bindValue(':viewer_owner2', $viewerId, PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $comment = $stmt->fetch();
            if (!$comment) {
                json_error(404, 'Comentario no encontrado.');
            }

            $comment['respuestas'] = fetch_reply_tree_iterative($pdo, (int)$comment['id'], $viewerId);
            json_response(200, ['success' => true, 'data' => $comment]);
        }

        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
        if ($page === false || $page === null || $page < 1) {
            $page = 1;
        }
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare("SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                      u.username, u.avatar,
                                      (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'like') AS likes,
                                      (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = 'dislike') AS dislikes,
                                      (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = :viewer_id LIMIT 1) AS viewer_vote,
                                      (SELECT COUNT(*) FROM comentarios r WHERE r.parent_id = c.id) AS replies_count,
                                      CASE WHEN c.usuario_id = :viewer_owner AND :viewer_owner2 > 0 THEN 1 ELSE 0 END AS is_owner
                               FROM comentarios c
                               LEFT JOIN usuarios u ON u.id = c.usuario_id
                               WHERE c.parent_id IS NULL
                               ORDER BY c.created_at DESC
                               LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
        $stmt->bindValue(':viewer_owner', $viewerId, PDO::PARAM_INT);
        $stmt->bindValue(':viewer_owner2', $viewerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $comments = $stmt->fetchAll();

        json_response(200, [
            'success' => true,
            'data' => $comments,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    } catch (Throwable $e) {
        error_log('API GET comments failed: ' . $e->getMessage());
        json_error(500, 'Error del servidor al obtener los comentarios.');
    }
}

// --------------------- POST: crear comentario o respuesta ---------------------
if ($method === 'POST') {
    $data = request_body();
    $userId = resolve_authenticated_user_id($pdo, true, $data, true);

    $contenidoRaw = $data['contenido'] ?? null;
    if (!is_string($contenidoRaw) || !mb_check_encoding($contenidoRaw, 'UTF-8')) {
        json_error(422, 'El comentario debe ser texto UTF-8 válido.');
    }
    $contenido = trim($contenidoRaw);

    if ($contenido === '' || mb_strlen($contenido, 'UTF-8') > 5000) {
        json_error(422, 'El comentario debe tener entre 1 y 5000 caracteres válidos.');
    }

    $parentId = null;
    if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null && $data['parent_id'] !== '') {
        $parentIdVal = filter_var($data['parent_id'], FILTER_VALIDATE_INT);
        if ($parentIdVal === false || $parentIdVal < 1) {
            json_error(422, 'El ID del comentario padre es inválido.');
        }

        // Verificar que exista y que sea un comentario raíz (parent_id IS NULL)
        $checkParent = $pdo->prepare('SELECT id, parent_id FROM comentarios WHERE id = :pid LIMIT 1');
        $checkParent->execute([':pid' => $parentIdVal]);
        $parentRow = $checkParent->fetch();
        if (!$parentRow) {
            json_error(404, 'El comentario al que intentas responder no existe.');
        }
        if ($parentRow['parent_id'] !== null) {
            json_error(422, 'No se pueden agregar respuestas anidadas sobre otra respuesta.');
        }
        $parentId = $parentIdVal;
    }

    $rateLimitKey = 'comment_' . $userId;
    if (!check_rate_limit($rateLimitKey, 5, 60)) {
        json_error(429, 'Demasiados comentarios. Espera un momento antes de publicar de nuevo.');
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO comentarios (usuario_id, parent_id, contenido, created_at, updated_at)
                               VALUES (:usuario_id, :parent_id, :contenido, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->execute([
            ':usuario_id' => $userId,
            ':parent_id' => $parentId,
            ':contenido' => $contenido,
        ]);
        $newId = (int)$pdo->lastInsertId();
        json_response(201, [
            'success' => true,
            'message' => $parentId !== null ? 'Respuesta publicada.' : 'Comentario creado.',
            'data' => [
                'id' => $newId,
                'parent_id' => $parentId,
            ],
        ]);
    } catch (PDOException $e) {
        error_log('API create comment failed: ' . $e->getMessage());
        json_error(500, 'Error del servidor al crear el comentario.');
    }
}

// --------------------- PUT: modificar ---------------------
if ($method === 'PUT') {
    if ($id === false || $id === null || $id < 1) {
        json_error(400, 'ID de comentario requerido.');
    }
    $data = request_body();
    $userId = resolve_authenticated_user_id($pdo, true, $data, false);

    $check = $pdo->prepare('SELECT usuario_id FROM comentarios WHERE id = :id LIMIT 1');
    $check->execute([':id' => $id]);
    $comment = $check->fetch();
    if (!$comment) {
        json_error(404, 'Comentario no encontrado.');
    }
    if ((int)$comment['usuario_id'] !== $userId) {
        json_error(403, 'No tienes permiso para editar este comentario.');
    }

    $contenidoRaw = $data['contenido'] ?? null;
    if (!is_string($contenidoRaw) || !mb_check_encoding($contenidoRaw, 'UTF-8')) {
        json_error(422, 'El contenido debe ser texto UTF-8 válido.');
    }
    $contenido = trim($contenidoRaw);
    if ($contenido === '' || mb_strlen($contenido, 'UTF-8') > 5000) {
        json_error(422, 'El contenido debe tener entre 1 y 5000 caracteres válidos.');
    }

    try {
        $update = $pdo->prepare('UPDATE comentarios SET contenido = :contenido, updated_at = UTC_TIMESTAMP()
                                 WHERE id = :id AND usuario_id = :usuario_id');
        $update->execute([
            ':contenido' => $contenido,
            ':id' => $id,
            ':usuario_id' => $userId,
        ]);
        json_response(200, ['success' => true, 'message' => 'Comentario actualizado.']);
    } catch (PDOException $e) {
        error_log('API update comment failed: ' . $e->getMessage());
        json_error(500, 'Error del servidor al actualizar el comentario.');
    }
}

// --------------------- DELETE: borrar ---------------------
if ($method === 'DELETE') {
    if ($id === false || $id === null || $id < 1) {
        json_error(400, 'ID de comentario requerido.');
    }
    $userId = resolve_authenticated_user_id($pdo, true, [], false);

    $check = $pdo->prepare('SELECT usuario_id FROM comentarios WHERE id = :id LIMIT 1');
    $check->execute([':id' => $id]);
    $comment = $check->fetch();
    if (!$comment) {
        json_error(404, 'Comentario no encontrado.');
    }
    if ((int)$comment['usuario_id'] !== $userId) {
        json_error(403, 'No tienes permiso para eliminar este comentario.');
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM comentarios WHERE id = :id AND usuario_id = :usuario_id');
        $stmt->execute([
            ':id' => $id,
            ':usuario_id' => $userId,
        ]);
        json_response(200, ['success' => true, 'message' => 'Comentario eliminado.']);
    } catch (PDOException $e) {
        error_log('API delete comment failed: ' . $e->getMessage());
        json_error(500, 'Error del servidor al eliminar el comentario.');
    }
}

json_error(405, 'Método no permitido.');

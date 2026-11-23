<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../csrf.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $override = strtoupper(trim($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']));
    if (in_array($override, ['PUT', 'DELETE'], true)) {
        $method = $override;
    }
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

/**
 * Respuesta JSON con código de estado HTTP.
 */
function json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Respuesta JSON de error.
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
 * Obtiene el cuerpo JSON de la petición (soporta application/json y form-urlencoded).
 */
function request_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return $_POST;
}

/**
 * Autentica la petición:
 * - App Android: Authorization: Bearer <google_id_token> (o X-Auth-Token)
 * - Web: Sesión PHP + token CSRF
 */
function authenticate_api_request(PDO $pdo, array $bodyData, bool $requireCsrf = true): int
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');

    $idToken = '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
        $idToken = trim($matches[1]);
    } elseif (trim($authHeader) !== '') {
        $idToken = trim($authHeader);
    }

    if ($idToken !== '') {
        $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $resp = @file_get_contents($verifyUrl, false, $ctx);
        $payload = $resp ? json_decode($resp, true) : null;

        if (!is_array($payload) || empty($payload['email']) || ($payload['email_verified'] ?? '') !== 'true') {
            json_error(401, 'Token de Google inválido o expirado.');
        }

        $email = $payload['email'];
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
        $username = substr($baseUser, 0, 40) . '_' . random_int(100, 999);
        $randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $avatar = isset($payload['picture']) && is_string($payload['picture']) ? substr($payload['picture'], 0, 500) : null;

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

    if (!is_logged_in()) {
        json_error(401, 'Debes iniciar sesión para realizar esta acción.');
    }
    if ($requireCsrf) {
        $token = $_POST['csrf_token'] ?? ($bodyData['csrf_token'] ?? null);
        if (!csrf_valid($token)) {
            json_error(403, 'Token CSRF inválido.');
        }
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Obtiene recursivamente las respuestas anidadas de un comentario.
 */
function fetch_nested_replies(PDO $pdo, int $parentId, int $viewerId, int $depth = 0): array
{
    if ($depth > 10) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                  u.username, u.avatar,
                                  (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'like\') AS likes,
                                  (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'dislike\') AS dislikes,
                                  (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = :viewer_id LIMIT 1) AS viewer_vote,
                                  (SELECT COUNT(*) FROM comentarios r WHERE r.parent_id = c.id) AS replies_count
                           FROM comentarios c
                           LEFT JOIN usuarios u ON u.id = c.usuario_id
                           WHERE c.parent_id = :parent_id
                           ORDER BY c.created_at ASC');
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
    $stmt->execute();
    $replies = $stmt->fetchAll();

    foreach ($replies as &$reply) {
        $replyId = (int)$reply['id'];
        $reply['respuestas'] = fetch_nested_replies($pdo, $replyId, $viewerId, $depth + 1);
    }
    unset($reply);

    return $replies;
}

// --------------------- GET: listar o obtener uno ---------------------
if ($method === 'GET') {
    $viewerId = is_logged_in() ? (int)$_SESSION['user_id'] : 0;

    if ($id !== false && $id !== null && $id >= 1) {
        $stmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                      u.username, u.avatar,
                                      (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'like\') AS likes,
                                      (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'dislike\') AS dislikes,
                                      (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = :viewer_id LIMIT 1) AS viewer_vote,
                                      (SELECT COUNT(*) FROM comentarios r WHERE r.parent_id = c.id) AS replies_count
                               FROM comentarios c
                               LEFT JOIN usuarios u ON u.id = c.usuario_id
                               WHERE c.id = :id LIMIT 1');
        $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $comment = $stmt->fetch();
        if (!$comment) {
            json_error(404, 'Comentario no encontrado.');
        }

        $comment['respuestas'] = fetch_nested_replies($pdo, (int)$comment['id'], $viewerId);
        json_response(200, ['success' => true, 'data' => $comment]);
    }

    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    if ($page === false || $page === null || $page < 1) {
        $page = 1;
    }
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                  u.username, u.avatar,
                                  (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'like\') AS likes,
                                  (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'dislike\') AS dislikes,
                                  (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = :viewer_id LIMIT 1) AS viewer_vote,
                                  (SELECT COUNT(*) FROM comentarios r WHERE r.parent_id = c.id) AS replies_count
                           FROM comentarios c
                           LEFT JOIN usuarios u ON u.id = c.usuario_id
                           WHERE c.parent_id IS NULL
                           ORDER BY c.created_at DESC
                           LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':viewer_id', $viewerId, PDO::PARAM_INT);
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
}

// --------------------- POST: crear comentario o respuesta ---------------------
if ($method === 'POST') {
    $data = request_body();
    $userId = authenticate_api_request($pdo, $data, true);

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
        $checkParent = $pdo->prepare('SELECT id FROM comentarios WHERE id = :pid LIMIT 1');
        $checkParent->execute([':pid' => $parentIdVal]);
        if (!$checkParent->fetch()) {
            json_error(404, 'El comentario al que intentas responder no existe.');
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
    $userId = authenticate_api_request($pdo, $data, false);

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
    $userId = authenticate_api_request($pdo, [], false);

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

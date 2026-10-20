<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../csrf.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
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

// ------------------------------------------------------------
// Nota sobre CORS (R8): la API y el frontend comparten el mismo
// origen (InfinityFree), por lo que NO se usa
// Access-Control-Allow-Origin: *. Las peticiones cross-origin
// desde el PWA/Android se resuelven por ser el mismo dominio.
// ------------------------------------------------------------

// --------------------- GET: listar o obtener uno ---------------------
if ($method === 'GET') {
    if ($id !== false && $id !== null && $id >= 1) {
        $stmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                      u.username, u.avatar
                               FROM comentarios c
                               LEFT JOIN usuarios u ON u.id = c.usuario_id
                               WHERE c.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $comment = $stmt->fetch();
        if (!$comment) {
            json_error(404, 'Comentario no encontrado.');
        }
        json_response(200, ['success' => true, 'data' => $comment]);
    }

    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    if ($page === false || $page === null || $page < 1) {
        $page = 1;
    }
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $viewerId = is_logged_in() ? (int)$_SESSION['user_id'] : 0;
    $stmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at,
                                  u.username, u.avatar,
                                  (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'like\') AS likes,
                                  (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'dislike\') AS dislikes,
                                  (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = :viewer_id LIMIT 1) AS viewer_vote
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

// --------------------- POST: crear ---------------------
if ($method === 'POST') {
    if (!is_logged_in()) {
        json_error(401, 'Debes iniciar sesión para crear un comentario.');
    }

    $data = request_body();
    $token = $_POST['csrf_token'] ?? ($data['csrf_token'] ?? null);
    if (!csrf_valid($token)) {
        json_error(403, 'Token CSRF inválido.');
    }

    $contenido = trim((string)($data['contenido'] ?? ''));

    if ($contenido === '' || mb_strlen($contenido, 'UTF-8') < 1 || !mb_check_encoding($contenido, 'UTF-8') || mb_strlen($contenido, 'UTF-8') > 5000) {
        json_error(422, 'El comentario debe tener entre 1 y 5000 caracteres válidos.');
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO comentarios (usuario_id, contenido, created_at, updated_at)
                               VALUES (:usuario_id, :contenido, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->execute([
            ':usuario_id' => (int)$_SESSION['user_id'],
            ':contenido' => $contenido,
        ]);
        $newId = (int)$pdo->lastInsertId();
        json_response(201, [
            'success' => true,
            'message' => 'Comentario creado.',
            'data' => ['id' => $newId],
        ]);
    } catch (PDOException $e) {
        error_log('API create comment failed: ' . $e->getMessage());
        json_error(500, 'Error del servidor al crear el comentario.');
    }
}

// --------------------- PUT: modificar ---------------------
if ($method === 'PUT') {
    if (!is_logged_in()) {
        json_error(401, 'Debes iniciar sesión para editar un comentario.');
    }
    if ($id === false || $id === null || $id < 1) {
        json_error(400, 'ID de comentario requerido.');
    }

    $check = $pdo->prepare('SELECT usuario_id FROM comentarios WHERE id = :id LIMIT 1');
    $check->execute([':id' => $id]);
    $comment = $check->fetch();
    if (!$comment) {
        json_error(404, 'Comentario no encontrado.');
    }
    if ((int)$comment['usuario_id'] !== (int)$_SESSION['user_id']) {
        json_error(403, 'No tienes permiso para editar este comentario.');
    }

    $data = request_body();
    $contenido = trim((string)($data['contenido'] ?? ''));
    if ($contenido === '') {
        json_error(422, 'El contenido no puede estar vacío.');
    }

    try {
        $update = $pdo->prepare('UPDATE comentarios SET contenido = :contenido, updated_at = UTC_TIMESTAMP()
                                 WHERE id = :id AND usuario_id = :usuario_id');
        $update->execute([
            ':contenido' => $contenido,
            ':id' => $id,
            ':usuario_id' => (int)$_SESSION['user_id'],
        ]);
        json_response(200, ['success' => true, 'message' => 'Comentario actualizado.']);
    } catch (PDOException $e) {
        error_log('API update comment failed: ' . $e->getMessage());
        json_error(500, 'Error del servidor al actualizar el comentario.');
    }
}

// --------------------- DELETE: borrar ---------------------
if ($method === 'DELETE') {
    if (!is_logged_in()) {
        json_error(401, 'Debes iniciar sesión para eliminar un comentario.');
    }
    if ($id === false || $id === null || $id < 1) {
        json_error(400, 'ID de comentario requerido.');
    }

    $check = $pdo->prepare('SELECT usuario_id FROM comentarios WHERE id = :id LIMIT 1');
    $check->execute([':id' => $id]);
    $comment = $check->fetch();
    if (!$comment) {
        json_error(404, 'Comentario no encontrado.');
    }
    if ((int)$comment['usuario_id'] !== (int)$_SESSION['user_id']) {
        json_error(403, 'No tienes permiso para eliminar este comentario.');
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM comentarios WHERE id = :id AND usuario_id = :usuario_id');
        $stmt->execute([
            ':id' => $id,
            ':usuario_id' => (int)$_SESSION['user_id'],
        ]);
        json_response(200, ['success' => true, 'message' => 'Comentario eliminado.']);
    } catch (PDOException $e) {
        error_log('API delete comment failed: ' . $e->getMessage());
        json_error(500, 'Error del servidor al eliminar el comentario.');
    }
}

json_error(405, 'Método no permitido.');

<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

function action_success(string $message): void
{
    $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
    redirect('index.php');
}

function action_error(string $message): void
{
    $_SESSION['flash'] = ['type' => 'error', 'message' => $message];
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

if (!is_logged_in()) {
    action_error('Debes iniciar sesión para realizar esta acción.');
}

$action = (string)($_POST['action'] ?? '');

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    action_error('Token CSRF inválido.');
}

// Estas acciones llegan desde formularios del mismo sitio, no únicamente
// desde fetch. La validación también funciona cuando el navegador no envía
// Origin/Referer, siempre respaldada por CSRF.
if (!validate_request_origin()) {
    log_security_event('invalid_origin_attempt', "Action: {$action}");
    http_response_code(403);
    exit('Origen no permitido');
}

// Validar Content-Type
if (!validate_content_type(['application/x-www-form-urlencoded', 'multipart/form-data', 'application/json'])) {
    log_security_event('invalid_content_type', "Action: {$action}");
    http_response_code(415);
    exit('Content-Type no soportado');
}

$userId = (int)$_SESSION['user_id'];
$commentId = filter_var($_POST['comentario_id'] ?? null, FILTER_VALIDATE_INT);

if ($commentId === false || $commentId === null || $commentId < 1) {
    action_error('Comentario no válido.');
}

$commentStmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, u.username
                              FROM comentarios c
                              LEFT JOIN usuarios u ON u.id = c.usuario_id
                              WHERE c.id = :id LIMIT 1');
$commentStmt->execute([':id' => $commentId]);
$comment = $commentStmt->fetch();

if (!$comment) {
    action_error('Comentario no encontrado.');
}

function add_notification(PDO $pdo, int $userId, string $message, int $commentId): void
{
    if ($userId < 1) {
        return;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO notificaciones (usuario_id, comentario_id, mensaje, leido, created_at)
                               VALUES (:usuario_id, :comentario_id, :mensaje, 0, UTC_TIMESTAMP())');
        $stmt->execute([
            ':usuario_id' => $userId,
            ':comentario_id' => $commentId,
            ':mensaje' => $message,
        ]);
    } catch (PDOException $exception) {
        error_log('Notification unavailable: ' . $exception->getMessage());
    }
}

if ($action === 'reply') {
    if ($comment['parent_id'] !== null) {
        action_error('Solo se permite un nivel de respuestas.');
    }

    $content = trim((string)($_POST['contenido'] ?? ''));
    if ($content === '' || strlen($content) > 5000) {
        action_error('La respuesta debe tener entre 1 y 5000 caracteres.');
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO comentarios (usuario_id, parent_id, contenido, created_at, updated_at)
                               VALUES (:usuario_id, :parent_id, :contenido, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->execute([
            ':usuario_id' => $userId,
            ':parent_id' => (int)$commentId,
            ':contenido' => $content,
        ]);
    } catch (PDOException $exception) {
        error_log('Reply insert failed: ' . $exception->getMessage());
        action_error('No se pudo guardar la respuesta. Verifica la estructura de comentarios.');
    }

    if ((int)$comment['usuario_id'] !== $userId) {
        add_notification($pdo, (int)$comment['usuario_id'], (string)$_SESSION['username'] . ' respondió a tu comentario.', (int)$commentId);
    }
    action_success('Respuesta publicada.');
}

if ($action === 'vote') {
    $voteType = (string)($_POST['tipo_voto'] ?? '');
    if (!in_array($voteType, ['like', 'dislike'], true)) {
        action_error('Tipo de voto no válido.');
    }

    $existing = $pdo->prepare('SELECT id, tipo_voto FROM votos WHERE comentario_id = :comentario_id AND usuario_id = :usuario_id LIMIT 1');
    $existing->execute([':comentario_id' => $commentId, ':usuario_id' => $userId]);
    $vote = $existing->fetch();

    if ($vote && $vote['tipo_voto'] === $voteType) {
        $stmt = $pdo->prepare('DELETE FROM votos WHERE id = :id AND usuario_id = :usuario_id');
        $stmt->execute([':id' => (int)$vote['id'], ':usuario_id' => $userId]);
    } elseif ($vote) {
        $stmt = $pdo->prepare('UPDATE votos SET tipo_voto = :tipo_voto, created_at = UTC_TIMESTAMP()
                               WHERE id = :id AND usuario_id = :usuario_id');
        $stmt->execute([
            ':tipo_voto' => $voteType,
            ':id' => (int)$vote['id'],
            ':usuario_id' => $userId,
        ]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO votos (comentario_id, usuario_id, tipo_voto, created_at)
                               VALUES (:comentario_id, :usuario_id, :tipo_voto, UTC_TIMESTAMP())');
        $stmt->execute([
            ':comentario_id' => $commentId,
            ':usuario_id' => $userId,
            ':tipo_voto' => $voteType,
        ]);
        if ((int)$comment['usuario_id'] !== $userId) {
            $label = $voteType === 'like' ? 'Me gusta' : 'No me gusta';
            add_notification($pdo, (int)$comment['usuario_id'], (string)$_SESSION['username'] . ' reaccionó a tu comentario: ' . $label . '.', (int)$commentId);
        }
    }

    action_success('Reacción actualizada.');
}

action_error('Acción no válida.');

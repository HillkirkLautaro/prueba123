<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

function delete_error(string $message): void
{
    $_SESSION['flash'] = ['type' => 'error', 'message' => $message];
    redirect('index.php');
}

if (!is_logged_in()) {
    redirect('login.php');
}

// Solo permitir POST para acciones destructivas
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

if (!validate_request_origin()) {
    log_security_event('invalid_delete_origin', "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    delete_error('Origen no permitido.');
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    log_security_event('csrf_attempt', "Delete attempt");
    delete_error('Token CSRF inválido.');
}

$id = (int)($_POST['id'] ?? 0);

if ($id < 1) {
    delete_error('ID de comentario no válido.');
}

// Verificar que el comentario pertenece al usuario antes de eliminar
$checkStmt = $pdo->prepare('SELECT id, usuario_id, contenido FROM comentarios WHERE id = :id LIMIT 1');
$checkStmt->execute([':id' => $id]);
$comment = $checkStmt->fetch();

if (!$comment) {
    delete_error('Comentario no encontrado.');
}

if ((int)$comment['usuario_id'] !== (int)$_SESSION['user_id']) {
    log_security_event('unauthorized_delete_attempt', "Comment ID: {$id}, User: {$_SESSION['user_id']}");
    delete_error('No tienes permisos para borrar este comentario.');
}

$stmt = $pdo->prepare('DELETE FROM comentarios WHERE id = :id AND usuario_id = :usuario_id');
$result = $stmt->execute([
    ':id' => $id,
    ':usuario_id' => (int)$_SESSION['user_id'],
]);

if (!$result || $stmt->rowCount() === 0) {
    delete_error('No se pudo eliminar el comentario.');
}

$_SESSION['flash'] = ['type' => 'success', 'message' => 'Comentario eliminado.'];
redirect('index.php');

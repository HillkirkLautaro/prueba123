<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

if (!is_logged_in()) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No autenticado']);
        exit;
    }
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare('SELECT id, comentario_id, mensaje, leido, created_at
                               FROM notificaciones
                               WHERE usuario_id = :usuario_id
                               ORDER BY created_at DESC LIMIT 10');
        $stmt->execute([':usuario_id' => (int)$_SESSION['user_id']]);
        $items = $stmt->fetchAll();

        $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = :usuario_id AND leido = 0');
        $unreadStmt->execute([':usuario_id' => (int)$_SESSION['user_id']]);
        $unreadCount = (int)$unreadStmt->fetchColumn();
    } catch (PDOException $exception) {
        $items = [];
        $unreadCount = 0;
        error_log('Notifications unavailable: ' . $exception->getMessage());
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'notifications' => $items,
        'unread_count' => $unreadCount,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Solicitud de notificaciones no válida.'];
    redirect('index.php');
}

try {
    $stmt = $pdo->prepare('UPDATE notificaciones SET leido = 1 WHERE usuario_id = :usuario_id AND leido = 0');
    $stmt->execute([':usuario_id' => (int)$_SESSION['user_id']]);
} catch (PDOException $exception) {
    error_log('Notifications unavailable: ' . $exception->getMessage());
}
redirect('index.php');

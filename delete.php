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

$id = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (int)($_POST['id'] ?? 0)
    : (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        delete_error('Token CSRF inválido.');
    }

    $stmt = $pdo->prepare('DELETE FROM comentarios WHERE id = :id AND usuario_id = :usuario_id');
    $result = $stmt->execute([
        ':id' => $id,
        ':usuario_id' => (int)$_SESSION['user_id'],
    ]);

    if (!$result || $stmt->rowCount() === 0) {
        delete_error('No tienes permisos para borrar este comentario.');
    }

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Comentario eliminado.'];
    redirect('index.php');
}

$stmt = $pdo->prepare('SELECT id, usuario_id, contenido FROM comentarios WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$comment = $stmt->fetch();

if (!$comment) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Comentario no encontrado.'];
    redirect('index.php');
}

if ((int)$comment['usuario_id'] !== (int)$_SESSION['user_id']) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No tienes permisos para borrar este comentario.'];
    redirect('index.php');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Eliminar comentario</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <div class="panel">
            <h1>Eliminar comentario</h1>
            <div class="warning">¿Estás seguro de que deseas borrar este comentario?</div>
            <p><strong><?= e($comment['contenido']); ?></strong></p>
            <form method="post" action="delete.php?id=<?= (int)$id; ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                <div class="actions">
                    <button type="submit">Sí, borrar</button>
                    <a class="btn secondary" href="index.php">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

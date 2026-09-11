<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

if (!is_logged_in()) {
    redirect('login.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT id, usuario_id, contenido FROM comentarios WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$comment = $stmt->fetch();

if (!$comment) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Comentario no encontrado.'];
    redirect('index.php');
}

if ((int)$comment['usuario_id'] !== (int)$_SESSION['user_id']) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No tienes permisos para editar este comentario.'];
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Token CSRF inválido.'];
        redirect('index.php');
    }

    $contenido = trim((string)($_POST['contenido'] ?? ''));
    if ($contenido === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'El contenido no puede estar vacío.'];
        redirect('edit.php?id=' . $id);
    }

    $update = $pdo->prepare('UPDATE comentarios SET contenido = :contenido, updated_at = NOW() WHERE id = :id AND usuario_id = :usuario_id');
    $update->execute([
        ':contenido' => $contenido,
        ':id' => $id,
        ':usuario_id' => (int)$_SESSION['user_id'],
    ]);

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Comentario actualizado.'];
    redirect('index.php');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar comentario</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <div class="panel">
            <h1>Editar comentario</h1>
            <form method="post" action="edit.php?id=<?= (int)$id; ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                <textarea name="contenido" required><?= e($comment['contenido']); ?></textarea>
                <div class="actions">
                    <button type="submit">Guardar cambios</button>
                    <a class="btn secondary" href="index.php">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

// Definimos el SVG Base64 por defecto
$defaultAvatar = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAgMTIwIj48cmVjdCB3aWR0aD0iMTIwIiBoZWlnaHQ9IjEyMCIgZmlsbD0iI2U1ZTdlYiIvPjxjaXJjbGUgY3g9IjYwIiBjeT0iNDYiIHI9IjIyIiBmaWxsPSIjOWNhM2FmIi8+PHBhdGggZD0iTTI0IDEwMGM4LTE4IDI5LTI4IDM2LTI4czI4IDEwIDM2IDI4IiBmaWxsPSIjOWNhM2FmIi8+PC9zdmc+';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_comment') {
    if (!is_logged_in()) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Debes iniciar sesión para comentar.'];
        redirect('login.php');
    }

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Token CSRF inválido.'];
        redirect('index.php');
    }

    $contenido = trim((string)($_POST['contenido'] ?? ''));
    if ($contenido === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'El comentario no puede estar vacío.'];
        redirect('index.php');
    }

    $stmt = $pdo->prepare('INSERT INTO comentarios (usuario_id, contenido, created_at, updated_at) VALUES (:usuario_id, :contenido, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $stmt->execute([
        ':usuario_id' => (int)$_SESSION['user_id'],
        ':contenido' => $contenido,
    ]);

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Comentario publicado.'];
    redirect('index.php');
}

$perPage = 5;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
if ($page === false || $page === null || $page < 1) {
    $page = 1;
}

$totalComments = (int)$pdo->query('SELECT COUNT(*) FROM comentarios')->fetchColumn();
$totalPages = $totalComments > 0 ? (int)ceil($totalComments / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.contenido, c.created_at, c.updated_at, u.username, u.avatar 
                       FROM comentarios c 
                       LEFT JOIN usuarios u ON u.id = c.usuario_id 
                       ORDER BY c.created_at DESC 
                       LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$comments = $stmt->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comentarios</title>
    <link rel="stylesheet" href="styles.css?v=2">
    <script src="timezone.js?v=2" defer></script>
</head>
<body>
    <div class="container">
        <?php if ($flash): ?>
            <div class="flash <?= e($flash['type']); ?>">
                <?= e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="panel">
            <div class="topbar">
            <h1>Comentarios</h1>
            <?php if (is_logged_in()): ?>
                <div>
                    <span class="muted">Hola, <?= e($_SESSION['username']); ?></span>
                    <a class="btn btn-secondary" href="profile.php?id=<?= (int)$_SESSION['user_id']; ?>">Mi perfil</a>
                    <a class="btn btn-secondary" href="logout.php">Cerrar sesión</a>
                </div>
            <?php else: ?>
                <div>
                    <a class="btn btn-secondary" href="login.php">Iniciar sesión</a>
                    <a class="btn btn-secondary" href="register.php">Registrarse</a>
                </div>
            <?php endif; ?>
        </div>

            <?php if (is_logged_in()): ?>
                <form method="post" action="index.php">
                    <input type="hidden" name="action" value="create_comment">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <textarea name="contenido" placeholder="Escribe tu comentario..." required></textarea>
                    <p><button type="submit">Publicar comentario</button></p>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$comments): ?>
            <div class="panel">
                <p class="muted">No hay comentarios aún.</p>
            </div>
        <?php else: ?>
            <?php foreach ($comments as $comment): ?>
                <div class="comment">
                    <div class="comment-header">
                        <div class="user-info">
                            <?php 
                                // Si el avatar está vacío en la BD, se asigna el SVG directamente
                                $avatarPath = !empty($comment['avatar']) ? $comment['avatar'] : $defaultAvatar;
                            ?>
                            <a href="profile.php?id=<?= (int)($comment['usuario_id'] ?? 0); ?>">
                                <img src="<?= e($avatarPath); ?>" alt="Avatar de <?= e($comment['username'] ?? 'Usuario'); ?>" class="avatar-img" onerror="this.onerror=null; this.src='<?= $defaultAvatar; ?>';">
                            </a>
                            <strong>
                                <a class="a-link" href="profile.php?id=<?= (int)($comment['usuario_id'] ?? 0); ?>">
                                    <?= e($comment['username'] ?? 'Usuario eliminado'); ?>
                                </a>
                            </strong>
                        </div>

                        <time class="meta local-datetime" datetime="<?= e(utc_datetime_iso($comment['created_at'])); ?>">
                            <?= e($comment['created_at']); ?>
                        </time>
                    </div>
                    
                    <div><?= nl2br(e($comment['contenido'])); ?></div>

                    <?php if (is_logged_in() && (int)$comment['usuario_id'] === (int)$_SESSION['user_id']): ?>
                        <div class="actions">
                            <a class="btn btn-secondary" href="edit.php?id=<?= (int)$comment['id']; ?>">Editar</a>
                            <a class="btn btn-danger" href="delete.php?id=<?= (int)$comment['id']; ?>">Eliminar</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Paginación de comentarios">
                <?php if ($page > 1): ?>
                    <a class="page-link" href="index.php?page=<?= (int)($page - 1); ?>">Anterior</a>
                <?php else: ?>
                    <span class="page-link disabled">Anterior</span>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="page-link active"><?= (int)$i; ?></span>
                    <?php else: ?>
                        <a class="page-link" href="index.php?page=<?= (int)$i; ?>"><?= (int)$i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-link" href="index.php?page=<?= (int)($page + 1); ?>">Siguiente</a>
                <?php else: ?>
                    <span class="page-link disabled">Siguiente</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</body>
</html>
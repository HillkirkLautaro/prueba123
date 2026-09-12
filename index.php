<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

$defaultAvatar = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAgMTIwIj48cmVjdCB3aWR0aD0iMTIwIiBoZWlnaHQ9IjEyMCIgZmlsbD0iI2U1ZTdlYiIvPjxjaXJjbGUgY3g9IjYwIiBjeT0iNDYiIHI9IjIyIiBmaWxsPSIjOWNhM2FmIi8+PHBhdGggZD0iTTI0IDEwMGM4LTE4IDI5LTI4IDM2LTI4czI4IDEwIDM2IDI4IiBmaWxsPSIjOWNhM2FmIi8+PC9zdmc+';

function render_comment(array $comment, array $replies, string $defaultAvatar): void
{
    $avatarPath = !empty($comment['avatar']) ? (string)$comment['avatar'] : $defaultAvatar;
    $isEdited = (string)$comment['created_at'] !== (string)$comment['updated_at'];
    $viewerVote = (string)($comment['viewer_vote'] ?? '');
    ?>
    <article id="comment-<?= (int)$comment['id']; ?>" class="comment<?= $comment['parent_id'] !== null ? ' comment-reply' : ''; ?>">
        <div class="comment-header">
            <div class="user-info">
                <a href="profile.php?id=<?= (int)$comment['usuario_id']; ?>">
                    <img src="<?= e($avatarPath); ?>" alt="Avatar de <?= e((string)($comment['username'] ?? 'Usuario')); ?>" class="avatar-img">
                </a>
                <strong><a class="a-link" href="profile.php?id=<?= (int)$comment['usuario_id']; ?>"><?= e((string)($comment['username'] ?? 'Usuario eliminado')); ?></a></strong>
            </div>
            <time class="meta local-datetime" datetime="<?= e(utc_datetime_iso($comment['created_at'])); ?>"><?= e((string)$comment['created_at']); ?></time>
        </div>
        <div><?= nl2br(e((string)$comment['contenido'])); ?></div>
        <div class="meta comment-meta">
            <?php if ($isEdited): ?> (editado) <?php endif; ?>
            <span class="likes-count"><?= (int)$comment['likes']; ?> Me gusta</span>
            <span class="dislikes-count"><?= (int)$comment['dislikes']; ?> No me gusta</span>
        </div>
        <?php if (is_logged_in()): ?>
            <div class="actions">
                <form method="post" action="actions.php" class="comment-action-form">
                    <input type="hidden" name="action" value="vote"><input type="hidden" name="comentario_id" value="<?= (int)$comment['id']; ?>"><input type="hidden" name="tipo_voto" value="like"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <button type="submit" class="btn btn-secondary vote-button<?= $viewerVote === 'like' ? ' active-vote' : ''; ?>" data-vote-type="like">Me gusta</button>
                </form>
                <form method="post" action="actions.php" class="comment-action-form">
                    <input type="hidden" name="action" value="vote"><input type="hidden" name="comentario_id" value="<?= (int)$comment['id']; ?>"><input type="hidden" name="tipo_voto" value="dislike"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <button type="submit" class="btn btn-secondary vote-button<?= $viewerVote === 'dislike' ? ' active-vote' : ''; ?>" data-vote-type="dislike">No me gusta</button>
                </form>
                <?php if ($comment['parent_id'] === null): ?><button type="button" class="btn btn-secondary reply-toggle" data-target="reply-<?= (int)$comment['id']; ?>">Responder</button><?php endif; ?>
                <?php if ((int)$comment['usuario_id'] === (int)$_SESSION['user_id']): ?>
                    <button type="button" class="btn btn-secondary edit-toggle" data-target="edit-<?= (int)$comment['id']; ?>">Editar</button>
                    <form method="post" action="delete.php?id=<?= (int)$comment['id']; ?>" class="delete-comment-form">
                        <input type="hidden" name="id" value="<?= (int)$comment['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                        <button type="submit" class="btn btn-danger">Eliminar</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($comment['parent_id'] === null): ?>
                <form method="post" action="actions.php" id="reply-<?= (int)$comment['id']; ?>" class="reply-form comment-action-form" hidden>
                    <input type="hidden" name="action" value="reply"><input type="hidden" name="comentario_id" value="<?= (int)$comment['id']; ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <textarea name="contenido" maxlength="5000" placeholder="Escribe una respuesta..." required></textarea><button type="submit">Publicar respuesta</button>
                </form>
            <?php endif; ?>
            <?php if ((int)$comment['usuario_id'] === (int)$_SESSION['user_id']): ?>
                <form method="post" action="edit.php?id=<?= (int)$comment['id']; ?>" id="edit-<?= (int)$comment['id']; ?>" class="edit-comment-form" hidden>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <textarea name="contenido" maxlength="5000" required><?= e((string)$comment['contenido']); ?></textarea>
                    <button type="submit">Guardar cambios</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <div class="replies-list" data-parent-id="<?= (int)$comment['id']; ?>">
            <?php foreach ($replies as $reply): ?><?php render_comment($reply, [], $defaultAvatar); ?><?php endforeach; ?>
        </div>
    </article>
    <?php
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$notifications = [];
$unreadCount = 0;
if (is_logged_in()) {
    try {
        $notificationStmt = $pdo->prepare('SELECT id, comentario_id, mensaje, leido, created_at FROM notificaciones
                                            WHERE usuario_id = :usuario_id ORDER BY created_at DESC LIMIT 10');
        $notificationStmt->execute([':usuario_id' => (int)$_SESSION['user_id']]);
        $notifications = $notificationStmt->fetchAll();

        $unreadNotificationStmt = $pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = :usuario_id AND leido = 0');
        $unreadNotificationStmt->execute([':usuario_id' => (int)$_SESSION['user_id']]);
        $unreadCount = (int)$unreadNotificationStmt->fetchColumn();
    } catch (PDOException $exception) {
        $notifications = [];
        error_log('Notifications unavailable: ' . $exception->getMessage());
    }
}

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

$totalCommentsStmt = $pdo->prepare('SELECT COUNT(*) FROM comentarios WHERE parent_id IS NULL');
$totalCommentsStmt->execute();
$totalComments = (int)$totalCommentsStmt->fetchColumn();
$totalPages = $totalComments > 0 ? (int)ceil($totalComments / $perPage) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$viewerId = is_logged_in() ? (int)$_SESSION['user_id'] : 0;
$stmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at, u.username, u.avatar,
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

$repliesByParent = [];
if ($comments) {
    $repliesStmt = $pdo->prepare('SELECT c.id, c.usuario_id, c.parent_id, c.contenido, c.created_at, c.updated_at, u.username, u.avatar,
                                         (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'like\') AS likes,
                                         (SELECT COUNT(*) FROM votos v WHERE v.comentario_id = c.id AND v.tipo_voto = \'dislike\') AS dislikes,
                                         (SELECT v.tipo_voto FROM votos v WHERE v.comentario_id = c.id AND v.usuario_id = :viewer_id LIMIT 1) AS viewer_vote
                                  FROM comentarios c LEFT JOIN usuarios u ON u.id = c.usuario_id
                                  WHERE c.parent_id IS NOT NULL ORDER BY c.created_at ASC');
    $repliesStmt->execute([':viewer_id' => $viewerId]);
    foreach ($repliesStmt->fetchAll() as $reply) {
        $repliesByParent[(int)$reply['parent_id']][] = $reply;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: text/html; charset=utf-8');
    foreach ($comments as $comment) {
        render_comment($comment, $repliesByParent[(int)$comment['id']] ?? [], $defaultAvatar);
    }
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comentarios</title>
    <link rel="stylesheet" href="styles.css?v=3">
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
                    <div class="notification-menu">
                        <button type="button" class="notification-bell" id="notificationBell" aria-label="Abrir notificaciones" aria-expanded="false">
                            <span aria-hidden="true">&#128276;</span>
                            <span class="notification-count<?= $unreadCount === 0 ? ' is-hidden' : ''; ?>" id="notificationCount"><?= $unreadCount; ?></span>
                        </button>
                        <div class="notification-dropdown" id="notificationDropdown" hidden>
                            <div class="notification-dropdown-header">
                                <strong>Notificaciones</strong>
                                <span class="muted" id="notificationStatus" aria-live="polite">Actualización automática activa</span>
                            </div>
                            <div id="notificationList">
                                <?php foreach ($notifications as $notification): ?>
                                    <div class="notification-item<?= (int)$notification['leido'] === 0 ? ' unread' : ''; ?>">
                                        <a href="index.php#comment-<?= (int)$notification['comentario_id']; ?>" class="notification-link" data-comment-id="<?= (int)$notification['comentario_id']; ?>"><?= e((string)$notification['mensaje']); ?></a>
                                        <time class="meta local-datetime" datetime="<?= e(utc_datetime_iso($notification['created_at'])); ?>"><?= e((string)$notification['created_at']); ?></time>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$notifications): ?><p class="muted">No tienes notificaciones.</p><?php endif; ?>
                            </div>
                            <form method="post" action="notifications.php" id="readNotificationsForm">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                                <button type="submit" class="btn btn-secondary">Marcar como leídas</button>
                            </form>
                        </div>
                    </div>
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
                <form method="post" action="index.php" id="create-comment-form">
                    <input type="hidden" name="action" value="create_comment">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <textarea name="contenido" placeholder="Escribe tu comentario..." required></textarea>
                    <p><button type="submit">Publicar comentario</button></p>
                </form>
            <?php endif; ?>
        </div>

        <div id="comments-wrapper">
            <?php if (!$comments): ?>
                <div class="panel" id="empty-comments">
                    <p class="muted">No hay comentarios aún.</p>
                </div>
            <?php endif; ?>
            <div id="comments-list"<?= !$comments ? ' hidden' : ''; ?>>
                <?php foreach ($comments as $comment): ?>
                    <?php render_comment($comment, $repliesByParent[(int)$comment['id']] ?? [], $defaultAvatar); ?>
                <?php endforeach; ?>
            </div>
        </div>

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
    <script nonce="<?= e($cspNonce); ?>">
        const bell = document.getElementById('notificationBell');
        const dropdown = document.getElementById('notificationDropdown');
        const notificationCount = document.getElementById('notificationCount');
        const notificationList = document.getElementById('notificationList');
        const notificationStatus = document.getElementById('notificationStatus');

        function formatNotificationDate(dateString) {
            const normalizedDate = String(dateString).replace(' ', 'T');
            const date = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalizedDate)
                ? normalizedDate
                : normalizedDate + 'Z');
            if (Number.isNaN(date.getTime())) {
                return dateString;
            }

            try {
                return new Intl.DateTimeFormat('es-AR', {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                    timeZoneName: 'short'
                }).format(date);
            } catch (error) {
                return date.toLocaleString();
            }
        }

        function renderNotifications(notifications) {
            notificationList.replaceChildren();

            if (!notifications.length) {
                const emptyMessage = document.createElement('p');
                emptyMessage.className = 'muted';
                emptyMessage.textContent = 'No tienes notificaciones.';
                notificationList.append(emptyMessage);
                return;
            }

            notifications.forEach(function (notification) {
                const item = document.createElement('div');
                item.className = 'notification-item' + (Number(notification.leido) === 0 ? ' unread' : '');

                const link = document.createElement('a');
                link.className = 'notification-link';
                link.href = 'index.php#comment-' + encodeURIComponent(notification.comentario_id);
                link.dataset.commentId = notification.comentario_id;
                link.textContent = notification.mensaje;

                const time = document.createElement('time');
                time.className = 'meta local-datetime';
                time.dateTime = String(notification.created_at).replace(' ', 'T') + 'Z';
                time.textContent = formatNotificationDate(notification.created_at);

                item.append(link, time);
                notificationList.append(item);
            });
        }

        let notificationRequestInFlight = false;
        async function refreshNotifications() {
            if (notificationRequestInFlight || document.hidden || !notificationList) {
                return;
            }

            notificationRequestInFlight = true;
            try {
                const response = await fetch('notifications.php', {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const payload = await response.json();
                if (!Array.isArray(payload.notifications) || typeof payload.unread_count !== 'number') {
                    throw new Error('Respuesta de notificaciones inválida');
                }

                renderNotifications(payload.notifications);
                notificationCount.textContent = String(payload.unread_count);
                notificationCount.classList.toggle('is-hidden', payload.unread_count === 0);
                notificationStatus.textContent = 'Actualizado automáticamente';
            } catch (error) {
                // La interfaz inicial sigue disponible si falla momentáneamente la red.
                notificationStatus.textContent = 'No se pudo actualizar; se reintentará';
                console.warn('No se pudieron actualizar las notificaciones.', error);
            } finally {
                notificationRequestInFlight = false;
            }
        }

        if (bell && dropdown && notificationCount && notificationList && notificationStatus) {
            bell.addEventListener('click', function () {
                dropdown.hidden = !dropdown.hidden;
                bell.setAttribute('aria-expanded', String(!dropdown.hidden));
                if (!dropdown.hidden) {
                    refreshNotifications();
                }
            });
            document.addEventListener('click', function (event) {
                if (!event.target.closest('.notification-menu')) {
                    dropdown.hidden = true;
                    bell.setAttribute('aria-expanded', 'false');
                }
            });

            // InfinityFree no permite ejecutar un proceso WebSocket persistente.
            // Un sondeo corto sólo en pestañas visibles ofrece notificaciones sin recargar.
            window.setInterval(refreshNotifications, 8000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    refreshNotifications();
                }
            });
        }

        const commentsList = document.getElementById('comments-list');
        const emptyComments = document.getElementById('empty-comments');
        let commentsRequestInFlight = false;
        let lastCommentsHtml = null;

        function formatDynamicDates(container) {
            container.querySelectorAll('.local-datetime[datetime]').forEach(function (time) {
                time.textContent = formatNotificationDate(time.dateTime);
            });
        }

        async function refreshComments() {
            const activeElement = document.activeElement;
            const isWriting = activeElement && activeElement.matches('textarea, input, select');
            const hasOpenCommentForm = commentsList && commentsList.querySelector('.reply-form:not([hidden]), .edit-comment-form:not([hidden])');
            if (commentsRequestInFlight || document.hidden || !commentsList || isWriting || hasOpenCommentForm) {
                return;
            }

            commentsRequestInFlight = true;
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('ajax', '1');
                const response = await fetch(url.toString(), {
                    headers: { 'Accept': 'text/html' },
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const html = await response.text();
                if (html !== lastCommentsHtml) {
                    commentsList.innerHTML = html;
                    commentsList.hidden = html.trim() === '';
                    if (emptyComments) {
                        emptyComments.hidden = html.trim() !== '';
                    }
                    formatDynamicDates(commentsList);
                    lastCommentsHtml = html;
                }
            } catch (error) {
                // Un error temporal no debe impedir que el usuario siga usando la página.
                console.warn('No se pudieron actualizar los comentarios.', error);
            } finally {
                commentsRequestInFlight = false;
            }
        }

        if (commentsList) {
            window.setInterval(refreshComments, 8000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    refreshComments();
                }
            });
        }

        document.addEventListener('click', function (event) {
            const toggle = event.target.closest('.reply-toggle');
            if (toggle) {
                const form = document.getElementById(toggle.dataset.target);
                if (form) {
                    form.hidden = !form.hidden;
                    form.classList.toggle('is-open', !form.hidden);
                }
            }

            const editToggle = event.target.closest('.edit-toggle');
            if (editToggle) {
                const form = document.getElementById(editToggle.dataset.target);
                if (form) {
                    form.hidden = !form.hidden;
                    form.classList.toggle('is-open', !form.hidden);
                }
            }

        });

    </script>
</body>
</html>

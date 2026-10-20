<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId < 1) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Perfil no válido.'];
    redirect('index.php');
}

$profileUser = $pdo->prepare('SELECT id, username, email, avatar FROM usuarios WHERE id = :id LIMIT 1');
$profileUser->execute([':id' => $userId]);
$profile = $profileUser->fetch();

if (!$profile) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Usuario no encontrado.'];
    redirect('index.php');
}

$statsStmt = $pdo->prepare('SELECT COUNT(DISTINCT c.id) AS comment_count,
                                   COUNT(v.id) AS received_likes
                            FROM comentarios c
                            LEFT JOIN votos v ON v.comentario_id = c.id AND v.tipo_voto = \'like\'
                            WHERE c.usuario_id = :usuario_id');
$statsStmt->execute([':usuario_id' => $userId]);
$stats = $statsStmt->fetch() ?: ['comment_count' => 0, 'received_likes' => 0];

$isOwner = is_logged_in() && (int)$profile['id'] === (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isOwner) {
        log_security_event('unauthorized_profile_update_attempt', "Target User: {$userId}, User: " . ($_SESSION['user_id'] ?? 'guest'));
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'No tienes permisos para editar este perfil.'];
        redirect('profile.php?id=' . $userId);
    }

    if (!validate_request_origin()) {
        log_security_event('invalid_profile_origin', "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Origen no autorizado.'];
        redirect('profile.php?id=' . $userId);
    }

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        log_security_event('csrf_attempt', "Profile update attempt");
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Token CSRF inválido.'];
        redirect('profile.php?id=' . $userId);
    }

    $file = $_FILES['avatar'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se recibió una imagen válida.'];
        redirect('profile.php?id=' . $userId);
    }

    if (!isset($file['tmp_name'], $file['size'])
        || !is_string($file['tmp_name'])
        || !is_uploaded_file($file['tmp_name'])
        || (int)$file['size'] <= 0
        || (int)$file['size'] > 2 * 1024 * 1024) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'La imagen debe pesar menos de 2 MB.'];
        redirect('profile.php?id=' . $userId);
    }

    $mime = false;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = @mime_content_type($file['tmp_name']);
    }

    // Algunos hostings no habilitan Fileinfo; getimagesize también permite
    // validar que el archivo sea una imagen real y conocer su MIME.
    if ($mime === false) {
        $imageInfo = @getimagesize($file['tmp_name']);
        $mime = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : false;
    }

    if ($mime === false) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo detectar el tipo MIME de la imagen.'];
        redirect('profile.php?id=' . $userId);
    }

    $allowedTypes = [
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    if (!isset($allowedTypes[$mime])) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Solo se permiten imágenes PNG, JPG, GIF, WebP o AVIF.'];
        redirect('profile.php?id=' . $userId);
    }

    if (@getimagesize($file['tmp_name']) === false) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'El archivo no contiene una imagen válida.'];
        redirect('profile.php?id=' . $userId);
    }

    $extReal = $allowedTypes[$mime];
    $uploadDir = __DIR__ . '/uploads/avatars';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo crear el directorio de avatars.'];
            redirect('profile.php?id=' . $userId);
        }
    }

    if (!is_writable($uploadDir)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'El directorio de avatars no permite guardar archivos.'];
        redirect('profile.php?id=' . $userId);
    }

    $safeName = 'profile_' . (int)$userId . '_' . bin2hex(random_bytes(16)) . '.' . $extReal;
    $targetPath = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo guardar la imagen.'];
        redirect('profile.php?id=' . $userId);
    }

    $avatarPath = 'uploads/avatars/' . $safeName;
    try {
        $update = $pdo->prepare('UPDATE usuarios SET avatar = :avatar WHERE id = :id LIMIT 1');
        $update->execute([
            ':avatar' => $avatarPath,
            ':id' => $userId,
        ]);
    } catch (PDOException $exception) {
        @unlink($targetPath);
        error_log('Profile avatar update failed: ' . $exception->getMessage());
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo actualizar la foto de perfil.'];
        redirect('profile.php?id=' . $userId);
    }

    // Borrar avatar anterior solo después de actualizar la referencia en la BD.
    if (!empty($profile['avatar'])) {
        $oldFilename = basename((string)$profile['avatar']);
        $oldPath = __DIR__ . '/uploads/avatars/' . $oldFilename;
        if ($oldFilename !== 'default.png' && $oldFilename !== $safeName && file_exists($oldPath)) {
            @unlink($oldPath);
        }
    }

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Foto de perfil actualizada correctamente.'];
    redirect('profile.php?id=' . $userId);
}

$comments = $pdo->prepare('SELECT id, contenido, created_at, updated_at FROM comentarios WHERE usuario_id = :usuario_id ORDER BY created_at DESC');
$comments->execute([':usuario_id' => $userId]);
$userComments = $comments->fetchAll();

$defaultAvatarRelative = 'uploads/avatars/default.png';
$defaultAvatarAbsolute = __DIR__ . '/' . $defaultAvatarRelative;
$defaultSvgDataUri = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" fill="#e5e7eb"/><circle cx="60" cy="46" r="22" fill="#9ca3af"/><path d="M24 100c8-18 29-28 36-28s28 10 36 28" fill="#9ca3af"/></svg>');

// Asignación segura del avatar evitando variables indefinidas y previendo Path Traversal
$avatar = $defaultSvgDataUri;

if (!empty($profile['avatar']) && is_string($profile['avatar'])) {
    $avatarFilename = basename($profile['avatar']);
    $storedAvatarAbsolute = __DIR__ . '/uploads/avatars/' . $avatarFilename;
    
    if (file_exists($storedAvatarAbsolute) && @getimagesize($storedAvatarAbsolute) !== false) {
        $avatar = 'uploads/avatars/' . $avatarFilename;
    } elseif (file_exists($defaultAvatarAbsolute) && @getimagesize($defaultAvatarAbsolute) !== false) {
        $avatar = $defaultAvatarRelative;
    }
} elseif (file_exists($defaultAvatarAbsolute) && @getimagesize($defaultAvatarAbsolute) !== false) {
    $avatar = $defaultAvatarRelative;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Perfil de <?= e($profile['username']); ?></title>
    <link rel="stylesheet" href="styles.css">
    <script src="timezone.js?v=2" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
</head>
<body>
    <div class="container">
        <?php if ($flash): ?>
            <div class="flash <?= e($flash['type']); ?>">
                <?= e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="panel profile-card">
            <p><a class="btn btn-secondary" href="index.php">Volver al menú principal</a></p>
            <div class="profile-header">
                <img class="avatar" src="<?= e($avatar); ?>" alt="Avatar de <?= e($profile['username']); ?>">
                <div>
                    <h1><?= e($profile['username']); ?></h1>
                    <div class="profile-meta">Email: <?= e($profile['email']); ?></div>
                    <div class="profile-meta">Comentarios: <?= (int)$stats['comment_count']; ?> · Me gusta recibidos: <?= (int)$stats['received_likes']; ?></div>
                    <?php if ($isOwner): ?>
                        <div class="profile-meta">Este es tu perfil.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isOwner): ?>
                <form method="post" action="profile.php?id=<?= (int)$userId; ?>" enctype="multipart/form-data" id="avatarForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
                    <div class="form-group">
                        <label for="avatar">Actualizar foto de perfil (PNG, JPG, GIF, WebP o AVIF, máximo 2 MB)</label>
                        <input id="avatar" type="file" name="avatar" accept="image/*" required>
                    </div>

                    <div id="cropperWrap" class="cropper-wrap">
                        <img id="cropperImage" src="" alt="Vista previa para recortar">
                        <div class="crop-actions">
                            <button type="button" id="cropConfirmBtn">Aplicar recorte</button>
                            <button type="button" class="btn btn-secondary" id="cropCancelBtn">Cancelar</button>
                        </div>
                    </div>
                    <p id="gifNotice" class="muted gif-notice" hidden>Los GIF se subirán sin recorte para conservar su animación y formato original.</p>

                    <button type="submit">Subir avatar</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h2>Comentarios de <?= e($profile['username']); ?></h2>
            <?php if (!$userComments): ?>
                <p class="muted">Este usuario aún no tiene comentarios.</p>
            <?php else: ?>
                <?php foreach ($userComments as $comment): ?>
                    <div class="comment">
                        <div class="comment-header">
                            <strong><?= e($profile['username']); ?></strong>
                            <time class="meta local-datetime" datetime="<?= e(utc_datetime_iso($comment['created_at'])); ?>">
                                <?= e($comment['created_at']); ?>
                            </time>
                        </div>
                        <div><?= nl2br(sanitize_html(e($comment['contenido']))); ?></div>
                        <?php if ((string)$comment['created_at'] !== (string)($comment['updated_at'] ?? $comment['created_at'])): ?>
                            <div class="meta">(editado)</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <script nonce="<?= e($cspNonce); ?>">
        document.addEventListener('DOMContentLoaded', function () {
            const fileInput = document.getElementById('avatar');
            const form = document.getElementById('avatarForm');
            const cropperWrap = document.getElementById('cropperWrap');
            const cropperImage = document.getElementById('cropperImage');
            const cropConfirmBtn = document.getElementById('cropConfirmBtn');
            const cropCancelBtn = document.getElementById('cropCancelBtn');

            if (!fileInput || !form || !cropperWrap || !cropperImage || !cropConfirmBtn || !cropCancelBtn) {
                return;
            }

            let cropper = null;
            let originalFile = null;

            function getOutputFormat(file) {
                const formats = {
                    'image/png': { mime: 'image/png', extension: 'png' },
                    'image/jpeg': { mime: 'image/jpeg', extension: 'jpg' },
                    'image/webp': { mime: 'image/webp', extension: 'webp' },
                    'image/avif': { mime: 'image/avif', extension: 'avif' }
                };

                return formats[file.type] || null;
            }

            fileInput.addEventListener('change', function () {
                const file = this.files && this.files[0];
                if (!file) {
                    return;
                }

                originalFile = file;

                if (file.type === 'image/gif') {
                    if (cropper) {
                        cropper.destroy();
                        cropper = null;
                    }
                    cropperWrap.style.display = 'none';
                    document.getElementById('gifNotice').hidden = false;
                    return;
                }

                document.getElementById('gifNotice').hidden = true;

                if (file.size > 2 * 1024 * 1024) {
                    alert('La imagen debe pesar menos de 2 MB.');
                    this.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function (event) {
                    if (typeof Cropper !== 'function') {
                        cropperWrap.style.display = 'none';
                        return;
                    }

                    cropperImage.onload = function () {
                        if (cropper) {
                            cropper.destroy();
                        }

                        cropper = new Cropper(cropperImage, {
                            aspectRatio: 1,
                            viewMode: 1,
                            dragMode: 'move',
                            autoCropArea: 1,
                            responsive: true,
                            scalable: true,
                            zoomable: true,
                            movable: true,
                        });
                    };
                    cropperWrap.style.display = 'block';
                    cropperImage.src = event.target.result;
                };

                reader.readAsDataURL(file);
            });

            cropConfirmBtn.addEventListener('click', function () {
                if (!cropper) {
                    return;
                }

                const canvas = cropper.getCroppedCanvas({
                    width: 512,
                    height: 512,
                    imageSmoothingQuality: 'high'
                });

                const outputFormat = getOutputFormat(originalFile);
                if (!outputFormat) {
                    alert('Este formato no puede conservarse al recortar en este navegador.');
                    return;
                }

                canvas.toBlob(function (blob) {
                    if (!blob || blob.type !== outputFormat.mime) {
                        alert('El navegador no pudo conservar el formato original.');
                        return;
                    }

                    const croppedFile = new File(
                        [blob],
                        'avatar.' + outputFormat.extension,
                        { type: outputFormat.mime }
                    );
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(croppedFile);
                    fileInput.files = dataTransfer.files;

                    cropper.destroy();
                    cropper = null;
                    cropperWrap.style.display = 'none';
                }, outputFormat.mime, outputFormat.mime === 'image/jpeg' ? 0.92 : undefined);
            });

            cropCancelBtn.addEventListener('click', function () {
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }

                cropperWrap.style.display = 'none';
                fileInput.value = '';
                originalFile = null;
            });

            // Se conserva el envío nativo multipart/form-data. Así el avatar
            // funciona incluso si la librería de recorte o la red externa no
            // están disponibles; el servidor muestra el resultado mediante
            // el mensaje flash correspondiente.
        });
    </script>
</body>
</html>

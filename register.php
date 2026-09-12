<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

if (is_logged_in()) {
    redirect('index.php');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Token CSRF inválido.'];
        redirect('register.php');
    }

    if (!verify_hcaptcha((string)($_POST['h-captcha-response'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Completa la verificación anti-bot.'];
        redirect('register.php');
    }
    $usernameRaw = $_POST['username'] ?? null;
    $passwordRaw = $_POST['password'] ?? null;
    $emailRaw = $_POST['email'] ?? null;
    
    if (!is_string($usernameRaw) || !is_string($emailRaw) || !is_string($passwordRaw)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Datos inválidos.'];
        redirect('register.php');
    }

    $username = trim($usernameRaw);
    $email = trim($emailRaw);
    $password = $passwordRaw;

    if (
        !preg_match('/^[A-Za-z0-9_]{3,50}$/', $username) ||
        $email === '' ||
        strlen($email) > 254 ||
        $password === '' ||
        strlen($password) > 72
    ) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Datos de registro inválidos.'];
        redirect('register.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Correo electrónico inválido.'];
        redirect('register.php');
    }

    if (strlen($password) < 8) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'La contraseña debe tener al menos 8 caracteres.'];
        redirect('register.php');
    }

    $check = $pdo->prepare('SELECT id FROM usuarios WHERE username = :username OR email = :email LIMIT 1');
    $check->execute([
        ':username' => $username,
        ':email' => $email,
    ]);

    if ($check->fetch()) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'El usuario o correo ya existen.'];
        redirect('register.php');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password, created_at)
             VALUES (:username, :email, :password, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_BCRYPT),
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'El usuario o correo ya existen.'];
            redirect('register.php');
        }

        throw $exception;
    }

    session_regenerate_id(true);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Registro exitoso. Inicia sesión.'];
    redirect('login.php');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro</title>
    <link rel="stylesheet" href="styles.css">
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
</head>
<body class="auth-shell">
    <div class="card">
        <?php if ($flash): ?>
            <div class="flash <?= e($flash['type']); ?>">
                <?= e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <h1>Registro</h1>
        <form method="post" action="register.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
            <div class="form-group">
                <label for="username">Usuario</label>
                <input id="username" type="text" name="username" maxlength="50" value="<?= e($_POST['username'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" maxlength="100" value="<?= e($_POST['email'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" minlength="8" required>
            </div>
            <div class="h-captcha" data-sitekey="<?= e($hcaptchaSitekey); ?>"></div>
            <button type="submit">Registrarse</button>
        </form>
        <?php if ($googleClientId !== '' && $googleClientSecret !== ''): ?>
            <p><a class="btn btn-secondary" href="google_login.php?return=register">Registrarse con Google</a></p>
        <?php endif; ?>
        <div class="auth-links">
            <a href="login.php">Iniciar sesión</a>
            <a href="index.php">Ver comentarios</a>
        </div>
    </div>
</body>
</html>

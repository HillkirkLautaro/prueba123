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
    if (!validate_request_origin()) {
        log_security_event('invalid_login_origin', "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Origen no permitido.'];
        redirect('login.php');
    }

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        log_security_event('csrf_attempt', "Login attempt");
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Token CSRF inválido.'];
        redirect('login.php');
    }

    if (hcaptcha_is_configured() && !verify_hcaptcha((string)($_POST['h-captcha-response'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Completa la verificación anti-bot.'];
        redirect('login.php');
    }
    
    // Rate limiting para prevenir fuerza bruta
    $rateLimitKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!check_rate_limit($rateLimitKey, 5, 300)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Demasiados intentos. Intenta de nuevo en 5 minutos.'];
        redirect('login.php');
    }
    
    $usernameRaw = $_POST['username'] ?? null;
    $passwordRaw = $_POST['password'] ?? null;

    if (!is_string($usernameRaw) || !is_string($passwordRaw)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Datos inválidos.'];
        redirect('login.php');
    }

    $username = trim($usernameRaw);
    $password = $passwordRaw;

    if (
        !preg_match('/^[A-Za-z0-9_]{3,50}$/', $username) ||
        $password === '' ||
        strlen($password) > 72 // requerido si se conserva PASSWORD_BCRYPT
    ) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Credenciales inválidas.'];
        redirect('login.php');
    }
    $stmt = $pdo->prepare('SELECT id, username, email, password FROM usuarios WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        log_security_event('login_failed', "Username: {$username}");
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Credenciales incorrectas.'];
        redirect('login.php');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['auth_time'] = time();
    $_SESSION['last_activity'] = time();

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Sesión iniciada.'];
    redirect('index.php');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login</title>
    <link rel="stylesheet" href="styles.css">
    <?php if (hcaptcha_is_configured()): ?>
        <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <?php endif; ?>
</head>
<body class="auth-shell">
    <div class="card">
        <?php if ($flash): ?>
            <div class="flash <?= e($flash['type']); ?>">
                <?= e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <h1>Iniciar sesión</h1>
        <form method="post" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()); ?>">
            <div class="form-group">
                <label for="username">Usuario</label>
                <input id="username" type="text" name="username" maxlength="50" value="<?= e($_POST['username'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input id="password" type="password" name="password" required>
            </div>
            <?php if (hcaptcha_is_configured()): ?>
                <div class="h-captcha" data-sitekey="<?= e($hcaptchaSitekey); ?>"></div>
            <?php endif; ?>
            <button type="submit">Entrar</button>
        </form>
        <?php if ($googleClientId !== '' && $googleClientSecret !== ''): ?>
            <p><a class="btn btn-secondary" href="google_login.php?return=login">Continuar con Google</a></p>
        <?php endif; ?>
        <div class="auth-links">
            <a href="register.php">Registrarse</a>
            <a href="index.php">Ver comentarios</a>
        </div>
    </div>
</body>
</html>

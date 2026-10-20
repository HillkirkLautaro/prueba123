<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/src/services/resend.php';

if (is_logged_in()) {
    redirect('index.php');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_request_origin()) {
        log_security_event('invalid_registration_origin', "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Origen no permitido.'];
        redirect('register.php');
    }

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        log_security_event('csrf_attempt', "Registration attempt");
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Token CSRF inválido.'];
        redirect('register.php');
    }

    if (hcaptcha_is_configured() && !verify_hcaptcha((string)($_POST['h-captcha-response'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Completa la verificación anti-bot.'];
        redirect('register.php');
    }

    if (!isset($_POST['accept_terms']) || $_POST['accept_terms'] !== '1') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Debes aceptar los Términos y Condiciones.'];
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

    $check = $pdo->prepare('SELECT username, email FROM usuarios WHERE username = :username OR email = :email LIMIT 1');
    $check->execute([
        ':username' => $username,
        ':email' => $email,
    ]);
    $existing = $check->fetch();

    if ($existing) {
        // Mensaje genérico que no revela qué campo existe
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo completar el registro. Intenta con otros datos.'];
        redirect('register.php');
    }

    $verificationToken = bin2hex(random_bytes(32));
    $verificationTokenHash = hash('sha256', $verificationToken);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password, email_verification_token, email_verification_expires_at, created_at)
             VALUES (:username, :email, :password, :verification_token, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_BCRYPT),
            ':verification_token' => $verificationTokenHash,
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'El usuario o correo ya existen.'];
            redirect('register.php');
        }

        throw $exception;
    }

    $baseUrl = $appUrl !== ''
        ? $appUrl
        : (($isHttps ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'));
    $verificationUrl = $baseUrl . '/verify_email.php?token=' . rawurlencode($verificationToken);
    $verificationSent = false;
    if (resend_is_configured()) {
        $verificationEmail = resend_send_verification_email($email, $username, $verificationUrl);
        $verificationSent = ($verificationEmail['success'] ?? false) === true;
        if (!$verificationSent) {
            error_log('Verification email unavailable: ' . ($verificationEmail['error'] ?? 'error desconocido'));
        }
    }

    if (!$verificationSent) {
        // Resend es opcional: no bloquear el uso normal si no está configurado
        // o si su servicio presenta una falla temporal.
        $activate = $pdo->prepare(
            'UPDATE usuarios
             SET email_verified_at = UTC_TIMESTAMP(),
                 email_verification_token = NULL,
                 email_verification_expires_at = NULL
             WHERE username = :username AND email_verified_at IS NULL'
        );
        $activate->execute([':username' => $username]);
    }

    session_regenerate_id(true);
    $_SESSION['flash'] = [
        'type' => 'success',
        'message' => $verificationSent
            ? 'Registro exitoso. Revisa tu correo para verificar la cuenta antes de iniciar sesión.'
            : 'Registro exitoso. La verificación por correo no está disponible; tu cuenta fue habilitada.',
    ];
    redirect('login.php');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro</title>
    <script nonce="<?= e($cspNonce); ?>">if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark-mode');</script>
    <link rel="stylesheet" href="styles.css?v=6">
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
            <div class="form-group checkbox-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <span>Acepto los <a href="terminos.php" target="_blank">Términos y Condiciones</a> y la <a href="privacidad.php" target="_blank">Política de Privacidad</a></span>
                </label>
            </div>
            <?php if (hcaptcha_is_configured()): ?>
                <div class="h-captcha" data-sitekey="<?= e($hcaptchaSitekey); ?>"></div>
            <?php endif; ?>
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

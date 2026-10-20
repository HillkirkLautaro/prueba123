<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'El enlace de verificación no es válido.'];
    redirect('login.php');
}

$tokenHash = hash('sha256', $token);

try {
    $stmt = $pdo->prepare(
        'UPDATE usuarios
         SET email_verified_at = UTC_TIMESTAMP(),
             email_verification_token = NULL,
             email_verification_expires_at = NULL
         WHERE email_verification_token = :token
           AND email_verification_expires_at >= UTC_TIMESTAMP()
           AND email_verified_at IS NULL'
    );
    $stmt->execute([':token' => $tokenHash]);

    if ($stmt->rowCount() !== 1) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'El enlace de verificación es inválido, ya fue usado o venció.'];
        redirect('login.php');
    }
} catch (PDOException $exception) {
    error_log('Email verification failed: ' . $exception->getMessage());
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo verificar el correo. Inténtalo nuevamente más tarde.'];
    redirect('login.php');
}

$_SESSION['flash'] = ['type' => 'success', 'message' => 'Correo verificado correctamente. Ya puedes iniciar sesión.'];
redirect('login.php');

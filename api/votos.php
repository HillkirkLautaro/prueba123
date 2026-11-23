<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $override = strtoupper(trim((string)$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']));
    if (in_array($override, ['PUT', 'DELETE'], true)) {
        $method = $override;
    }
}

function json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function json_error(int $code, string $message, ?array $extra = null): void
{
    $body = ['success' => false, 'error' => $message];
    if ($extra !== null) {
        $body = array_merge($body, $extra);
    }
    json_response($code, $body);
}

function request_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return $_POST;
}

function extract_bearer_token(): string
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');

    if ($authHeader === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $key => $val) {
                if (strcasecmp((string)$key, 'Authorization') === 0 || strcasecmp((string)$key, 'X-Auth-Token') === 0) {
                    $authHeader = (string)$val;
                    break;
                }
            }
        }
    }

    $trimmed = trim((string)$authHeader);
    if (preg_match('/^Bearer\s+(.+)$/i', $trimmed, $matches)) {
        return trim($matches[1]);
    }
    return $trimmed;
}

function verify_google_token_payload(string $idToken): ?array
{
    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);

    if (function_exists('curl_init')) {
        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (is_string($resp) && $resp !== '') {
            $decoded = json_decode($resp, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 6,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $resp = @file_get_contents($verifyUrl, false, $ctx);
    $decoded = is_string($resp) ? json_decode($resp, true) : null;
    return is_array($decoded) ? $decoded : null;
}

function resolve_authenticated_user_id(PDO $pdo, array $bodyData = []): int
{
    $idToken = extract_bearer_token();

    if ($idToken !== '') {
        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $idToken)) {
            json_error(401, 'Formato de token de autenticación inválido.');
        }

        $payload = verify_google_token_payload($idToken);
        $emailVerified = $payload['email_verified'] ?? false;
        $isVerified = ($emailVerified === true || $emailVerified === 'true' || $emailVerified === 1 || $emailVerified === '1');

        if (!is_array($payload) || empty($payload['email']) || !$isVerified) {
            json_error(401, 'Token de Google inválido o expirado.');
        }

        $email = mb_substr(trim((string)$payload['email']), 0, 254);
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            return (int)$user['id'];
        }

        $baseUser = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]);
        if ($baseUser === '') {
            $baseUser = 'usuario';
        }
        $username = substr($baseUser, 0, 42) . '_' . random_int(1000, 9999);
        $randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $avatar = (isset($payload['picture']) && is_string($payload['picture']) && filter_var($payload['picture'], FILTER_VALIDATE_URL))
            ? substr($payload['picture'], 0, 500)
            : null;

        $ins = $pdo->prepare('INSERT INTO usuarios (username, email, password, avatar, email_verified_at)
                              VALUES (:u, :e, :p, :a, UTC_TIMESTAMP())');
        $ins->execute([
            ':u' => $username,
            ':e' => $email,
            ':p' => $randomPass,
            ':a' => $avatar,
        ]);
        return (int)$pdo->lastInsertId();
    }

    if (function_exists('is_logged_in') && is_logged_in()) {
        $token = $_POST['csrf_token'] ?? ($bodyData['csrf_token'] ?? null);
        if (!csrf_valid($token)) {
            json_error(403, 'Token CSRF inválido.');
        }
        return (int)$_SESSION['user_id'];
    }

    json_error(401, 'Debes iniciar sesión para votar.');
    return 0;
}

if (!in_array($method, ['POST', 'PUT'], true)) {
    json_error(405, 'Método HTTP no permitido. Usa POST o PUT.');
}

try {
    $data = request_body();
    $userId = resolve_authenticated_user_id($pdo, $data);

    $comentarioIdRaw = $data['comentario_id'] ?? ($_GET['comentario_id'] ?? null);
    $comentarioId = filter_var($comentarioIdRaw, FILTER_VALIDATE_INT);
    if ($comentarioId === false || $comentarioId === null || $comentarioId < 1) {
        json_error(422, 'El ID de comentario es inválido.');
    }

    $tipoVotoRaw = $data['tipo_voto'] ?? null;
    if (!is_string($tipoVotoRaw) || !in_array($tipoVotoRaw, ['like', 'dislike'], true)) {
        json_error(422, 'El tipo de voto debe ser "like" o "dislike".');
    }
    $tipoVoto = $tipoVotoRaw;

    $rateLimitKey = 'vote_' . $userId;
    if (!check_rate_limit($rateLimitKey, 30, 60)) {
        json_error(429, 'Demasiadas acciones de voto. Espera unos segundos.');
    }

    // Verificar que el comentario o respuesta exista
    $checkComment = $pdo->prepare('SELECT id, usuario_id FROM comentarios WHERE id = :cid LIMIT 1');
    $checkComment->execute([':cid' => $comentarioId]);
    $targetComment = $checkComment->fetch();
    if (!$targetComment) {
        json_error(404, 'Comentario no encontrado.');
    }

    $pdo->beginTransaction();

    $existingStmt = $pdo->prepare('SELECT id, tipo_voto FROM votos WHERE comentario_id = :cid AND usuario_id = :uid LIMIT 1 FOR UPDATE');
    $existingStmt->execute([
        ':cid' => $comentarioId,
        ':uid' => $userId,
    ]);
    $existingVote = $existingStmt->fetch();

    $viewerVote = null;
    $actionPerformed = '';

    if ($existingVote) {
        if ($existingVote['tipo_voto'] === $tipoVoto) {
            // Mismo voto -> quitar (toggle off)
            $del = $pdo->prepare('DELETE FROM votos WHERE id = :vid');
            $del->execute([':vid' => (int)$existingVote['id']]);
            $viewerVote = null;
            $actionPerformed = 'removed';
        } else {
            // Voto opuesto -> actualizar
            $upd = $pdo->prepare('UPDATE votos SET tipo_voto = :tipo WHERE id = :vid');
            $upd->execute([
                ':tipo' => $tipoVoto,
                ':vid' => (int)$existingVote['id'],
            ]);
            $viewerVote = $tipoVoto;
            $actionPerformed = 'updated';
        }
    } else {
        // Sin voto previo -> insertar
        $ins = $pdo->prepare('INSERT INTO votos (comentario_id, usuario_id, tipo_voto) VALUES (:cid, :uid, :tipo)');
        $ins->execute([
            ':cid' => $comentarioId,
            ':uid' => $userId,
            ':tipo' => $tipoVoto,
        ]);
        $viewerVote = $tipoVoto;
        $actionPerformed = 'created';
    }

    $countsStmt = $pdo->prepare("SELECT
                                    COALESCE(SUM(CASE WHEN tipo_voto = 'like' THEN 1 ELSE 0 END), 0) AS likes,
                                    COALESCE(SUM(CASE WHEN tipo_voto = 'dislike' THEN 1 ELSE 0 END), 0) AS dislikes
                                 FROM votos
                                 WHERE comentario_id = :cid");
    $countsStmt->execute([':cid' => $comentarioId]);
    $counts = $countsStmt->fetch() ?: ['likes' => 0, 'dislikes' => 0];

    $pdo->commit();

    json_response(200, [
        'success' => true,
        'action' => $actionPerformed,
        'data' => [
            'comentario_id' => $comentarioId,
            'likes' => (int)$counts['likes'],
            'dislikes' => (int)$counts['dislikes'],
            'viewer_vote' => $viewerVote,
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('API votos error: ' . $e->getMessage());
    json_error(500, 'Error del servidor al procesar el voto.');

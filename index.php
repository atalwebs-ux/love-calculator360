<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function loadEnvFile(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $trimmed, 2);
        $key = trim($key);
        $val = trim($val);
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

loadEnvFile(__DIR__ . '/.env');

function envVal(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return (string)$v;
    }
    if (!empty($_ENV[$key])) {
        return (string)$_ENV[$key];
    }
    if (!empty($_SERVER[$key])) {
        return (string)$_SERVER[$key];
    }
    return $default;
}

define('DB_HOST', envVal('DB_HOST', 'localhost'));
define('DB_PORT', envVal('DB_PORT', '3306'));
define('DB_NAME', envVal('DB_NAME', 'u785941294_lovecal'));
define('DB_USER', envVal('DB_USER', 'u785941294_lovecal'));
define('DB_PASS', envVal('DB_PASSWORD', ''));
define('JWT_SECRET', envVal('JWT_SECRET', 'love_calculator_fallback_change_me'));
define(
    'GOOGLE_WEB_CLIENT_ID',
    envVal('GOOGLE_WEB_CLIENT_ID', '690786932656-oiachl1n0b11dgnl2hk85coebk160jsq.apps.googleusercontent.com')
);

function jsonResponse(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function signJwt(array $payload): string {
    $header = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload['exp'] = time() + (30 * 24 * 60 * 60);
    $body = base64UrlEncode(json_encode($payload));
    $sig = base64UrlEncode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function verifyJwt(?string $token): ?array {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64UrlEncode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64UrlDecode($body), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;
    return $payload;
}

function requireAuth(): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        jsonResponse(401, ['error' => 'Access token required']);
    }
    $user = verifyJwt(trim($m[1]));
    if (!$user || !isset($user['id'])) {
        jsonResponse(403, ['error' => 'Invalid or expired token']);
    }
    return $user;
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (DB_PASS === '') {
        throw new RuntimeException('Missing DB_PASSWORD. Set environment variables or create a .env file on Hostinger.');
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      email VARCHAR(255) NULL UNIQUE,
      phone_number VARCHAR(32) NULL UNIQUE,
      firebase_uid VARCHAR(128) NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS calculations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      feature_type VARCHAR(32) NOT NULL DEFAULT 'love',
      your_name VARCHAR(120) NOT NULL,
      partner_name VARCHAR(120) NOT NULL,
      score INT NOT NULL,
      tier_title VARCHAR(140) NOT NULL,
      details_json TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_calculations_user_id (user_id),
      CONSTRAINT fk_calculations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_predictions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      date_key VARCHAR(16) NOT NULL,
      category VARCHAR(16) NOT NULL,
      mood VARCHAR(80) NOT NULL,
      lucky_color VARCHAR(60) NOT NULL,
      lucky_number INT NOT NULL,
      message TEXT NOT NULL,
      advice TEXT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_date_cat (user_id, date_key, category),
      CONSTRAINT fk_predictions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS favorite_tips (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      tip_id VARCHAR(32) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_tip (user_id, tip_id),
      CONSTRAINT fk_tips_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS game_sessions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      game_id VARCHAR(40) NOT NULL,
      score INT NOT NULL,
      total_questions INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_games_user_id (user_id),
      CONSTRAINT fk_games_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    return $pdo;
}

try {
    $pdo = getDb();
} catch (Throwable $e) {
    jsonResponse(500, ['status' => 'error', 'database' => $e->getMessage()]);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

if ($method === 'GET' && ($path === '/api/health' || $path === '/')) {
    $row = $pdo->query('SELECT NOW() AS now')->fetch();
    jsonResponse(200, [
        'status' => 'ok',
        'service' => 'Love Calculator 360 MySQL API',
        'database' => 'MySQL Connected',
        'timestamp' => $row['now'] ?? date('c'),
    ]);
}

if ($method === 'POST' && $path === '/api/auth/register') {
    $b = getJsonBody();
    $name = trim((string)($b['name'] ?? ''));
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $password = (string)($b['password'] ?? '');
    $firebaseUid = trim((string)($b['firebaseUid'] ?? ''));
    if (!$name || !$email || !$password) {
        jsonResponse(400, ['error' => 'All fields (name, email, password) are required']);
    }
    if (strlen($password) < 6) {
        jsonResponse(400, ['error' => 'Password must be at least 6 characters']);
    }
    $chk = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $chk->execute([$email]);
    if ($chk->fetch()) {
        jsonResponse(409, ['error' => 'An account with this email already exists']);
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $ins = $pdo->prepare('INSERT INTO users (name, email, firebase_uid, password_hash) VALUES (?, ?, ?, ?)');
    $ins->execute([$name, $email, $firebaseUid !== '' ? $firebaseUid : null, $hash]);
    $id = (int)$pdo->lastInsertId();
    $u = $pdo->prepare('SELECT id, name, email, firebase_uid, created_at FROM users WHERE id = ?');
    $u->execute([$id]);
    $user = $u->fetch();
    $token = signJwt(['id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['name']]);
    jsonResponse(201, [
        'message' => 'Account created successfully!',
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'firebaseUid' => $user['firebase_uid'] ?: null,
            'createdAt' => $user['created_at'],
        ],
    ]);
}

if ($method === 'POST' && $path === '/api/auth/login') {
    $b = getJsonBody();
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $password = (string)($b['password'] ?? '');
    $firebaseUid = trim((string)($b['firebaseUid'] ?? ''));
    if (!$email || !$password) {
        jsonResponse(400, ['error' => 'Email and password are required']);
    }
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(401, ['error' => 'Invalid email or password']);
    }
    if ($firebaseUid !== '' && empty($user['firebase_uid'])) {
        $upd = $pdo->prepare('UPDATE users SET firebase_uid = ? WHERE id = ?');
        $upd->execute([$firebaseUid, $user['id']]);
        $user['firebase_uid'] = $firebaseUid;
    }
    $token = signJwt(['id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['name']]);
    jsonResponse(200, [
        'message' => 'Login successful!',
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'firebaseUid' => $user['firebase_uid'] ?: null,
            'createdAt' => $user['created_at'],
        ],
    ]);
}

if ($method === 'POST' && $path === '/api/auth/google') {
    $b = getJsonBody();
    $idToken = trim((string)($b['idToken'] ?? ''));
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $name = trim((string)($b['name'] ?? ''));

    if ($idToken !== '') {
        $verified = null;
        $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        if (function_exists('curl_init')) {
            $ch = curl_init($verifyUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp && $httpCode === 200) {
                $decoded = json_decode($resp, true);
                if (is_array($decoded) && !empty($decoded['email'])) {
                    $verified = $decoded;
                }
            } elseif ($resp && $httpCode >= 400 && $httpCode < 500) {
                jsonResponse(401, ['error' => 'Invalid Google ID token']);
            }
        }

        if ($verified === null) {
            $parts = explode('.', $idToken);
            if (count($parts) === 3) {
                $payload = json_decode(base64UrlDecode($parts[1]), true);
                if (is_array($payload) && !empty($payload['email'])) {
                    $verified = $payload;
                }
            }
        }

        if (is_array($verified)) {
            $aud = (string)($verified['aud'] ?? '');
            if ($aud !== '' && $aud !== GOOGLE_WEB_CLIENT_ID) {
                jsonResponse(401, ['error' => 'Google token audience mismatch']);
            }
            $email = strtolower(trim((string)$verified['email']));
            if (!$name && !empty($verified['name'])) {
                $name = trim((string)$verified['name']);
            }
        }
    }

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, ['error' => 'Valid email is required for Google authentication']);
    }

    $displayName = $name !== '' ? substr($name, 0, 100) : explode('@', $email)[0];
    $firebaseUid = trim((string)($b['firebaseUid'] ?? ''));

    $st = $pdo->prepare('SELECT id, name, email, firebase_uid, created_at FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user) {
        $ins = $pdo->prepare('INSERT INTO users (name, email, firebase_uid, password_hash) VALUES (?, ?, ?, ?)');
        $ins->execute([$displayName, $email, $firebaseUid !== '' ? $firebaseUid : null, 'GOOGLE_OAUTH_USER']);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT id, name, email, firebase_uid, created_at FROM users WHERE id = ?');
        $st->execute([$id]);
        $user = $st->fetch();
    } elseif ($firebaseUid !== '' && empty($user['firebase_uid'])) {
        $upd = $pdo->prepare('UPDATE users SET firebase_uid = ? WHERE id = ?');
        $upd->execute([$firebaseUid, $user['id']]);
        $user['firebase_uid'] = $firebaseUid;
    }

    $token = signJwt(['id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['name']]);
    jsonResponse(200, [
        'message' => 'Google Sign-In successful!',
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'firebaseUid' => $user['firebase_uid'] ?: null,
            'createdAt' => $user['created_at'],
        ],
    ]);
}

if ($method === 'POST' && $path === '/api/auth/firebase') {
    $b = getJsonBody();
    $firebaseUid = trim((string)($b['firebaseUid'] ?? ''));
    if ($firebaseUid === '') {
        jsonResponse(400, ['error' => 'firebaseUid is required']);
    }
    $phone = !empty($b['phoneNumber']) ? trim((string)$b['phoneNumber']) : null;
    $email = !empty($b['email']) ? strtolower(trim((string)$b['email'])) : null;
    $name = trim((string)($b['name'] ?? ''));
    $displayName = $name !== '' ? substr($name, 0, 100) : ($phone ?: ($email ? explode('@', $email)[0] : 'Love User'));

    $st = $pdo->prepare('SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE firebase_uid = ?');
    $st->execute([$firebaseUid]);
    $user = $st->fetch();

    if (!$user && $phone) {
        $st = $pdo->prepare('SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE phone_number = ?');
        $st->execute([$phone]);
        $user = $st->fetch();
    }
    if (!$user && $email) {
        $st = $pdo->prepare('SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch();
    }

    if (!$user) {
        $ins = $pdo->prepare('INSERT INTO users (name, email, phone_number, firebase_uid, password_hash) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$displayName, $email, $phone, $firebaseUid, 'FIREBASE_AUTH_USER']);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE id = ?');
        $st->execute([$id]);
        $user = $st->fetch();
    } else {
        $upd = $pdo->prepare(
            'UPDATE users SET firebase_uid = COALESCE(firebase_uid, ?), phone_number = COALESCE(?, phone_number), email = COALESCE(?, email), name = COALESCE(NULLIF(?, \'\'), name) WHERE id = ?'
        );
        $upd->execute([$firebaseUid, $phone, $email, $displayName, $user['id']]);
        $st = $pdo->prepare('SELECT id, name, email, phone_number, firebase_uid, created_at FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        $user = $st->fetch();
    }

    $token = signJwt(['id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['name']]);
    jsonResponse(200, [
        'message' => 'Firebase user synced with MySQL!',
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'] ?? '',
            'phoneNumber' => $user['phone_number'] ?? null,
            'firebaseUid' => $user['firebase_uid'] ?: $firebaseUid,
            'createdAt' => $user['created_at'],
        ],
    ]);
}

if ($method === 'GET' && $path === '/api/auth/me') {
    $authUser = requireAuth();
    $st = $pdo->prepare('SELECT id, name, email, created_at FROM users WHERE id = ?');
    $st->execute([$authUser['id']]);
    $u = $st->fetch();
    if (!$u) jsonResponse(404, ['error' => 'User not found']);
    jsonResponse(200, [
        'user' => [
            'id' => (int)$u['id'],
            'name' => $u['name'],
            'email' => $u['email'] ?? '',
            'createdAt' => $u['created_at'],
        ],
    ]);
}

if ($method === 'POST' && $path === '/api/calculations') {
    $authUser = requireAuth();
    $b = getJsonBody();
    $yourName = trim((string)($b['your_name'] ?? ''));
    $partnerName = trim((string)($b['partner_name'] ?? ''));
    $score = $b['score'] ?? null;
    if (!$yourName || !$partnerName || $score === null) {
        jsonResponse(400, ['error' => 'Missing calculation details']);
    }
    $featureType = substr((string)($b['feature_type'] ?? 'love'), 0, 32);
    $tierTitle = substr((string)($b['tier_title'] ?? 'Match'), 0, 140);
    $details = isset($b['details_json'])
        ? (is_string($b['details_json']) ? $b['details_json'] : json_encode($b['details_json']))
        : null;

    $ins = $pdo->prepare(
        'INSERT INTO calculations (user_id, feature_type, your_name, partner_name, score, tier_title, details_json) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$authUser['id'], $featureType, $yourName, $partnerName, (int)$score, $tierTitle, $details]);
    $id = (int)$pdo->lastInsertId();
    $row = $pdo->prepare('SELECT * FROM calculations WHERE id = ?');
    $row->execute([$id]);
    jsonResponse(201, ['message' => 'Saved to MySQL history', 'calculation' => $row->fetch()]);
}

if ($method === 'GET' && $path === '/api/calculations') {
    $authUser = requireAuth();
    $st = $pdo->prepare(
        'SELECT id, feature_type, your_name, partner_name, score, tier_title, details_json, created_at FROM calculations WHERE user_id = ? ORDER BY created_at DESC LIMIT 60'
    );
    $st->execute([$authUser['id']]);
    $rows = $st->fetchAll();
    jsonResponse(200, ['calculations' => $rows, 'total' => count($rows)]);
}

if ($method === 'DELETE' && preg_match('#^/api/calculations/(\d+)$#', $path, $m)) {
    $authUser = requireAuth();
    $st = $pdo->prepare('DELETE FROM calculations WHERE id = ? AND user_id = ?');
    $st->execute([(int)$m[1], $authUser['id']]);
    jsonResponse(200, ['message' => 'Calculation deleted successfully']);
}

if ($method === 'POST' && $path === '/api/predictions') {
    $authUser = requireAuth();
    $b = getJsonBody();
    $dateKey = (string)($b['date_key'] ?? '');
    $category = (string)($b['category'] ?? '');
    $message = (string)($b['message'] ?? '');
    if (!$dateKey || !$category || !$message) {
        jsonResponse(400, ['error' => 'Missing daily prediction details']);
    }
    $st = $pdo->prepare(
        'INSERT INTO daily_predictions (user_id, date_key, category, mood, lucky_color, lucky_number, message, advice)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE mood=VALUES(mood), lucky_color=VALUES(lucky_color), lucky_number=VALUES(lucky_number), message=VALUES(message), advice=VALUES(advice)'
    );
    $st->execute([
        $authUser['id'],
        $dateKey,
        $category,
        (string)($b['mood'] ?? ''),
        (string)($b['lucky_color'] ?? ''),
        (int)($b['lucky_number'] ?? 7),
        $message,
        (string)($b['advice'] ?? ''),
    ]);
    jsonResponse(201, ['message' => 'Daily prediction saved to MySQL']);
}

if ($method === 'GET' && $path === '/api/tips/favorites') {
    $authUser = requireAuth();
    $st = $pdo->prepare('SELECT tip_id FROM favorite_tips WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$authUser['id']]);
    $rows = $st->fetchAll();
    jsonResponse(200, ['favorites' => array_column($rows, 'tip_id')]);
}

if ($method === 'POST' && $path === '/api/tips/favorites/toggle') {
    $authUser = requireAuth();
    $b = getJsonBody();
    $tipId = (string)($b['tip_id'] ?? '');
    if (!$tipId) jsonResponse(400, ['error' => 'tip_id is required']);
    $chk = $pdo->prepare('SELECT id FROM favorite_tips WHERE user_id = ? AND tip_id = ?');
    $chk->execute([$authUser['id'], $tipId]);
    $found = $chk->fetch();
    if ($found) {
        $del = $pdo->prepare('DELETE FROM favorite_tips WHERE id = ?');
        $del->execute([$found['id']]);
    } else {
        $ins = $pdo->prepare('INSERT INTO favorite_tips (user_id, tip_id) VALUES (?, ?)');
        $ins->execute([$authUser['id'], $tipId]);
    }
    $st = $pdo->prepare('SELECT tip_id FROM favorite_tips WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$authUser['id']]);
    $rows = $st->fetchAll();
    jsonResponse(200, ['favorites' => array_column($rows, 'tip_id')]);
}

if ($method === 'POST' && $path === '/api/games/sessions') {
    $authUser = requireAuth();
    $b = getJsonBody();
    $gameId = (string)($b['game_id'] ?? '');
    if (!$gameId) jsonResponse(400, ['error' => 'game_id is required']);
    $ins = $pdo->prepare('INSERT INTO game_sessions (user_id, game_id, score, total_questions) VALUES (?, ?, ?, ?)');
    $ins->execute([$authUser['id'], $gameId, (int)($b['score'] ?? 0), (int)($b['total_questions'] ?? 10)]);
    jsonResponse(201, ['message' => 'Game session saved to MySQL', 'id' => (int)$pdo->lastInsertId()]);
}

jsonResponse(404, ['error' => 'Endpoint not found']);

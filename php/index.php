<?php

declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/config.example.php';
}
$config = require $configPath;

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $config['db']['host'],
    $config['db']['port'],
    $config['db']['name']
);

$pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

// Run schema on first start
$tableExists = $pdo->query("SHOW TABLES LIKE 'servers'")->rowCount() > 0;
if (!$tableExists) {
    $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');

// Strip prefix if behind reverse proxy
$prefix = getenv('PATH_PREFIX') ?: '';
if ($prefix && str_starts_with($uri, $prefix)) {
    $uri = substr($uri, strlen($prefix));
}

$rawBody = file_get_contents('php://input');
if (strlen($rawBody) > 65536) {
    json_response(413, ['error' => 'Request body too large']);
}
$body = json_decode($rawBody, true) ?? [];

header('Content-Type: application/json');

$corsAllowlist = ['https://3aka.com', 'https://reg.3aka.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $corsAllowlist, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: https://3aka.com');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response(int $status, mixed $data): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function get_client_ip(): string {
    // Prefer Cloudflare's verified header (cannot be spoofed)
    $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cfIp) return $cfIp;
    // Fallback to REMOTE_ADDR (direct connection)
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function get_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($header, 'Bearer ')) {
        return substr($header, 7);
    }
    return null;
}

function verify_server_token(PDO $pdo, string $domain, ?string $token): void {
    if (!$token) {
        json_response(401, ['error' => 'Missing authorization']);
    }
    $stmt = $pdo->prepare('SELECT server_token, token_expires_at FROM servers WHERE domain = ?');
    $stmt->execute([$domain]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals($row['server_token'], $token)) {
        json_response(401, ['error' => 'Invalid token']);
    }
    if ($row['token_expires_at'] !== null && strtotime($row['token_expires_at']) < time()) {
        json_response(401, ['error' => 'Token expired']);
    }
}

function check_rate_limit(PDO $pdo, string $endpoint, int $maxRequests = 30, int $windowSeconds = 60): void {
    $ip = get_client_ip();
    $stmt = $pdo->prepare('SELECT request_count, window_start FROM rate_limits WHERE ip = ? AND endpoint = ?');
    $stmt->execute([$ip, $endpoint]);
    $row = $stmt->fetch();

    if ($row) {
        $windowStart = strtotime($row['window_start']);
        if ($windowStart !== false && (time() - $windowStart) > $windowSeconds) {
            $pdo->prepare('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL ? SECOND')->execute([$windowSeconds]);
            $stmt = $pdo->prepare('UPDATE rate_limits SET request_count = 1, window_start = NOW() WHERE ip = ? AND endpoint = ?');
            $stmt->execute([$ip, $endpoint]);
            return;
        }
        if ((int)$row['request_count'] >= $maxRequests) {
            json_response(429, ['error' => 'Rate limit exceeded']);
        }
        $stmt = $pdo->prepare('UPDATE rate_limits SET request_count = request_count + 1 WHERE ip = ? AND endpoint = ?');
        $stmt->execute([$ip, $endpoint]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO rate_limits (ip, endpoint) VALUES (?, ?)');
        $stmt->execute([$ip, $endpoint]);
    }
}

// ── Routes ──

// POST /servers - Register
if ($method === 'POST' && $uri === '/servers') {
    check_rate_limit($pdo, 'register', 5, 3600);
    $required = ['domain', 'name', 'apiUrl', 'wsUrl'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            json_response(400, ['error' => "Missing required field: $field"]);
        }
    }

    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $body['domain'])) {
        json_response(400, ['error' => 'Invalid domain format']);
    }

    if (!filter_var($body['apiUrl'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $body['apiUrl'])) {
        json_response(400, ['error' => 'Invalid apiUrl']);
    }
    if (!filter_var($body['wsUrl'], FILTER_VALIDATE_URL) || !preg_match('#^(wss?|https?)://#', $body['wsUrl'])) {
        json_response(400, ['error' => 'Invalid wsUrl']);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM servers WHERE domain = ?');
    $stmt->execute([$body['domain']]);
    if ($stmt->fetch()) {
        json_response(409, ['error' => 'Domain already registered']);
    }

    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare('
        INSERT INTO servers (domain, name, api_url, ws_url, voice_url, voice_provider, cdn_url, protocols, capabilities, description, icon, version, server_token, token_expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL 1 YEAR)
    ');
    $stmt->execute([
        $body['domain'],
        $body['name'],
        $body['apiUrl'],
        $body['wsUrl'],
        $body['voiceUrl'] ?? null,
        $body['voiceProvider'] ?? 'livekit',
        $body['cdnUrl'] ?? null,
        json_encode($body['protocols'] ?? ['zent-v1']),
        json_encode($body['capabilities'] ?? new \stdClass()),
        $body['description'] ?? null,
        $body['icon'] ?? null,
        $body['version'] ?? null,
        $token,
    ]);

    json_response(201, ['domain' => $body['domain'], 'serverToken' => $token]);
}

// GET /servers - List
if ($method === 'GET' && $uri === '/servers') {
    $search = $_GET['search'] ?? '';
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $sortMap = ['user_count' => 'user_count DESC', 'created_at' => 'created_at DESC', 'name' => 'name ASC'];
    $sort = $_GET['sort'] ?? 'user_count';
    if (!is_string($sort)) $sort = 'user_count';
    $orderClause = $sortMap[$sort] ?? 'user_count DESC';

    if ($search) {
        $stmt = $pdo->prepare("
            SELECT domain, name, api_url, ws_url, voice_url, voice_provider, cdn_url, protocols, capabilities, description, icon, user_count, channel_count, verified, status, last_seen, version, created_at
            FROM servers WHERE (name LIKE ? OR domain LIKE ?) ORDER BY $orderClause LIMIT ? OFFSET ?
        ");
        $like = "%$search%";
        $stmt->execute([$like, $like, $limit, $offset]);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM servers WHERE (name LIKE ? OR domain LIKE ?)');
        $countStmt->execute([$like, $like]);
    } else {
        $stmt = $pdo->prepare("
            SELECT domain, name, api_url, ws_url, voice_url, voice_provider, cdn_url, protocols, capabilities, description, icon, user_count, channel_count, verified, status, last_seen, version, created_at
            FROM servers ORDER BY $orderClause LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);

        $countStmt = $pdo->query('SELECT COUNT(*) FROM servers');
    }

    $servers = $stmt->fetchAll();
    foreach ($servers as &$s) {
        $s['protocols'] = json_decode($s['protocols'], true);
        $s['capabilities'] = json_decode($s['capabilities'], true);
        $s['verified'] = (bool)$s['verified'];
        $s['user_count'] = (int)$s['user_count'];
        $s['channel_count'] = (int)$s['channel_count'];
        // Override status to offline if no heartbeat in 5 minutes
        $lastSeen = strtotime($s['last_seen'] ?? '');
        if ($lastSeen !== false && (time() - $lastSeen) > 300) {
            $s['status'] = 'offline';
        }
    }

    json_response(200, ['servers' => $servers, 'total' => (int)$countStmt->fetchColumn()]);
}

// Match /servers/{domain}
if (preg_match('#^/servers/([^/]+)$#', $uri, $m)) {
    $domain = $m[1];

    // GET /servers/:domain
    if ($method === 'GET') {
        $stmt = $pdo->prepare('
            SELECT domain, name, api_url, ws_url, voice_url, voice_provider, cdn_url, protocols, capabilities, description, icon, user_count, channel_count, verified, status, last_seen, version, created_at
            FROM servers WHERE domain = ?
        ');
        $stmt->execute([$domain]);
        $server = $stmt->fetch();
        if (!$server) json_response(404, ['error' => 'Server not found']);

        $server['protocols'] = json_decode($server['protocols'], true);
        $server['capabilities'] = json_decode($server['capabilities'], true);
        $server['verified'] = (bool)$server['verified'];
        $server['user_count'] = (int)$server['user_count'];
        $server['channel_count'] = (int)$server['channel_count'];

        json_response(200, $server);
    }

    // PUT /servers/:domain
    if ($method === 'PUT') {
        verify_server_token($pdo, $domain, get_bearer_token());

        $fields = [];
        $values = [];
        $allowed = [
            'name' => 'name', 'apiUrl' => 'api_url', 'wsUrl' => 'ws_url',
            'voiceUrl' => 'voice_url', 'voiceProvider' => 'voice_provider',
            'cdnUrl' => 'cdn_url', 'description' => 'description',
            'icon' => 'icon', 'userCount' => 'user_count',
            'channelCount' => 'channel_count', 'version' => 'version',
        ];

        foreach ($allowed as $jsonKey => $dbCol) {
            if (array_key_exists($jsonKey, $body)) {
                $fields[] = "$dbCol = ?";
                $values[] = $body[$jsonKey];
            }
        }

        if (!empty($body['protocols'])) {
            $fields[] = 'protocols = ?';
            $values[] = json_encode($body['protocols']);
        }
        if (array_key_exists('capabilities', $body)) {
            $fields[] = 'capabilities = ?';
            $values[] = json_encode($body['capabilities']);
        }

        if (empty($fields)) {
            json_response(400, ['error' => 'No fields to update']);
        }

        $values[] = $domain;
        $sql = 'UPDATE servers SET ' . implode(', ', $fields) . ' WHERE domain = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        json_response(200, ['updated' => true]);
    }

    // DELETE /servers/:domain
    if ($method === 'DELETE') {
        verify_server_token($pdo, $domain, get_bearer_token());
        $stmt = $pdo->prepare('DELETE FROM servers WHERE domain = ?');
        $stmt->execute([$domain]);
        json_response(204, null);
    }
}

// POST /servers/{domain}/heartbeat
if (preg_match('#^/servers/([^/]+)/heartbeat$#', $uri, $m) && $method === 'POST') {
    $domain = $m[1];
    verify_server_token($pdo, $domain, get_bearer_token());

    $updates = ['last_seen = NOW()', "status = 'online'", 'token_expires_at = DATE_ADD(NOW(), INTERVAL 1 YEAR)'];
    $values = [];

    if (isset($body['userCount'])) {
        $updates[] = 'user_count = ?';
        $values[] = (int)$body['userCount'];
    }
    if (isset($body['channelCount'])) {
        $updates[] = 'channel_count = ?';
        $values[] = (int)$body['channelCount'];
    }

    $values[] = $domain;
    $sql = 'UPDATE servers SET ' . implode(', ', $updates) . ' WHERE domain = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    json_response(204, null);
}

// GET /servers/{domain}/health
if (preg_match('#^/servers/([^/]+)/health$#', $uri, $m) && $method === 'GET') {
    $domain = $m[1];
    $stmt = $pdo->prepare('SELECT domain, status, last_seen FROM servers WHERE domain = ?');
    $stmt->execute([$domain]);
    $row = $stmt->fetch();
    if (!$row) json_response(404, ['error' => 'Server not found']);

    // Mark offline if no heartbeat in 5 minutes
    $lastSeen = strtotime($row['last_seen']);
    if ($lastSeen !== false && (time() - $lastSeen) > 300) {
        $row['status'] = 'offline';
    }

    json_response(200, $row);
}

// POST /servers/{domain}/rate
if (preg_match('#^/servers/([^/]+)/rate$#', $uri, $m) && $method === 'POST') {
    $domain = $m[1];
    check_rate_limit($pdo, 'rate', 10, 60);

    // Require server token auth to prevent unauthenticated vote manipulation
    $ratingDomain = $body['fromDomain'] ?? null;
    if (!$ratingDomain) {
        json_response(400, ['error' => 'fromDomain required']);
    }
    verify_server_token($pdo, $ratingDomain, get_bearer_token());

    $stmt = $pdo->prepare('SELECT 1 FROM servers WHERE domain = ?');
    $stmt->execute([$domain]);
    if (!$stmt->fetch()) json_response(404, ['error' => 'Server not found']);

    if (empty($body['userId']) || !isset($body['rating'])) {
        json_response(400, ['error' => 'userId and rating required']);
    }

    if (isset($body['comment']) && strlen($body['comment']) > 2000) {
        json_response(400, ['error' => 'Comment too long (max 2000 characters)']);
    }

    $rating = max(1, min(5, (int)$body['rating']));

    // Upsert — one rating per user per domain
    $stmt = $pdo->prepare('SELECT id FROM ratings WHERE domain = ? AND user_id = ?');
    $stmt->execute([$domain, $body['userId']]);
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare('UPDATE ratings SET rating = ?, comment = ?, created_at = NOW() WHERE domain = ? AND user_id = ?');
        $stmt->execute([$rating, $body['comment'] ?? null, $domain, $body['userId']]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO ratings (domain, user_id, rating, comment) VALUES (?, ?, ?, ?)');
        $stmt->execute([$domain, $body['userId'], $rating, $body['comment'] ?? null]);
    }

    json_response(201, ['created' => true]);
}

// GET /servers/{domain}/ratings
if (preg_match('#^/servers/([^/]+)/ratings$#', $uri, $m) && $method === 'GET') {
    $domain = $m[1];
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $stmt = $pdo->prepare('SELECT id, domain, user_id, rating, comment, created_at FROM ratings WHERE domain = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$domain, $limit, $offset]);
    $ratings = $stmt->fetchAll();

    $avgStmt = $pdo->prepare('SELECT COALESCE(AVG(rating), 0) as avg, COUNT(*) as total FROM ratings WHERE domain = ?');
    $avgStmt->execute([$domain]);
    $agg = $avgStmt->fetch();

    json_response(200, [
        'ratings' => $ratings,
        'averageRating' => round((float)$agg['avg'], 2),
        'total' => (int)$agg['total'],
    ]);
}

// GET /health
if ($uri === '/health' && $method === 'GET') {
    json_response(200, ['status' => 'ok', 'service' => 'registrar']);
}

// 404
json_response(404, ['error' => 'Not found']);

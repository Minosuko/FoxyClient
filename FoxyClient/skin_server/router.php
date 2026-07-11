<?php
/*
 * FoxyClient Local Auth Server
 * Yggdrasil API v1.0 (authlib-injector compatible)
 * Built-in server for local account skin/cape management
 * Uses JWT (RS256) for access tokens
 */

// --- Constants ---
define('DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data');
define('SSL_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'ssl');
define('SKIN_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'textures' . DIRECTORY_SEPARATOR . 'skins');
define('CAPE_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'textures' . DIRECTORY_SEPARATOR . 'capes');
define('ACCOUNTS_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'accounts.json');
define('TOKENS_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'tokens.json');
define('JWT_KEY_FILE', SSL_DIR . DIRECTORY_SEPARATOR . 'jwt_private.key');
define('JWT_PUB_FILE', SSL_DIR . DIRECTORY_SEPARATOR . 'jwt_public.der');

// --- Init ---
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
if (!is_dir(SKIN_DIR)) mkdir(SKIN_DIR, 0777, true);
if (!is_dir(CAPE_DIR)) mkdir(CAPE_DIR, 0777, true);
if (!is_dir(SSL_DIR)) mkdir(SSL_DIR, 0777, true);
if (!file_exists(ACCOUNTS_FILE)) file_put_contents(ACCOUNTS_FILE, '{}');
if (!file_exists(TOKENS_FILE)) file_put_contents(TOKENS_FILE, '{}');

// --- JWT Key Generation ---
define('JWT_PRIVATE_KEY', load_or_generate_jwt_key());

function get_openssl_cnf() {
    static $cnf = null;
    if ($cnf !== null) return $cnf;
    $phpDir = dirname(PHP_BINARY);
    $candidates = [
        $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        $phpDir . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        $phpDir . DIRECTORY_SEPARATOR . 'openssl.cnf',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) { $cnf = $c; return $cnf; }
    }
    // Try OPENSSL_CONF env
    $env = getenv('OPENSSL_CONF');
    if ($env && file_exists($env)) { $cnf = $env; return $cnf; }
    $cnf = '';
    return $cnf;
}

function load_or_generate_jwt_key() {
    if (file_exists(JWT_KEY_FILE)) {
        $key = file_get_contents(JWT_KEY_FILE);
        if (strlen($key) > 50) {
            // Ensure public key PEM file exists and has valid PEM headers
            $pubOk = false;
            if (file_exists(JWT_PUB_FILE)) {
                $pubContent = file_get_contents(JWT_PUB_FILE);
                $pubOk = (strpos($pubContent, '-----BEGIN PUBLIC KEY-----') === 0);
            }
            if (!$pubOk && extension_loaded('openssl') && strpos($key, '-----BEGIN') === 0) {
                $res = @openssl_pkey_get_private($key);
                if ($res) {
                    $details = @openssl_pkey_get_details($res);
                    @openssl_pkey_free($res);
                    if ($details && !empty($details['key'])) {
                        file_put_contents(JWT_PUB_FILE, rtrim(str_replace("\r\n", "\n", $details['key'])));
                    }
                }
            }
            return $key;
        }
    }
    if (!extension_loaded('openssl')) {
        $fallback = bin2hex(random_bytes(64));
        file_put_contents(JWT_KEY_FILE, $fallback);
        return $fallback;
    }
    $cnf = get_openssl_cnf();
    $args = ['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    if ($cnf) $args['config'] = $cnf;
    $res = @openssl_pkey_new($args);
    if (!$res) {
        $fallback = bin2hex(random_bytes(64));
        file_put_contents(JWT_KEY_FILE, $fallback);
        return $fallback;
    }
    $exportArgs = $cnf ? ['config' => $cnf] : [];
    @openssl_pkey_export($res, $privKey, null, $exportArgs);
    file_put_contents(JWT_KEY_FILE, $privKey);

    // Export public key as PEM (authlib-injector expects PEM inside Base64)
    $pubDetails = @openssl_pkey_get_details($res);
    if ($pubDetails && !empty($pubDetails['key'])) {
        $pem = str_replace("\r\n", "\n", $pubDetails['key']);
        file_put_contents(JWT_PUB_FILE, rtrim($pem));
    }

    return $privKey;
}

// --- JWT Functions ---
function jwt_encode($payload, $key = null) {
    if ($key === null) $key = JWT_PRIVATE_KEY;
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $body = base64url_encode(json_encode($payload));
    $signature = '';
    if (extension_loaded('openssl') && strpos($key, '-----BEGIN') === 0) {
        $pkey = @openssl_pkey_get_private($key);
        if ($pkey) {
            @openssl_sign("$header.$body", $signature, $pkey, OPENSSL_ALGO_SHA256);
            @openssl_pkey_free($pkey);
        }
    }
    if (empty($signature)) {
        // Fallback: HMAC-SHA256
        $signature = hash_hmac('sha256', "$header.$body", $key, true);
        $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    }
    return "$header.$body." . base64url_encode($signature);
}

function jwt_decode($token, $key = null) {
    if ($key === null) $key = JWT_PRIVATE_KEY;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    list($h, $b, $s) = $parts;
    $header = json_decode(base64url_decode($h), true);
    $payload = json_decode(base64url_decode($b), true);
    if (!$header || !$payload) return null;
    $alg = $header['alg'] ?? '';
    $sig = base64url_decode($s);
    if ($alg === 'RS256' && extension_loaded('openssl') && strpos($key, '-----BEGIN') === 0) {
        $pubKey = @openssl_pkey_get_public(file_get_contents(JWT_PUB_FILE));
        if (!$pubKey) {
            // Derive public from private
            $priv = @openssl_pkey_get_private($key);
            if ($priv) {
                $details = @openssl_pkey_get_details($priv);
                if ($details && !empty($details['key'])) {
                    $pubKey = @openssl_pkey_get_public($details['key']);
                }
                @openssl_pkey_free($priv);
            }
        }
        if ($pubKey) {
            $ok = @openssl_verify("$h.$b", $sig, $pubKey, OPENSSL_ALGO_SHA256);
            @openssl_pkey_free($pubKey);
            if ($ok !== 1) return null;
        } else return null;
    } elseif ($alg === 'HS256') {
        $expected = hash_hmac('sha256', "$h.$b", $key, true);
        if (!hash_equals($expected, $sig)) return null;
    } else return null;
    return $payload;
}

function jwt_verify($token) {
    $payload = jwt_decode($token);
    if (!$payload) return null;
    if (($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

// --- Helpers ---
function json_read($file) {
    return json_decode(file_get_contents($file), true) ?: [];
}

function json_write($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function uuid3($name) {
    $ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
    $hex = md5(hex2bin(str_replace('-', '', $ns)) . $name);
    // Set version nibble (position 12) to 0 to avoid authlib-injector's
    // isMaskedUUID check (bit 15 must be 0, meaning hex digit 0-7)
    $hex[12] = '0';
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
           substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function uuid_dash($u) {
    $u = str_replace('-', '', $u);
    return substr($u, 0, 8) . '-' . substr($u, 8, 4) . '-' .
           substr($u, 12, 4) . '-' . substr($u, 16, 4) . '-' . substr($u, 20, 12);
}

function uuid_undash($u) {
    return str_replace('-', '', $u);
}

function gen_client_token() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

function get_body() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function error_response($err, $msg, $code = 400) {
    json_response(['error' => $err, 'errorMessage' => $msg], $code);
}

function get_base_url() {
    $s = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 's' : '';
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) $s = $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ? 's' : '';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "http$s://$host";
}

function ensure_account($uuid, $username) {
    $accts = json_read(ACCOUNTS_FILE);
    if (!isset($accts[$uuid])) {
        $accts[$uuid] = [
            'username' => $username,
            'skin_md5' => null,
            'cape_md5' => null,
            'is_slim'  => false,
        ];
        json_write(ACCOUNTS_FILE, $accts);
    }
    return $accts[$uuid];
}

function get_profile($uuid) {
    $accts = json_read(ACCOUNTS_FILE);
    $dashed = uuid_dash($uuid);
    $undashed = uuid_undash($uuid);
    $match = null;
    foreach ($accts as $id => $data) {
        $clean = uuid_undash($id);
        if ($clean === $undashed || $id === $dashed) {
            $match = $data;
            $match['uuid'] = $id;
            $match['uuid_clean'] = $clean;
            break;
        }
    }
    return $match;
}

function sign_textures_payload($value) {
    if (!extension_loaded('openssl')) return null;
    $key = JWT_PRIVATE_KEY;
    if (strpos($key, '-----BEGIN') !== 0) return null;
    $cnf = get_openssl_cnf();
    $pkey = @openssl_pkey_get_private($key);
    if (!$pkey) return null;
    $sig = '';
    @openssl_sign($value, $sig, $pkey, OPENSSL_ALGO_SHA1);
    @openssl_pkey_free($pkey);
    if (empty($sig)) return null;
    return base64_encode($sig);
}

function get_signature_publickey() {
    $key = JWT_PRIVATE_KEY;
    if (!extension_loaded('openssl') || strpos($key, '-----BEGIN') !== 0) return null;
    $pkey = @openssl_pkey_get_private($key);
    if (!$pkey) return null;
    $details = @openssl_pkey_get_details($pkey);
    @openssl_pkey_free($pkey);
    if (!$details || empty($details['key'])) return null;
    $pem = str_replace("\r\n", "\n", $details['key']);
    $pem = rtrim($pem);
    file_put_contents(JWT_PUB_FILE, $pem);
    // Return the PEM string directly — authlib-injector receives it as-is from JSON
    // (PHP's json_encode escapes newlines as \n, Java's JSON parser unescapes them)
    return $pem;
}

function build_textures_json($profile, $baseUrl) {
    $textures = [];
    $uuid = $profile['uuid'];
    $clean = $profile['uuid_clean'];

    if ($profile['skin_md5']) {
        $url = "$baseUrl/textures/skins/{$clean}_skin.png";
    } else {
        $url = "$baseUrl/textures/skins/default" . ($profile['is_slim'] ? '_slim' : '') . "_skin.png";
    }
    $textures['SKIN'] = ['url' => $url];
    if ($profile['is_slim']) {
        $textures['SKIN']['metadata'] = ['model' => 'slim'];
    }
    if ($profile['cape_md5']) {
        $url = "$baseUrl/textures/capes/{$clean}_cape.png";
        $textures['CAPE'] = ['url' => $url];
    }
    return $textures;
}

function build_profile_response($profile, $baseUrl) {
    $clean = $profile['uuid_clean'];
    $value = [
        'timestamp'    => time() * 1000,
        'profileId'    => $clean,
        'profileName'  => $profile['username'],
        'textures'     => build_textures_json($profile, $baseUrl),
    ];
    $encoded = base64_encode(json_encode($value));
    $signature = sign_textures_payload($encoded);
    $prop = [
        'name'  => 'textures',
        'value' => $encoded,
    ];
    if ($signature) {
        $prop['signature'] = $signature;
    }
    return [
        'id'   => $clean,
        'name' => $profile['username'],
        'properties' => [$prop],
    ];
}

function cleanup_expired_tokens() {
    $tokens = json_read(TOKENS_FILE);
    $changed = false;
    foreach ($tokens as $token => $data) {
        if (($data['expires_at'] ?? 0) < time()) {
            unset($tokens[$token]);
            $changed = true;
        }
    }
    if ($changed) json_write(TOKENS_FILE, $tokens);
}

// === ROUTING ===

$method = $_SERVER['REQUEST_METHOD'];
$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uriPath, '/') ?: '/';
$baseUrl = get_base_url();

// Serve static files (CSS, JS, etc.) directly
$filePath = __DIR__ . $uriPath;
if ($uriPath !== '/' && is_file($filePath)) {
    $ext = pathinfo($uriPath, PATHINFO_EXTENSION);
    $mime = [
        'css' => 'text/css',
        'js'  => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
    ][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($filePath);
    exit;
}

// --- CORS ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($method === 'OPTIONS') {
    http_response_code(204); exit;
}

// Cleanup expired tokens periodically
cleanup_expired_tokens();

// ========== API Metadata ==========
if ($uri === '/api' || $uri === '/api/') {
    json_response([
        'meta' => [
            'serverName'            => 'FoxyClient Local',
            'implementationName'    => 'FoxyClientLocal',
            'implementationVersion' => '1.0.0',
            'links' => [
                'homepage' => "$baseUrl/",
            ],
            'feature.non_email_login' => true,
        ],
        'skinDomains' => [parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost'],
        'signaturePublickey' => get_signature_publickey(),
    ]);
}

// ========== Authenticate ==========
if ($uri === '/api/authenticate' && $method === 'POST') {
    $input = get_body();
    $username = trim($input['username'] ?? '');

    if (!$username) {
        error_response('ForbiddenOperationException', 'Invalid credentials.');
    }

    $uuid = uuid3($username);
    ensure_account($uuid, $username);

    $clientToken = $input['clientToken'] ?? gen_client_token();
    $accessToken = jwt_encode([
        'uuid'        => $uuid,
        'username'    => $username,
        'client_token'=> $clientToken,
        'iat'         => time(),
        'exp'         => time() + 86400 * 30,
    ]);
    if (empty($accessToken)) {
        $accessToken = bin2hex(random_bytes(32));
    }

    // Store token metadata for session lookup
    $tokens = json_read(TOKENS_FILE);
    $tokens[$accessToken] = [
        'uuid'        => $uuid,
        'username'    => $username,
        'client_token'=> $clientToken,
        'created_at'  => time(),
        'expires_at'  => time() + 86400 * 30,
    ];
    json_write(TOKENS_FILE, $tokens);

    $profile = get_profile($uuid);
    if (!$profile) {
        error_response('ForbiddenOperationException', 'Profile not found.', 500);
    }

    $resp = [
        'accessToken'       => $accessToken,
        'clientToken'       => $clientToken,
        'availableProfiles' => [
            ['id' => uuid_undash($uuid), 'name' => $username],
        ],
        'selectedProfile'   => ['id' => uuid_undash($uuid), 'name' => $username],
    ];

    if (!empty($input['requestUser'])) {
        $resp['user'] = ['id' => $uuid, 'properties' => []];
    }

    json_response($resp);
}

// ========== Refresh ==========
if ($uri === '/api/refresh' && $method === 'POST') {
    $input = get_body();
    $token = $input['accessToken'] ?? '';
    $clientToken = $input['clientToken'] ?? null;

    // Verify JWT
    $payload = jwt_verify($token);
    if (!$payload) {
        error_response('ForbiddenOperationException', 'Invalid token.');
    }
    if ($clientToken && $payload['client_token'] !== $clientToken) {
        error_response('ForbiddenOperationException', 'Invalid token.');
    }

    $uuid = $payload['uuid'];
    $username = $payload['username'];
    $profile = get_profile($uuid);

    $newToken = jwt_encode([
        'uuid'        => $uuid,
        'username'    => $username,
        'client_token'=> $payload['client_token'],
        'iat'         => time(),
        'exp'         => time() + 86400 * 30,
    ]);

    // Update token store
    $tokens = json_read(TOKENS_FILE);
    unset($tokens[$token]);
    $tokens[$newToken] = [
        'uuid'        => $uuid,
        'username'    => $username,
        'client_token'=> $payload['client_token'],
        'created_at'  => time(),
        'expires_at'  => time() + 86400 * 30,
    ];
    json_write(TOKENS_FILE, $tokens);

    $resp = [
        'accessToken' => $newToken,
        'clientToken' => $payload['client_token'],
        'selectedProfile' => ['id' => uuid_undash($uuid), 'name' => $username],
    ];
    json_response($resp);
}

// ========== Validate ==========
if ($uri === '/api/validate' && $method === 'POST') {
    $input = get_body();
    $token = $input['accessToken'] ?? '';
    $clientToken = $input['clientToken'] ?? null;

    $payload = jwt_verify($token);
    if (!$payload) {
        http_response_code(403); exit;
    }
    if ($clientToken && $payload['client_token'] !== $clientToken) {
        http_response_code(403); exit;
    }
    http_response_code(204); exit;
}

// ========== Invalidate ==========
if ($uri === '/api/invalidate' && $method === 'POST') {
    $input = get_body();
    $token = $input['accessToken'] ?? '';

    $tokens = json_read(TOKENS_FILE);
    if (isset($tokens[$token])) {
        unset($tokens[$token]);
        json_write(TOKENS_FILE, $tokens);
    }
    http_response_code(204); exit;
}

// ========== Signout ==========
if ($uri === '/api/signout' && $method === 'POST') {
    $input = get_body();
    $username = $input['username'] ?? '';

    $tokens = json_read(TOKENS_FILE);
    foreach ($tokens as $tk => $data) {
        if ($data['username'] === $username) {
            unset($tokens[$tk]);
        }
    }
    json_write(TOKENS_FILE, $tokens);
    http_response_code(204); exit;
}

// ========== Join Server ==========
if (preg_match('#^/api/(?:sessionserver/)?session/minecraft/join$#', $uri) && $method === 'POST') {
    $input = get_body();
    if (!$input || !isset($input['accessToken']) || !isset($input['selectedProfile']) || !isset($input['serverId'])) {
        error_response('IllegalArgumentException', 'Access token, selected profile, and server ID are required.');
    }

    $accessToken = $input['accessToken'];
    $selectedProfile = $input['selectedProfile'];
    $serverId = $input['serverId'];

    // Verify JWT
    $payload = jwt_verify($accessToken);
    if (!$payload) {
        error_response('ForbiddenOperationException', 'Invalid token.', 403);
    }

    // Validate selectedProfile matches the token's UUID
    if (uuid_undash($payload['uuid']) !== $selectedProfile) {
        error_response('ForbiddenOperationException', 'Invalid token or profile.', 403);
    }

    $uuid = $payload['uuid'];
    $sessionsFile = DATA_DIR . DIRECTORY_SEPARATOR . 'sessions.json';
    $sessions = json_read($sessionsFile);

    // Remove old sessions for this user
    unset($sessions[$uuid]);

    $sessions[$uuid] = ['server_id' => $serverId, 'ip' => $_SERVER['REMOTE_ADDR'], 'created_at' => time()];
    json_write($sessionsFile, $sessions);

    http_response_code(204); exit;
}

// ========== Has Joined ==========
if (preg_match('#^/api/(?:sessionserver/)?session/minecraft/hasJoined$#', $uri) && $method === 'GET') {
    $username = $_GET['username'] ?? '';
    $serverId = $_GET['serverId'] ?? '';
    $ip = $_GET['ip'] ?? null;

    if (!$username || !$serverId) {
        http_response_code(400); exit;
    }

    $sessionsFile = DATA_DIR . DIRECTORY_SEPARATOR . 'sessions.json';
    $sessions = json_read($sessionsFile);

    $foundUuid = null;
    foreach ($sessions as $uuid => $session) {
        if ($session['server_id'] === $serverId) {
            if ($ip && ($session['ip'] ?? '') !== $ip) {
                continue;
            }
            $foundUuid = $uuid;
            break;
        }
    }

    if (!$foundUuid) {
        // Fallback: look up by username directly (for LAN / offline-mode servers)
        $accts = json_read(ACCOUNTS_FILE);
        foreach ($accts as $uuid => $data) {
            if (($data['username'] ?? '') === $username) {
                $foundUuid = $uuid;
                break;
            }
        }
        if (!$foundUuid) {
            http_response_code(204); exit;
        }
    }

    $profile = get_profile($foundUuid);
    if (!$profile) {
        http_response_code(204); exit;
    }

    json_response(build_profile_response($profile, $baseUrl));
}

// ========== Profile Lookup by Name ==========
if (preg_match('#^/api/minecraft/profile/lookup/name/([^/]+)$#', $uri, $m)) {
    $username = $m[1];
    $accts = json_read(ACCOUNTS_FILE);
    $found = null;
    foreach ($accts as $id => $data) {
        if (strtolower($data['username']) === strtolower($username)) {
            $found = $id; break;
        }
    }
    if (!$found) {
        http_response_code(204); exit;
    }
    $profile = get_profile($found);
    if (!$profile) {
        http_response_code(204); exit;
    }
    json_response(build_profile_response($profile, $baseUrl));
}

// ========== Session Profile ==========
// authlib-injector v1.2.7 QueryProfileFilter constructs URLs from the intercepted
// sessionserver.mojang.com path, producing multiple possible paths:
//   /api/sessionserver/session/minecraft/profile/{uuid}
//   /api/session/minecraft/profile/{uuid}
//   /api/minecraft/profile/{uuid}
if (preg_match('#^/api/(?:sessionserver/)?(?:session/)?minecraft/profile/([^/]+)$#', $uri, $m)) {
    $uuid = $m[1];
    $profile = get_profile($uuid);
    if (!$profile) {
        http_response_code(204); exit;
    }
    json_response(build_profile_response($profile, $baseUrl));
}

// ========== Profiles by UUID/Name ==========
if (preg_match('#^/api/profiles/minecraft/byname/([^/]+)$#', $uri, $m)) {
    $username = $m[1];
    $accts = json_read(ACCOUNTS_FILE);
    $found = null;
    foreach ($accts as $id => $data) {
        if (strtolower($data['username']) === strtolower($username)) {
            $found = $id; break;
        }
    }
    if (!$found) {
        http_response_code(204); exit;
    }
    $profile = get_profile($found);
    if (!$profile) {
        http_response_code(204); exit;
    }
    json_response(build_profile_response($profile, $baseUrl));
}

if (preg_match('#^/api/profiles/minecraft/([^/]+)$#', $uri, $m)) {
    // Handle POST (profile lookup by names)
    if ($method === 'POST') {
        $input = get_body();
        $names = is_array($input) ? $input : [];
        $accts = json_read(ACCOUNTS_FILE);
        $result = [];
        foreach ($accts as $id => $data) {
            if (in_array($data['username'], $names)) {
                $result[] = ['id' => uuid_undash($id), 'name' => $data['username']];
            }
        }
        json_response($result);
        exit;
    }

    $uuid = $m[1];
    $profile = get_profile($uuid);
    if (!$profile) {
        http_response_code(204); exit;
    }
    json_response(build_profile_response($profile, $baseUrl));
}

// ========== Textures API ==========
if ($uri === '/api/textures' && $method === 'GET') {
    $uuid = $_GET['uuid'] ?? '';
    $profile = get_profile($uuid);
    if (!$profile) {
        json_response(['error' => 'Profile not found'], 404);
    }
    json_response(build_textures_json($profile, $baseUrl));
}

// ========== Serve Skin/Cape Files ==========
if (preg_match('#^/textures/skins/([a-f0-9\-]+_skin\.png)$#', $uri, $m)) {
    $file = SKIN_DIR . DIRECTORY_SEPARATOR . $m[1];
    if (!file_exists($file)) {
        // Try default skins
        if (strpos($m[1], 'default_slim') === 0) {
            $file = SKIN_DIR . DIRECTORY_SEPARATOR . "default_skin_slim.png";
        } else {
            $file = SKIN_DIR . DIRECTORY_SEPARATOR . "default_skin.png";
        }
    }
    if (file_exists($file)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($file); exit;
    }
    http_response_code(404); exit;
}

if (preg_match('#^/textures/skins/default(_slim)?\.png$#', $uri, $m)) {
    $slim = !empty($m[1]);
    $file = $slim ? (SKIN_DIR . DIRECTORY_SEPARATOR . "default_skin_slim.png") : (SKIN_DIR . DIRECTORY_SEPARATOR . "default_skin.png");
    if (file_exists($file)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($file); exit;
    }
    http_response_code(404); exit;
}

if (preg_match('#^/textures/capes/([a-f0-9\-]+_cape\.png)$#', $uri, $m)) {
    $file = CAPE_DIR . DIRECTORY_SEPARATOR . $m[1];
    if (file_exists($file)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($file); exit;
    }
    http_response_code(404); exit;
}

// ========== Management Page ==========
if ($uri === '/manage' || $uri === '/manage/') {
    require __DIR__ . DIRECTORY_SEPARATOR . 'manage.php';
    exit;
}

// ========== Management API ==========
if (strpos($uri, '/manage') === 0) {

    function handle_skin_upload($uuid, $tmpPath, $isSlim = false) {
        $profile = get_profile($uuid);
        if (!$profile) return 'Profile not found';

        $info = @getimagesize($tmpPath);
        if (!$info) return 'Invalid image file';
        $w = $info[0]; $h = $info[1];
        if (!(($w === 64 && $h === 64) || ($w === 64 && $h === 32) || ($w === 32 && $h === 32))) {
            return 'Dimensions must be 64x64, 64x32, or 32x32';
        }

        $clean = uuid_undash($uuid);
        $dest = SKIN_DIR . DIRECTORY_SEPARATOR . "{$clean}_skin.png";
        if (copy($tmpPath, $dest)) {
            $md5 = md5_file($dest);
            $accts = json_read(ACCOUNTS_FILE);
            $accts[$profile['uuid']]['skin_md5'] = $md5;
            $accts[$profile['uuid']]['is_slim'] = (bool)$isSlim;
            json_write(ACCOUNTS_FILE, $accts);
            return null;
        }
        return 'Failed to save file';
    }

    function handle_cape_upload($uuid, $tmpPath) {
        $profile = get_profile($uuid);
        if (!$profile) return 'Profile not found';

        $info = @getimagesize($tmpPath);
        if (!$info) return 'Invalid image file';
        if ($info[0] > 128 || $info[1] > 128) return 'Cape too large';

        $clean = uuid_undash($uuid);
        $md5 = md5_file($tmpPath);
        $dest = CAPE_DIR . DIRECTORY_SEPARATOR . "{$clean}_cape.png";
        if (copy($tmpPath, $dest)) {
            $accts = json_read(ACCOUNTS_FILE);
            $accts[$profile['uuid']]['cape_md5'] = $md5;
            json_write(ACCOUNTS_FILE, $accts);
            return null;
        }
        return 'Failed to save file';
    }

    // Upload Skin (multipart)
    if ($uri === '/manage/upload/skin' && $method === 'POST') {
        $uuid = $_POST['uuid'] ?? '';
        if (!$uuid) json_response(['error' => 'Missing uuid'], 400);

        if (!isset($_FILES['skin']) || $_FILES['skin']['error'] !== UPLOAD_ERR_OK) {
            json_response(['error' => 'No file uploaded'], 400);
        }

        $file = $_FILES['skin'];
        if ($file['size'] > 20480) json_response(['error' => 'File exceeds 20KB limit'], 400);
        $pngOk = @file_get_contents($file['tmp_name'], false, null, 0, 8) === "\x89PNG\r\n\x1a\n";
        if (!$pngOk) json_response(['error' => 'Must be PNG'], 400);

        $err = handle_skin_upload($uuid, $file['tmp_name'], !empty($_POST['is_slim']));
        if ($err) json_response(['error' => $err], 400);
        json_response(['success' => true]);
    }

    // Upload Skin (JSON/base64)
    if ($uri === '/manage/upload/skin_json' && $method === 'POST') {
        $input = get_body();
        $uuid = $input['uuid'] ?? '';
        $data = $input['data'] ?? '';
        $isSlim = !empty($input['is_slim']);
        if (!$uuid || !$data) json_response(['error' => 'Missing uuid or data'], 400);

        $bin = base64_decode($data);
        if ($bin === false || strlen($bin) < 100) json_response(['error' => 'Invalid image data'], 400);

        $tmp = tempnam(sys_get_temp_dir(), 'skin_');
        file_put_contents($tmp, $bin);
        $err = handle_skin_upload($uuid, $tmp, $isSlim);
        @unlink($tmp);
        if ($err) json_response(['error' => $err], 400);
        json_response(['success' => true]);
    }

    // Upload Cape (multipart)
    if ($uri === '/manage/upload/cape' && $method === 'POST') {
        $uuid = $_POST['uuid'] ?? '';
        if (!$uuid) json_response(['error' => 'Missing uuid'], 400);

        if (!isset($_FILES['cape']) || $_FILES['cape']['error'] !== UPLOAD_ERR_OK) {
            json_response(['error' => 'No file uploaded'], 400);
        }

        $file = $_FILES['cape'];
        if ($file['size'] > 5120) json_response(['error' => 'File exceeds 5KB limit'], 400);
        $pngOk = @file_get_contents($file['tmp_name'], false, null, 0, 8) === "\x89PNG\r\n\x1a\n";
        if (!$pngOk) json_response(['error' => 'Must be PNG'], 400);

        $err = handle_cape_upload($uuid, $file['tmp_name']);
        if ($err) json_response(['error' => $err], 400);
        json_response(['success' => true]);
    }

    // Upload Cape (JSON/base64)
    if ($uri === '/manage/upload/cape_json' && $method === 'POST') {
        $input = get_body();
        $uuid = $input['uuid'] ?? '';
        $data = $input['data'] ?? '';
        if (!$uuid || !$data) json_response(['error' => 'Missing uuid or data'], 400);

        $bin = base64_decode($data);
        if ($bin === false || strlen($bin) < 100) json_response(['error' => 'Invalid image data'], 400);

        $tmp = tempnam(sys_get_temp_dir(), 'cape_');
        file_put_contents($tmp, $bin);
        $err = handle_cape_upload($uuid, $tmp);
        @unlink($tmp);
        if ($err) json_response(['error' => $err], 400);
        json_response(['success' => true]);
    }

    // Remove Cape
    if ($uri === '/manage/remove/cape' && $method === 'POST') {
        $input = get_body();
        $uuid = $input['uuid'] ?? '';
        if (!$uuid) json_response(['error' => 'Missing uuid'], 400);

        $profile = get_profile($uuid);
        if (!$profile) json_response(['error' => 'Profile not found'], 404);

        $clean = uuid_undash($uuid);
        $capeFile = CAPE_DIR . DIRECTORY_SEPARATOR . "{$clean}_cape.png";
        if (file_exists($capeFile)) unlink($capeFile);

        $accts = json_read(ACCOUNTS_FILE);
        $accts[$profile['uuid']]['cape_md5'] = null;
        json_write(ACCOUNTS_FILE, $accts);
        json_response(['success' => true]);
    }

    // Toggle Slim
    if ($uri === '/manage/toggle/slim' && $method === 'POST') {
        $input = get_body();
        $uuid = $input['uuid'] ?? '';
        if (!$uuid) json_response(['error' => 'Missing uuid'], 400);

        $profile = get_profile($uuid);
        if (!$profile) json_response(['error' => 'Profile not found'], 404);

        $accts = json_read(ACCOUNTS_FILE);
        $accts[$profile['uuid']]['is_slim'] = !$accts[$profile['uuid']]['is_slim'];
        json_write(ACCOUNTS_FILE, $accts);
        json_response(['success' => true, 'is_slim' => $accts[$profile['uuid']]['is_slim']]);
    }

    // Get Profile Info
    if ($uri === '/manage/profile' && $method === 'GET') {
        $uuid = $_GET['uuid'] ?? '';
        if (!$uuid) json_response(['error' => 'Missing uuid'], 400);
        $profile = get_profile($uuid);
        if (!$profile) json_response(['error' => 'Profile not found'], 404);

        $hasSkin = $profile['skin_md5'] !== null && $profile['skin_md5'] !== '';
        $hasCape = $profile['cape_md5'] !== null && $profile['cape_md5'] !== '';

        json_response([
            'uuid'     => $profile['uuid'],
            'username' => $profile['username'],
            'has_skin' => $hasSkin,
            'has_cape' => $hasCape,
            'is_slim'  => $profile['is_slim'],
        ]);
    }

    // List Accounts
    if ($uri === '/manage/accounts' && $method === 'GET') {
        $accts = json_read(ACCOUNTS_FILE);
        $list = [];
        foreach ($accts as $uuid => $data) {
            $list[] = [
                'uuid'     => $uuid,
                'username' => $data['username'],
                'has_skin' => !empty($data['skin_md5']),
                'has_cape' => !empty($data['cape_md5']),
                'is_slim'  => $data['is_slim'],
            ];
        }
        json_response($list);
    }

    json_response(['error' => 'Not found'], 404);
}

// ========== 404 Fallback ==========
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found', 'uri' => $uri]);

<?php
// ================================================
// LoKy ACC — Configuration
// config.php
// ================================================

// ===== BASE DE DONNÉES =====
define('DB_HOST',    'localhost');
define('DB_NAME',    'loky_acc');
define('DB_USER',    'loky_acc');
define('DB_PASS',    'te87XrDdKbiHxPML');
define('DB_CHARSET', 'utf8mb4');

// ===== PARAMÈTRES ACC =====
define('SURPLUS_THRESHOLD', 200);
define('NOTIFY_COOLDOWN',   300);
define('DATA_RETENTION',    30);

// ===== CONNEXION MySQL =====
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'DB error: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ===== CLÉS VAPID DEPUIS LA BASE =====
function getVAPIDConfig(): array {
    static $vapid = null;
    if ($vapid === null) {
        try {
            $db   = getDB();
            $rows = $db->query(
                "SELECT `cle`, `valeur` FROM parametres
                 WHERE `cle` IN ('vapid_public_key','vapid_private_key','vapid_subject')"
            )->fetchAll();
            $vapid = [];
            foreach ($rows as $r) {
                $vapid[$r['cle']] = $r['valeur'];
            }
        } catch (Exception $e) {
            $vapid = [];
        }
    }
    return $vapid;
}

function getVAPIDPublicKey(): string {
    return getVAPIDConfig()['vapid_public_key'] ?? '';
}

function getVAPIDPrivateKey(): string {
    return getVAPIDConfig()['vapid_private_key'] ?? '';
}

function getVAPIDSubject(): string {
    return getVAPIDConfig()['vapid_subject'] ?? 'mailto:admin@loky-acc.fr';
}

// ===== HELPERS =====
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize(string $str): string {
    return htmlspecialchars(strip_tags(trim($str)));
}

// ===== BASE64URL =====
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

// ===== ENVOI PUSH (utilisé partout) =====
function sendPushNotification(array $subscription, array $payload): bool {
    $pubKey  = getVAPIDPublicKey();
    $privKey = getVAPIDPrivateKey();
    $subject = getVAPIDSubject();

    if (!$pubKey || !$privKey) return false;

    $endpoint = $subscription['endpoint'];
    $parsed   = parse_url($endpoint);
    $audience = $parsed['scheme'] . '://' . $parsed['host'];

    // Créer JWT VAPID
    $header  = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload_jwt = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $subject,
    ]));
    $signing = $header . '.' . $payload_jwt;

    $rawPriv = base64url_decode($privKey);
    $der     = "\x30\x77\x02\x01\x01\x04\x20" . $rawPriv
             . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $pem     = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";

    $privKeyObj = openssl_pkey_get_private($pem);
    openssl_sign($signing, $sig, $privKeyObj, OPENSSL_ALGO_SHA256);

    // Convertir DER → raw
    $offset = 2;
    if (ord($sig[$offset]) === 0x81) $offset++;
    $offset++; $rLen = ord($sig[$offset++]);
    $r = substr($sig, $offset, $rLen); $offset += $rLen; $offset++;
    $sLen = ord($sig[$offset++]); $s = substr($sig, $offset, $sLen);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $jwt = $signing . '.' . base64url_encode($r . $s);

    $headers = [
        'Authorization: vapid t=' . $jwt . ', k=' . $pubKey,
        'Content-Type: application/octet-stream',
        'TTL: 86400',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

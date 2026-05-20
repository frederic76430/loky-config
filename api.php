<?php
// ================================================
// LoKy ACC — API principale
// api.php
// Reçoit les données ESP32 + gère Push notifications
// ================================================

require_once 'config.php';

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-LoKy-ID, X-LoKy-ACC');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$route  = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ================================================
// ROUTEUR
// ================================================

switch ($route) {

    // ---- Réception données ESP32 ----
    case '':
        if ($method === 'POST') receiveData();
        else jsonResponse(['status' => 'ok', 'version' => '1.0', 'time' => date('Y-m-d H:i:s')]);
        break;

    // ---- Abonnement Push ----
    case 'subscribe':
        if ($method === 'POST') subscribePush();
        break;

    // ---- Désabonnement Push ----
    case 'unsubscribe':
        if ($method === 'POST') unsubscribePush();
        break;

    // ---- Test notification Push ----
    case 'test_push':
        testPush();
        break;

    // ---- Données dashboard ----
    case 'get_acc_data':
        getAccData();
        break;

    // ---- Liste des ACC ----
    case 'get_acc_list':
        getAccList();
        break;

    // ---- Données admin ----
    case 'get_admin_data':
        getAdminData();
        break;

    // ---- Historique mesures ----
    case 'get_history':
        getHistory();
        break;

    // ---- Créer un ACC ----
    case 'create_acc':
        if ($method === 'POST') createAcc();
        break;

    default:
        jsonResponse(['error' => 'Route inconnue'], 404);
}

// ================================================
// RÉCEPTION DONNÉES ESP32
// ================================================

function receiveData(): void {
    $db   = getDB();
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw, true);

    if (!$json) {
        jsonResponse(['error' => 'JSON invalide'], 400);
    }

    $module_id = sanitize($json['id']  ?? '');
    $acc_nom   = sanitize($json['acc'] ?? '');
    $ch1       = $json['ch1'] ?? [];

    if (!$module_id) {
        jsonResponse(['error' => 'ID module manquant'], 400);
    }

    // 1. Trouver ou créer l'ACC
    $acc_id = null;
    if ($acc_nom) {
        $stmt = $db->prepare("SELECT id FROM acc WHERE nom = ?");
        $stmt->execute([$acc_nom]);
        $acc = $stmt->fetch();

        if (!$acc) {
            // Créer l'ACC automatiquement
            $stmt = $db->prepare("INSERT INTO acc (nom, description) VALUES (?, 'ACC créé automatiquement')");
            $stmt->execute([$acc_nom]);
            $acc_id = $db->lastInsertId();
        } else {
            $acc_id = $acc['id'];
        }
    }

    // 2. Trouver ou créer le producteur
    $stmt = $db->prepare("SELECT id FROM producteurs WHERE module_id = ?");
    $stmt->execute([$module_id]);
    $prod = $stmt->fetch();

    if (!$prod) {
        // Créer le producteur
        $stmt = $db->prepare(
            "INSERT INTO producteurs (acc_id, module_id, nom, ip, firmware, rssi, derniere_vue)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $acc_id,
            $module_id,
            $module_id,
            sanitize($json['ip']  ?? ''),
            sanitize($json['fw']  ?? ''),
            intval($json['rssi']  ?? 0),
        ]);
        $prod_id = $db->lastInsertId();
    } else {
        $prod_id = $prod['id'];
        // Mettre à jour le producteur
        $stmt = $db->prepare(
            "UPDATE producteurs SET
                acc_id      = COALESCE(?, acc_id),
                ip          = ?,
                firmware    = ?,
                rssi        = ?,
                derniere_vue = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $acc_id,
            sanitize($json['ip']  ?? ''),
            sanitize($json['fw']  ?? ''),
            intval($json['rssi']  ?? 0),
            $prod_id,
        ]);
    }

    // 3. Enregistrer la mesure
    $stmt = $db->prepare(
        "INSERT INTO mesures
            (producteur_id, tension, courant, puissance, surplus, est_surplus,
             energie_in, energie_out, facteur_puiss, rssi, uptime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $prod_id,
        floatval($ch1['voltage']    ?? 0),
        floatval($ch1['current']    ?? 0),
        floatval($ch1['power']      ?? $json['total_power'] ?? 0),
        floatval($json['surplus']   ?? 0),
        intval($json['is_surplus']  ?? 0),
        floatval($ch1['energy_in']  ?? 0),
        floatval($ch1['energy_out'] ?? 0),
        floatval($ch1['pf']         ?? 0),
        intval($json['rssi']        ?? 0),
        intval($json['uptime']      ?? 0),
    ]);

    // 4. Vérifier si notification à envoyer
    if ($acc_id && ($json['is_surplus'] ?? false)) {
        checkAndNotify($db, $acc_id, $acc_nom, floatval($json['surplus'] ?? 0));
    }

    // 5. Nettoyer vieilles mesures (> DATA_RETENTION jours)
    $db->exec("DELETE FROM mesures WHERE timestamp < DATE_SUB(NOW(), INTERVAL " . DATA_RETENTION . " DAY)");

    jsonResponse([
        'status'     => 'ok',
        'module_id'  => $module_id,
        'acc'        => $acc_nom,
        'prod_id'    => $prod_id,
        'surplus'    => $json['surplus'] ?? 0,
    ]);
}

// ================================================
// VÉRIFIER ET ENVOYER NOTIFICATIONS
// ================================================

function checkAndNotify(PDO $db, int $acc_id, string $acc_nom, float $surplus): void {
    if ($surplus < SURPLUS_THRESHOLD) return;

    // Vérifier cooldown
    $stmt = $db->prepare(
        "SELECT timestamp FROM notifications_log
         WHERE acc_id = ?
         ORDER BY timestamp DESC LIMIT 1"
    );
    $stmt->execute([$acc_id]);
    $last = $stmt->fetch();

    if ($last) {
        $diff = time() - strtotime($last['timestamp']);
        if ($diff < NOTIFY_COOLDOWN) return;
    }

    // Récupérer abonnés de cet ACC
    $stmt = $db->prepare(
        "SELECT endpoint, p256dh, auth, nom
         FROM consommateurs
         WHERE acc_id = ? AND actif = 1"
    );
    $stmt->execute([$acc_id]);
    $subs = $stmt->fetchAll();

    if (empty($subs)) return;

    $payload = [
        'title'    => '⚡ LoKy ACC — Surplus disponible !',
        'body'     => '🌞 ' . number_format($surplus) . ' W disponibles — Branchez vos appareils ! [' . $acc_nom . ']',
        'tag'      => 'loky-surplus-' . $acc_id,
        'renotify' => true,
        'data'     => ['url' => '/fred/loky/consumer.php?acc=' . urlencode($acc_nom)],
    ];

    $sent    = 0;
    $expired = [];

    foreach ($subs as $sub) {
        $subscription = [
            'endpoint' => $sub['endpoint'],
            'keys'     => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth']],
        ];
        if (sendPushNotification($subscription, $payload)) {
            $sent++;
        } else {
            $expired[] = $sub['endpoint'];
        }
    }

    // Supprimer abonnements expirés
    foreach ($expired as $ep) {
        $stmt = $db->prepare("DELETE FROM consommateurs WHERE endpoint = ?");
        $stmt->execute([$ep]);
    }

    // Logger la notification
    $stmt = $db->prepare(
        "INSERT INTO notifications_log (acc_id, surplus_w, nb_envoyes) VALUES (?, ?, ?)"
    );
    $stmt->execute([$acc_id, intval($surplus), $sent]);
}

// ================================================
// ABONNEMENT PUSH
// ================================================

function subscribePush(): void {
    $db  = getDB();
    $raw = file_get_contents('php://input');
    $sub = json_decode($raw, true);

    if (!$sub || empty($sub['endpoint'])) {
        jsonResponse(['error' => 'Données invalides'], 400);
    }

    $acc_nom = sanitize($sub['acc']  ?? '');
    $nom     = sanitize($sub['name'] ?? 'Consommateur');
    $hash    = md5($sub['endpoint']);

    // Trouver l'ACC
    $acc_id = null;
    if ($acc_nom) {
        $stmt = $db->prepare("SELECT id FROM acc WHERE nom = ?");
        $stmt->execute([$acc_nom]);
        $acc = $stmt->fetch();
        if ($acc) $acc_id = $acc['id'];
    }

    if (!$acc_id) {
        jsonResponse(['error' => 'ACC introuvable : ' . $acc_nom], 404);
    }

    // Insérer ou mettre à jour
    $stmt = $db->prepare(
        "INSERT INTO consommateurs (acc_id, nom, endpoint, p256dh, auth, endpoint_hash)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            acc_id  = VALUES(acc_id),
            nom     = VALUES(nom),
            p256dh  = VALUES(p256dh),
            auth    = VALUES(auth),
            actif   = 1"
    );
    $stmt->execute([
        $acc_id,
        $nom,
        $sub['endpoint'],
        $sub['keys']['p256dh'] ?? '',
        $sub['keys']['auth']   ?? '',
        $hash,
    ]);

    // Compter abonnés
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM consommateurs WHERE acc_id = ? AND actif = 1");
    $stmt->execute([$acc_id]);
    $count = $stmt->fetch()['total'];

    jsonResponse(['status' => 'ok', 'acc' => $acc_nom, 'total' => $count]);
}

// ================================================
// DÉSABONNEMENT PUSH
// ================================================

function unsubscribePush(): void {
    $db  = getDB();
    $sub = json_decode(file_get_contents('php://input'), true);

    if ($sub && !empty($sub['endpoint'])) {
        $stmt = $db->prepare("UPDATE consommateurs SET actif = 0 WHERE endpoint_hash = ?");
        $stmt->execute([md5($sub['endpoint'])]);
    }

    jsonResponse(['status' => 'ok']);
}

// ================================================
// TEST PUSH
// ================================================

function testPush(): void {
    $db      = getDB();
    $acc_nom = $_GET['acc'] ?? '';

    $query = "SELECT c.endpoint, c.p256dh, c.auth, a.nom as acc_nom
              FROM consommateurs c
              JOIN acc a ON c.acc_id = a.id
              WHERE c.actif = 1";
    $params = [];

    if ($acc_nom) {
        $query  .= " AND a.nom = ?";
        $params[] = $acc_nom;
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $subs = $stmt->fetchAll();

    $sent = 0;
    foreach ($subs as $sub) {
        $subscription = [
            'endpoint' => $sub['endpoint'],
            'keys'     => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth']],
        ];
        $payload = [
            'title' => '⚡ LoKy ACC — Test',
            'body'  => '✅ Notifications OK !' . ($acc_nom ? ' [' . $acc_nom . ']' : ''),
            'tag'   => 'loky-test',
        ];
        if (sendPushNotification($subscription, $payload)) $sent++;
    }

    jsonResponse(['status' => 'ok', 'sent' => $sent, 'acc' => $acc_nom]);
}

// ================================================
// DONNÉES ACC (dashboard consommateur)
// ================================================

function getAccData(): void {
    $db      = getDB();
    $acc_nom = $_GET['acc'] ?? '';

    if (!$acc_nom) jsonResponse(['error' => 'ACC manquant'], 400);

    // Infos ACC
    $stmt = $db->prepare("SELECT * FROM acc WHERE nom = ?");
    $stmt->execute([$acc_nom]);
    $acc = $stmt->fetch();

    if (!$acc) jsonResponse(['found' => false, 'acc' => $acc_nom]);

    // Producteurs de l'ACC avec dernière mesure
    $stmt = $db->prepare(
        "SELECT p.*,
                m.tension, m.courant, m.puissance, m.surplus, m.est_surplus,
                m.energie_in, m.energie_out, m.facteur_puiss,
                m.timestamp as derniere_mesure
         FROM producteurs p
         LEFT JOIN mesures m ON m.id = (
             SELECT id FROM mesures
             WHERE producteur_id = p.id
             ORDER BY timestamp DESC LIMIT 1
         )
         WHERE p.acc_id = ? AND p.actif = 1"
    );
    $stmt->execute([$acc['id']]);
    $membres = $stmt->fetchAll();

    // Calculs globaux
    $surplus_total  = 0;
    $energie_in     = 0;
    $energie_out    = 0;
    $tension        = 0;

    foreach ($membres as $m) {
        $surplus_total += floatval($m['surplus'] ?? 0);
        $energie_in    += floatval($m['energie_in'] ?? 0);
        $energie_out   += floatval($m['energie_out'] ?? 0);
        $tension        = floatval($m['tension'] ?? 0);
    }

    // Abonnés de l'ACC
    $stmt = $db->prepare(
        "SELECT COUNT(*) as total FROM consommateurs WHERE acc_id = ? AND actif = 1"
    );
    $stmt->execute([$acc['id']]);
    $sub_count = $stmt->fetch()['total'];

    // Historique dernières 24h
    $stmt = $db->prepare(
        "SELECT m.timestamp, m.surplus, m.puissance, m.tension, p.module_id
         FROM mesures m
         JOIN producteurs p ON m.producteur_id = p.id
         WHERE p.acc_id = ? AND m.timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         AND m.est_surplus = 1
         ORDER BY m.timestamp DESC
         LIMIT 20"
    );
    $stmt->execute([$acc['id']]);
    $historique = $stmt->fetchAll();

    // Formater membres
    $membres_fmt = array_map(function($m) {
        $age = $m['derniere_mesure'] ? time() - strtotime($m['derniere_mesure']) : 9999;
        return [
            'id'         => $m['module_id'],
            'power'      => floatval($m['puissance'] ?? 0),
            'surplus'    => floatval($m['surplus'] ?? 0),
            'is_surplus' => boolval($m['est_surplus'] ?? 0),
            'voltage'    => floatval($m['tension'] ?? 0),
            'current'    => floatval($m['courant'] ?? 0),
            'energy_in'  => floatval($m['energie_in'] ?? 0),
            'energy_out' => floatval($m['energie_out'] ?? 0),
            'rssi'       => intval($m['rssi'] ?? 0),
            'age'        => $age,
        ];
    }, $membres);

    jsonResponse([
        'found'      => true,
        'acc'        => $acc_nom,
        'acc_info'   => $acc,
        'members'    => $membres_fmt,
        'surplus'    => round($surplus_total, 1),
        'injected'   => round($energie_out, 3),
        'consumed'   => round($energie_in, 3),
        'voltage'    => $tension,
        'is_surplus' => $surplus_total >= SURPLUS_THRESHOLD,
        'sub_count'  => $sub_count,
        'historique' => $historique,
        'threshold'  => SURPLUS_THRESHOLD,
    ]);
}

// ================================================
// LISTE DES ACC
// ================================================

function getAccList(): void {
    $db = getDB();
    $stmt = $db->query(
        "SELECT a.nom, a.description,
                COUNT(DISTINCT p.id) as nb_producteurs,
                COUNT(DISTINCT c.id) as nb_consommateurs,
                SUM(CASE WHEN p.actif = 1 THEN 1 ELSE 0 END) as nb_actifs
         FROM acc a
         LEFT JOIN producteurs p ON p.acc_id = a.id
         LEFT JOIN consommateurs c ON c.acc_id = a.id AND c.actif = 1
         WHERE a.actif = 1
         GROUP BY a.id
         ORDER BY a.nom"
    );
    $accs = $stmt->fetchAll();
    jsonResponse(['acc_list' => $accs]);
}

// ================================================
// CRÉER UN ACC
// ================================================

function createAcc(): void {
    $db   = getDB();
    $data = json_decode(file_get_contents('php://input'), true);

    $nom  = sanitize($data['nom']  ?? '');
    $desc = sanitize($data['desc'] ?? '');
    $coord = sanitize($data['coordinateur'] ?? '');

    if (!$nom) jsonResponse(['error' => 'Nom ACC manquant'], 400);

    try {
        $stmt = $db->prepare(
            "INSERT INTO acc (nom, description, coordinateur) VALUES (?, ?, ?)"
        );
        $stmt->execute([$nom, $desc, $coord]);
        jsonResponse(['status' => 'ok', 'id' => $db->lastInsertId(), 'nom' => $nom]);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'ACC déjà existant'], 409);
    }
}

// ================================================
// DONNÉES ADMIN
// ================================================

function getAdminData(): void {
    $db = getDB();

    // Tous les ACC avec stats
    $stmt = $db->query(
        "SELECT a.*,
                COUNT(DISTINCT p.id) as nb_producteurs,
                COUNT(DISTINCT c.id) as nb_consommateurs
         FROM acc a
         LEFT JOIN producteurs p ON p.acc_id = a.id AND p.actif = 1
         LEFT JOIN consommateurs c ON c.acc_id = a.id AND c.actif = 1
         GROUP BY a.id ORDER BY a.nom"
    );
    $accs = $stmt->fetchAll();

    // Tous les producteurs avec dernière mesure
    $stmt = $db->query(
        "SELECT p.*, a.nom as acc_nom,
                m.tension, m.courant, m.puissance, m.surplus, m.est_surplus,
                m.timestamp as derniere_mesure
         FROM producteurs p
         LEFT JOIN acc a ON p.acc_id = a.id
         LEFT JOIN mesures m ON m.id = (
             SELECT id FROM mesures WHERE producteur_id = p.id ORDER BY timestamp DESC LIMIT 1
         )
         WHERE p.actif = 1
         ORDER BY a.nom, p.module_id"
    );
    $producteurs = $stmt->fetchAll();

    // Tous les consommateurs
    $stmt = $db->query(
        "SELECT c.*, a.nom as acc_nom
         FROM consommateurs c
         LEFT JOIN acc a ON c.acc_id = a.id
         WHERE c.actif = 1
         ORDER BY a.nom, c.nom"
    );
    $consommateurs = $stmt->fetchAll();

    // Stats globales
    $stats = $db->query(
        "SELECT
            (SELECT COUNT(*) FROM acc WHERE actif = 1) as nb_acc,
            (SELECT COUNT(*) FROM producteurs WHERE actif = 1) as nb_producteurs,
            (SELECT COUNT(*) FROM consommateurs WHERE actif = 1) as nb_consommateurs,
            (SELECT COUNT(*) FROM mesures WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)) as mesures_heure,
            (SELECT SUM(surplus) FROM mesures WHERE timestamp = (SELECT MAX(timestamp) FROM mesures m2 WHERE m2.producteur_id = mesures.producteur_id)) as surplus_total
        "
    )->fetch();

    jsonResponse([
        'accs'          => $accs,
        'producteurs'   => $producteurs,
        'consommateurs' => $consommateurs,
        'stats'         => $stats,
        'timestamp'     => date('Y-m-d H:i:s'),
    ]);
}

// ================================================
// HISTORIQUE MESURES
// ================================================

function getHistory(): void {
    $db      = getDB();
    $acc_nom = $_GET['acc']    ?? '';
    $hours   = intval($_GET['hours'] ?? 24);
    $hours   = min($hours, 168); // Max 7 jours

    $query = "SELECT m.timestamp, m.puissance, m.surplus, m.tension,
                     m.est_surplus, p.module_id, a.nom as acc_nom
              FROM mesures m
              JOIN producteurs p ON m.producteur_id = p.id
              JOIN acc a ON p.acc_id = a.id
              WHERE m.timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)";
    $params = [$hours];

    if ($acc_nom) {
        $query  .= " AND a.nom = ?";
        $params[] = $acc_nom;
    }

    $query .= " ORDER BY m.timestamp DESC LIMIT 500";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $mesures = $stmt->fetchAll();

    jsonResponse(['mesures' => $mesures, 'count' => count($mesures), 'hours' => $hours]);
}

// ================================================
// FONCTIONS VAPID / PUSH
// ================================================

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function sendPushNotification(array $subscription, array $payload): bool {
    $endpoint = $subscription['endpoint'];
    $parsed   = parse_url($endpoint);
    $audience = $parsed['scheme'] . '://' . $parsed['host'];

    $header  = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $pl      = base64url_encode(json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => VAPID_SUBJECT]));
    $signing = $header . '.' . $pl;

    $rawPriv = base64url_decode(VAPID_PRIVATE_KEY);
    $der     = "\x30\x77\x02\x01\x01\x04\x20" . $rawPriv . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $pem     = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    $privKey = openssl_pkey_get_private($pem);
    openssl_sign($signing, $sig, $privKey, OPENSSL_ALGO_SHA256);

    $offset = 2; if (ord($sig[$offset]) === 0x81) $offset++;
    $offset++; $rLen = ord($sig[$offset++]);
    $r = substr($sig, $offset, $rLen); $offset += $rLen; $offset++;
    $sLen = ord($sig[$offset++]); $s = substr($sig, $offset, $sLen);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $jwt = $signing . '.' . base64url_encode($r . $s);

    $headers = [
        'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY,
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

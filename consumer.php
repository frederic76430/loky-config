<?php
// ================================================
// LoKy ACC — Espace Consommateur (sécurisé)
// consumer.php
// ================================================

require_once 'auth.php';

// Routes API sans login requis
$route = $_GET['action'] ?? '';
$api_routes = ['get_data', 'get_acc_list', 'subscribe', 'unsubscribe'];

if (!in_array($route, $api_routes)) {
    requireLogin();
    if (!isConsommateur() && !isAdmin()) {
        header('Location: login.php');
        exit;
    }
}

$db   = getDB();
$user = getUser();

// ================================================
// ROUTES API
// ================================================

// ---- Données ACC ----
if ($route === 'get_data') {
    header('Content-Type: application/json');
    $acc_nom = $_GET['acc'] ?? ($user['acc_nom'] ?? '');
    if (!$acc_nom) { echo json_encode(['found' => false]); exit; }

    $stmt = $db->prepare("SELECT * FROM acc WHERE nom = ?");
    $stmt->execute([$acc_nom]);
    $acc = $stmt->fetch();
    if (!$acc) { echo json_encode(['found' => false]); exit; }

    // Producteurs + dernière mesure
    $stmt = $db->prepare("
        SELECT p.module_id, p.rssi,
               m.tension, m.courant, m.puissance, m.surplus,
               m.est_surplus, m.energie_in, m.energie_out, m.timestamp as ts
        FROM producteurs p
        LEFT JOIN mesures m ON m.id = (
            SELECT id FROM mesures WHERE producteur_id = p.id ORDER BY timestamp DESC LIMIT 1
        )
        WHERE p.acc_id = ? AND p.actif = 1
    ");
    $stmt->execute([$acc['id']]);
    $membres = $stmt->fetchAll();

    $surplus = 0; $e_in = 0; $e_out = 0; $voltage = 0;
    foreach ($membres as $m) {
        $surplus  += floatval($m['surplus'] ?? 0);
        $e_in     += floatval($m['energie_in']  ?? 0);
        $e_out    += floatval($m['energie_out'] ?? 0);
        $voltage   = floatval($m['tension'] ?? 0);
    }

    // Abonnés push de cet ACC
    $stmt = $db->prepare("SELECT COUNT(*) as t FROM utilisateurs WHERE acc_id = ? AND push_actif = 1");
    $stmt->execute([$acc['id']]);
    $sub_count = $stmt->fetch()['t'];

    // Historique 24h
    $stmt = $db->prepare("
        SELECT m.timestamp, m.surplus, m.puissance, p.module_id
        FROM mesures m JOIN producteurs p ON m.producteur_id = p.id
        WHERE p.acc_id = ? AND m.est_surplus = 1
        AND m.timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY m.timestamp DESC LIMIT 20
    ");
    $stmt->execute([$acc['id']]);
    $historique = $stmt->fetchAll();

    // Graphique 24h
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(m.timestamp, '%H:00') as heure,
               AVG(ABS(m.puissance)) as avg_pwr,
               MAX(m.surplus) as max_surplus
        FROM mesures m JOIN producteurs p ON m.producteur_id = p.id
        WHERE p.acc_id = ? AND m.timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY DATE_FORMAT(m.timestamp, '%Y-%m-%d %H')
        ORDER BY heure
    ");
    $stmt->execute([$acc['id']]);
    $graphdata = $stmt->fetchAll();

    $membres_fmt = array_map(function($m) {
        $age = $m['ts'] ? time() - strtotime($m['ts']) : 9999;
        return [
            'id'         => $m['module_id'],
            'power'      => floatval($m['puissance'] ?? 0),
            'surplus'    => floatval($m['surplus'] ?? 0),
            'is_surplus' => boolval($m['est_surplus'] ?? 0),
            'voltage'    => floatval($m['tension'] ?? 0),
            'current'    => floatval($m['courant'] ?? 0),
            'energy_in'  => floatval($m['energie_in']  ?? 0),
            'energy_out' => floatval($m['energie_out'] ?? 0),
            'rssi'       => intval($m['rssi'] ?? 0),
            'age'        => $age,
        ];
    }, $membres);

    echo json_encode([
        'found'      => true,
        'acc'        => $acc_nom,
        'members'    => $membres_fmt,
        'surplus'    => round($surplus, 1),
        'injected'   => round($e_out, 3),
        'consumed'   => round($e_in, 3),
        'voltage'    => $voltage,
        'is_surplus' => $surplus >= SURPLUS_THRESHOLD,
        'sub_count'  => $sub_count,
        'historique' => $historique,
        'graphdata'  => $graphdata,
        'threshold'  => SURPLUS_THRESHOLD,
        'time'       => date('H:i:s'),
    ]);
    exit;
}

// ---- Liste ACC ----
if ($route === 'get_acc_list') {
    header('Content-Type: application/json');
    $accs = $db->query("
        SELECT a.nom, COUNT(DISTINCT p.id) as nb_prod,
               COUNT(DISTINCT u.id) as nb_cons
        FROM acc a
        LEFT JOIN producteurs p ON p.acc_id = a.id AND p.actif = 1
        LEFT JOIN utilisateurs u ON u.acc_id = a.id AND u.push_actif = 1
        WHERE a.actif = 1 GROUP BY a.id ORDER BY a.nom
    ")->fetchAll();
    echo json_encode(['accs' => $accs]);
    exit;
}

// ---- Changer d'ACC ----
if ($route === 'change_acc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    requireLogin();
    $acc_nom = sanitize($_POST['acc'] ?? '');
    $stmt    = $db->prepare("SELECT id FROM acc WHERE nom = ?");
    $stmt->execute([$acc_nom]);
    $acc = $stmt->fetch();
    if (!$acc) {
        echo json_encode(['error' => 'ACC introuvable']);
        exit;
    }
    $stmt = $db->prepare("UPDATE utilisateurs SET acc_id = ? WHERE id = ?");
    $stmt->execute([$acc['id'], $user['id']]);
    refreshSession();
    echo json_encode(['status' => 'ok', 'acc' => $acc_nom]);
    exit;
}

// ---- Toggle Push ----
if ($route === 'toggle_push' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    requireLogin();
    $data     = json_decode(file_get_contents('php://input'), true);
    $endpoint = $data['endpoint'] ?? '';
    $p256dh   = $data['keys']['p256dh'] ?? '';
    $auth     = $data['keys']['auth']   ?? '';
    $enable   = boolval($data['enable'] ?? false);

    if ($enable && $endpoint) {
        $stmt = $db->prepare(
            "UPDATE utilisateurs SET
                push_endpoint = ?,
                push_p256dh   = ?,
                push_auth     = ?,
                push_actif    = 1
             WHERE id = ?"
        );
        $stmt->execute([$endpoint, $p256dh, $auth, $user['id']]);
    } else {
        $stmt = $db->prepare(
            "UPDATE utilisateurs SET push_actif = 0 WHERE id = ?"
        );
        $stmt->execute([$user['id']]);
    }
    refreshSession();
    echo json_encode(['status' => 'ok', 'push_actif' => $enable]);
    exit;
}

// ---- Se retirer d'un ACC ----
if ($route === 'leave_acc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    requireLogin();
    $stmt = $db->prepare(
        "UPDATE utilisateurs SET acc_id = NULL, push_actif = 0 WHERE id = ?"
    );
    $stmt->execute([$user['id']]);
    refreshSession();
    echo json_encode(['status' => 'ok']);
    exit;
}

// Récupérer ACC actuel
$currentAcc = null;
if ($user['acc_id']) {
    $stmt = $db->prepare("SELECT * FROM acc WHERE id = ?");
    $stmt->execute([$user['acc_id']]);
    $currentAcc = $stmt->fetch();
}

// Liste tous les ACC
$allAccs = $db->query("SELECT nom, description FROM acc WHERE actif = 1 ORDER BY nom")->fetchAll();
$script  = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LoKy ACC — Mon espace</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;--green:#00ff9d;
  --warn:#ffaa00;--danger:#ff3860;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;min-height:100vh;}
body::after{content:'';position:fixed;inset:0;
  background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);
  background-size:28px 28px;opacity:.12;pointer-events:none;z-index:0;}
.wrap{position:relative;z-index:1;max-width:620px;margin:0 auto;padding:20px 16px;}

/* Header */
.header{display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:14px 18px;background:var(--surface);border:1px solid var(--border);
  border-radius:14px;margin-bottom:18px;}
.header-left{display:flex;align-items:center;gap:10px;}
.logo-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--accent),var(--green));
  border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.user-name{font-size:.9rem;font-weight:700;color:var(--text);}
.user-role{font-size:.62rem;color:var(--muted);font-family:'Space Mono',monospace;}
.nav{display:flex;gap:6px;}
.nav-btn{padding:7px 12px;border-radius:8px;border:1px solid var(--border);
  background:var(--card);color:var(--muted);font-family:'Space Mono',monospace;
  font-size:.62rem;text-decoration:none;cursor:pointer;transition:all .2s;}
.nav-btn:hover{border-color:var(--accent);color:var(--accent);}
.nav-btn.danger:hover{border-color:var(--danger);color:var(--danger);}

/* Card */
.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;
  padding:20px;margin-bottom:14px;position:relative;overflow:hidden;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--accent),var(--green));}

/* ACC selector */
.acc-selector h3{font-size:.95rem;color:var(--accent);margin-bottom:6px;}
.acc-selector p{font-size:.78rem;color:var(--muted);font-family:'Space Mono',monospace;margin-bottom:14px;}
.acc-list{display:flex;flex-direction:column;gap:8px;}
.acc-option{display:flex;align-items:center;justify-content:space-between;
  padding:12px 14px;background:var(--card);border:1px solid var(--border);
  border-radius:10px;cursor:pointer;transition:all .2s;}
.acc-option:hover{border-color:var(--accent);}
.acc-option.current{border-color:rgba(0,255,157,.4);background:rgba(0,255,157,.05);}
.acc-option-name{font-family:'Space Mono',monospace;font-size:.85rem;color:var(--accent);}
.acc-option-desc{font-size:.72rem;color:var(--muted);margin-top:2px;}
.btn-join-acc{padding:7px 14px;border-radius:8px;border:none;cursor:pointer;
  font-size:.75rem;font-weight:600;transition:all .2s;}
.btn-join-primary{background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);}
.btn-current{background:rgba(0,255,157,.1);color:var(--green);border:1px solid rgba(0,255,157,.3);}
.btn-leave{background:rgba(255,56,96,.1);color:var(--danger);border:1px solid rgba(255,56,96,.3);}
.btn-leave:hover{background:var(--danger);color:#fff;}

/* Banner surplus */
.banner{border-radius:14px;padding:24px;text-align:center;margin-bottom:14px;
  position:relative;overflow:hidden;}
.banner.active{background:linear-gradient(135deg,rgba(0,255,157,.1),rgba(0,212,255,.05));
  border:1px solid rgba(0,255,157,.3);}
.banner.active::before{content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at center,rgba(0,255,157,.08),transparent 70%);
  animation:glow 3s infinite;}
@keyframes glow{0%,100%{opacity:.5}50%{opacity:1}}
.banner.inactive{background:var(--surface);border:1px solid var(--border);}
.banner-icon{font-size:3rem;margin-bottom:8px;}
.banner-power{font-family:'Space Mono',monospace;font-size:2.8rem;font-weight:700;}
.banner-power.active{color:var(--green);}
.banner-power.inactive{color:var(--muted);}
.banner-label{font-size:.85rem;margin-top:6px;}
.banner-label.active{color:var(--green);}
.banner-label.inactive{color:var(--muted);}
.banner-time{font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:4px;}

/* Stats */
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;}
.stat-item{background:var(--card);border:1px solid var(--border);border-radius:10px;
  padding:12px;text-align:center;}
.stat-lbl{font-size:.58rem;color:var(--muted);text-transform:uppercase;
  letter-spacing:2px;font-family:'Space Mono',monospace;margin-bottom:4px;}
.stat-val{font-family:'Space Mono',monospace;font-size:1.2rem;font-weight:700;color:var(--accent);}
.stat-val.g{color:var(--green);}
.stat-unit{font-size:.62rem;color:var(--muted);}

/* Bouton notif */
.btn-notif{display:flex;align-items:center;justify-content:center;gap:10px;
  padding:15px;border-radius:10px;border:none;cursor:pointer;
  font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:700;
  transition:all .2s;width:100%;}
.btn-notif.off{background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);}
.btn-notif.on{background:rgba(0,255,157,.1);color:var(--green);border:1px solid rgba(0,255,157,.3);}
.btn-notif.on:hover{background:rgba(255,56,96,.1);color:var(--danger);border-color:rgba(255,56,96,.3);}
.notif-sub{font-size:.72rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-align:center;margin-top:8px;}
.dot{width:8px;height:8px;border-radius:50%;background:currentColor;
  animation:pulse 2s infinite;display:inline-block;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}

/* Modules */
.sec-title{font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:3px;
  font-family:'Space Mono',monospace;margin-bottom:10px;
  display:flex;align-items:center;gap:8px;}
.sec-title::after{content:'';flex:1;height:1px;background:var(--border);}
.module-item{display:flex;align-items:center;justify-content:space-between;
  padding:10px 12px;background:var(--card);border:1px solid var(--border);
  border-radius:8px;margin-bottom:6px;}
.mdot{width:9px;height:9px;border-radius:50%;margin-right:8px;}
.mdot.ok{background:var(--green);box-shadow:0 0 5px var(--green);}
.mdot.warn{background:var(--warn);}
.mdot.dead{background:var(--danger);}
.mod-name{font-family:'Space Mono',monospace;font-size:.78rem;color:var(--accent);}
.mod-meta{font-size:.62rem;color:var(--muted);}
.mod-pwr{font-family:'Space Mono',monospace;font-size:.82rem;font-weight:700;text-align:right;}
.mod-pwr.s{color:var(--green);}
.mod-pwr.c{color:var(--warn);}

/* Historique */
.hist-item{display:flex;align-items:center;gap:10px;padding:8px 0;
  border-bottom:1px solid var(--border);}
.hist-item:last-child{border-bottom:none;}
.hist-text{flex:1;font-size:.78rem;}
.hist-time{font-family:'Space Mono',monospace;font-size:.6rem;color:var(--muted);}

/* Graphique */
.chart-wrap{height:110px;position:relative;}

/* Toast */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
  padding:12px 24px;border-radius:10px;font-weight:700;font-size:.85rem;
  box-shadow:0 4px 20px rgba(0,0,0,.4);z-index:9999;}

/* Modal changement ACC */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;
  display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.show{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;
  padding:24px;max-width:420px;width:100%;}
.modal h3{color:var(--accent);margin-bottom:16px;}
.modal-acc-list{display:flex;flex-direction:column;gap:8px;max-height:300px;overflow-y:auto;}
.modal-acc-item{padding:12px 14px;background:var(--card);border:1px solid var(--border);
  border-radius:8px;cursor:pointer;transition:all .2s;}
.modal-acc-item:hover{border-color:var(--accent);}
.modal-acc-name{font-family:'Space Mono',monospace;font-size:.85rem;color:var(--accent);}
.modal-acc-desc{font-size:.7rem;color:var(--muted);margin-top:2px;}
.modal-close{margin-top:14px;width:100%;padding:10px;border-radius:8px;
  border:1px solid var(--border);background:none;color:var(--muted);cursor:pointer;}
.modal-close:hover{border-color:var(--danger);color:var(--danger);}

@media(max-width:400px){.banner-power{font-size:2rem;}}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="header">
  <div class="header-left">
    <div class="logo-icon">👤</div>
    <div>
      <div class="user-name"><?= htmlspecialchars($user['nom']) ?></div>
      <div class="user-role">
        Consommateur
        <?php if ($user['acc_nom']): ?>
        · <span style="color:var(--accent)"><?= htmlspecialchars($user['acc_nom']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="nav">
    <a href="home.php" class="nav-btn">🏠</a>
    <a href="logout.php" class="nav-btn danger">Déconnexion</a>
  </div>
</div>

<?php if (!$currentAcc): ?>
<!-- Pas encore dans un ACC -->
<div class="card acc-selector">
  <h3>⚡ Rejoindre un ACC</h3>
  <p>Choisissez votre groupe d'autoconsommation collective</p>

  <div class="acc-list">
    <?php if (empty($allAccs)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);font-size:.8rem;
                font-family:'Space Mono',monospace;">
      Aucun ACC disponible — contactez votre administrateur
    </div>
    <?php else: ?>
    <?php foreach ($allAccs as $acc): ?>
    <div class="acc-option">
      <div>
        <div class="acc-option-name">⚡ <?= htmlspecialchars($acc['nom']) ?></div>
        <?php if ($acc['description']): ?>
        <div class="acc-option-desc"><?= htmlspecialchars($acc['description']) ?></div>
        <?php endif; ?>
      </div>
      <button class="btn-join-acc btn-join-primary"
              onclick="joinACC('<?= htmlspecialchars($acc['nom']) ?>')">
        Rejoindre
      </button>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- Dashboard consommateur -->

<!-- Banner surplus -->
<div class="banner inactive" id="banner">
  <div class="banner-icon" id="b-icon">🌙</div>
  <div class="banner-power inactive" id="b-power">— W</div>
  <div class="banner-label inactive" id="b-label">Chargement...</div>
  <div class="banner-time" id="b-time"></div>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-item">
    <div class="stat-lbl">Injecté</div>
    <div class="stat-val g" id="s-inj">—</div>
    <div class="stat-unit">kWh</div>
  </div>
  <div class="stat-item">
    <div class="stat-lbl">Consommé</div>
    <div class="stat-val" id="s-cons">—</div>
    <div class="stat-unit">kWh</div>
  </div>
  <div class="stat-item">
    <div class="stat-lbl">Tension</div>
    <div class="stat-val" id="s-volt">—</div>
    <div class="stat-unit">V</div>
  </div>
  <div class="stat-item">
    <div class="stat-lbl">Abonnés</div>
    <div class="stat-val g" id="s-subs">—</div>
    <div class="stat-unit">Push actifs</div>
  </div>
</div>

<!-- Notifications -->
<div class="card">
  <div class="sec-title">🔔 Alertes surplus</div>
  <button class="btn-notif <?= $user['push_actif'] ? 'on' : 'off' ?>"
          id="btn-notif" onclick="toggleNotif()">
    <?= $user['push_actif']
      ? '<span class="dot"></span> Alertes actives — Cliquer pour désactiver'
      : '🔔 Activer les alertes surplus' ?>
  </button>
  <div class="notif-sub" id="notif-sub">
    <?= $user['push_actif']
      ? '✅ Vous recevrez les alertes de ' . htmlspecialchars($user['acc_nom'])
      : 'Recevez une notification quand du surplus est disponible' ?>
  </div>
</div>

<!-- Mon ACC -->
<div class="card">
  <div class="sec-title">⚡ Mon ACC — <?= htmlspecialchars($currentAcc['nom']) ?></div>

  <!-- Boutons gestion ACC -->
  <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
    <button class="btn-join-acc" style="background:rgba(0,212,255,.1);color:var(--accent);
            border:1px solid rgba(0,212,255,.3);padding:8px 14px;border-radius:8px;cursor:pointer;"
            onclick="document.getElementById('modal-acc').classList.add('show')">
      🔄 Changer d'ACC
    </button>
    <button class="btn-join-acc btn-leave"
            onclick="if(confirm('Quitter cet ACC ?')) leaveACC()"
            style="padding:8px 14px;border-radius:8px;cursor:pointer;">
      🚪 Quitter cet ACC
    </button>
  </div>

  <!-- Producteurs -->
  <div class="sec-title">Producteurs</div>
  <div id="modules-list">
    <div style="text-align:center;padding:20px;color:var(--muted);font-size:.8rem;">
      Chargement...
    </div>
  </div>
</div>

<!-- Graphique 24h -->
<div class="card">
  <div class="sec-title">📊 Production 24h</div>
  <div class="chart-wrap"><canvas id="chart"></canvas></div>
  <div style="font-size:.6rem;color:var(--muted);font-family:'Space Mono',monospace;
              text-align:center;margin-top:6px;">Puissance moyenne par heure (W)</div>
</div>

<!-- Historique -->
<div class="card">
  <div class="sec-title">📋 Surplus détectés (24h)</div>
  <div id="history-list">
    <div style="text-align:center;padding:16px;color:var(--muted);font-size:.78rem;
                font-family:'Space Mono',monospace;">Aucun historique</div>
  </div>
</div>

<?php endif; ?>

</div><!-- /wrap -->

<!-- Modal changement ACC -->
<div class="modal-overlay" id="modal-acc">
  <div class="modal">
    <h3>🔄 Changer d'ACC</h3>
    <div class="modal-acc-list">
      <?php foreach ($allAccs as $acc): ?>
      <div class="modal-acc-item" onclick="joinACC('<?= htmlspecialchars($acc['nom']) ?>')">
        <div class="modal-acc-name">⚡ <?= htmlspecialchars($acc['nom']) ?></div>
        <?php if ($acc['description']): ?>
        <div class="modal-acc-desc"><?= htmlspecialchars($acc['description']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="modal-close"
            onclick="document.getElementById('modal-acc').classList.remove('show')">
      Annuler
    </button>
  </div>
</div>

<script>
const API    = '<?= $script ?>';
const VAPID  = '<?= VAPID_PUBLIC_KEY ?>';
const THRESH = <?= SURPLUS_THRESHOLD ?>;
const ACC    = '<?= htmlspecialchars($user['acc_nom'] ?? '') ?>';

let swReg       = null;
let currentSub  = null;
let pushActif   = <?= $user['push_actif'] ? 'true' : 'false' ?>;
let refreshTimer = null;

// Init
window.addEventListener('load', async () => {
  if (ACC) {
    loadData();
    refreshTimer = setInterval(loadData, 15000);
  }

  if ('serviceWorker' in navigator && 'PushManager' in window) {
    try {
      swReg      = await navigator.serviceWorker.register('/fred/sw.js');
      await navigator.serviceWorker.ready;
      currentSub = await swReg.pushManager.getSubscription();
    } catch(e) { console.error('SW:', e); }
  }
});

// Rejoindre ACC
async function joinACC(accNom) {
  document.getElementById('modal-acc')?.classList.remove('show');
  const fd = new FormData();
  fd.append('acc', accNom);
  const res = await fetch(API + '?action=change_acc', {method:'POST', body:fd});
  const d   = await res.json();
  if (d.status === 'ok') {
    showToast('✅ ACC changé : ' + accNom);
    setTimeout(() => location.reload(), 800);
  } else {
    showToast('❌ ' + (d.error || 'Erreur'), 'error');
  }
}

// Quitter ACC
async function leaveACC() {
  const res = await fetch(API + '?action=leave_acc', {method:'POST'});
  const d   = await res.json();
  if (d.status === 'ok') {
    showToast('👋 Vous avez quitté votre ACC');
    setTimeout(() => location.reload(), 800);
  }
}

// Charger données
async function loadData() {
  try {
    const res = await fetch(API + '?action=get_data&acc=' + encodeURIComponent(ACC));
    const d   = await res.json();
    if (!d.found) return;
    updateDash(d);
  } catch(e) { console.error(e); }
}

// Mettre à jour dashboard
function updateDash(d) {
  const isSurp = d.is_surplus;
  const surp   = d.surplus;

  // Banner
  const banner = document.getElementById('banner');
  if (!banner) return;
  banner.className = 'banner ' + (isSurp ? 'active' : 'inactive');
  document.getElementById('b-power').className = 'banner-power ' + (isSurp ? 'active' : 'inactive');
  document.getElementById('b-label').className = 'banner-label ' + (isSurp ? 'active' : 'inactive');
  document.getElementById('b-icon').textContent  = isSurp ? '🌞' : '🌙';
  document.getElementById('b-power').textContent = (isSurp ? surp : 0) + ' W';
  document.getElementById('b-label').textContent = isSurp
    ? '⚡ Surplus disponible — Branchez vos appareils !'
    : 'Pas de surplus (seuil : ' + THRESH + 'W)';
  document.getElementById('b-time').textContent = 'Mis à jour à ' + d.time;

  // Stats
  document.getElementById('s-inj').textContent  = d.injected || '0';
  document.getElementById('s-cons').textContent = d.consumed || '0';
  document.getElementById('s-volt').textContent = d.voltage  || '—';
  document.getElementById('s-subs').textContent = d.sub_count;

  // Modules
  const ml = document.getElementById('modules-list');
  if (ml) {
    ml.innerHTML = d.members.length === 0
      ? '<div style="text-align:center;padding:16px;color:var(--muted);font-size:.78rem;">Aucun module actif</div>'
      : d.members.map(m => {
          const dc = m.age < 120 ? 'ok' : m.age < 600 ? 'warn' : 'dead';
          const pc = m.is_surplus ? 's' : 'c';
          return `<div class="module-item">
            <div style="display:flex;align-items:center;">
              <div class="mdot ${dc}"></div>
              <div>
                <div class="mod-name">${m.id}</div>
                <div class="mod-meta">WiFi: ${m.rssi} dBm · ${m.energy_out.toFixed(3)} kWh inj.</div>
              </div>
            </div>
            <div class="mod-pwr ${pc}">
              ${m.is_surplus ? '⚡ -' : '+'}${Math.abs(m.power).toFixed(1)} W
            </div>
          </div>`;
        }).join('');
  }

  // Historique
  const hl = document.getElementById('history-list');
  if (hl && d.historique && d.historique.length > 0) {
    hl.innerHTML = d.historique.map(h => {
      const t = new Date(h.timestamp).toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'});
      return `<div class="hist-item">
        <div style="font-size:1.1rem">🌞</div>
        <div>
          <div class="hist-text">Surplus <strong style="color:var(--green)">${parseFloat(h.surplus).toFixed(0)} W</strong> — ${h.module_id}</div>
          <div class="hist-time">${t}</div>
        </div>
      </div>`;
    }).join('');
  }

  // Graphique
  updateChart(d.graphdata || []);
}

// Graphique simple
function updateChart(data) {
  const canvas = document.getElementById('chart');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const w = canvas.offsetWidth; const h = canvas.offsetHeight;
  canvas.width = w; canvas.height = h;
  if (!data.length) {
    ctx.fillStyle='rgba(74,112,144,.5)'; ctx.font='11px Space Mono';
    ctx.textAlign='center'; ctx.fillText('Pas de données', w/2, h/2); return;
  }
  const pwrs = data.map(d => Math.abs(parseFloat(d.avg_pwr||0)));
  const max  = Math.max(...pwrs, 1);
  const barW = (w-30)/data.length;
  const padL=30, padB=18, padT=8, cH=h-padB-padT;
  ctx.clearRect(0,0,w,h);
  ctx.strokeStyle='rgba(14,42,74,.8)'; ctx.lineWidth=1;
  for(let i=0;i<=4;i++){
    const y=padT+cH*(1-i/4);
    ctx.beginPath();ctx.moveTo(padL,y);ctx.lineTo(w,y);ctx.stroke();
    ctx.fillStyle='rgba(74,112,144,.8)';ctx.font='8px Space Mono';
    ctx.textAlign='right';ctx.fillText(Math.round(max*i/4)+'W',padL-2,y+3);
  }
  data.forEach((d,i)=>{
    const pwr=Math.abs(parseFloat(d.avg_pwr||0));
    const bH=(pwr/max)*cH; const x=padL+i*barW; const y=padT+cH-bH;
    const g=ctx.createLinearGradient(0,y,0,y+bH);
    g.addColorStop(0,'rgba(0,255,157,.9)');g.addColorStop(1,'rgba(0,212,255,.3)');
    ctx.fillStyle=g; ctx.fillRect(x+1,y,barW-2,bH);
    if(i%4===0){ctx.fillStyle='rgba(74,112,144,.8)';ctx.font='7px Space Mono';
      ctx.textAlign='center';ctx.fillText(d.heure,x+barW/2,h-3);}
  });
}

// Toggle notifications Push
async function toggleNotif() {
  if (!swReg) { showToast('Service Worker non disponible', 'error'); return; }

  if (pushActif && currentSub) {
    // Désactiver
    await currentSub.unsubscribe();
    await fetch(API + '?action=toggle_push', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({enable: false})
    });
    currentSub = null; pushActif = false;
    updateNotifBtn();
    showToast('🔕 Alertes désactivées');
  } else {
    // Activer
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') { showToast('❌ Permission refusée', 'error'); return; }
    try {
      const sub = await swReg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlB64(VAPID)
      });
      const sd = sub.toJSON();
      sd.enable = true;
      await fetch(API + '?action=toggle_push', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify(sd)
      });
      currentSub = sub; pushActif = true;
      updateNotifBtn();
      showToast('✅ Alertes activées pour ' + ACC + ' !');
    } catch(e) { showToast('❌ ' + e.message, 'error'); }
  }
}

function updateNotifBtn() {
  const btn = document.getElementById('btn-notif');
  const sub = document.getElementById('notif-sub');
  if (!btn) return;
  btn.className = 'btn-notif ' + (pushActif ? 'on' : 'off');
  btn.innerHTML = pushActif
    ? '<span class="dot"></span> Alertes actives — Cliquer pour désactiver'
    : '🔔 Activer les alertes surplus';
  if (sub) sub.textContent = pushActif
    ? '✅ Vous recevrez les alertes de ' + ACC
    : 'Recevez une notification quand du surplus est disponible';
}

function urlB64(b) {
  const p='='.repeat((4-b.length%4)%4);
  const d=(b+p).replace(/-/g,'+').replace(/_/g,'/');
  return Uint8Array.from([...atob(d)].map(c=>c.charCodeAt(0)));
}

function showToast(msg, type='ok') {
  const t = document.createElement('div');
  t.className = 'toast'; t.textContent = msg;
  t.style.cssText = `background:${type==='error'?'#ff3860':'#00ff9d'};color:${type==='error'?'#fff':'#050d1a'}`;
  document.body.appendChild(t);
  setTimeout(()=>t.remove(), 3500);
}
</script>
</body>
</html>

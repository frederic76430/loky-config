<?php
// ================================================
// LoKy ACC — Dashboard Administrateur
// admin.php
// ================================================

require_once 'config.php';

$db = getDB();

// ---- Créer ACC via formulaire ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_acc'])) {
    $nom   = sanitize($_POST['nom']   ?? '');
    $desc  = sanitize($_POST['desc']  ?? '');
    $coord = sanitize($_POST['coord'] ?? '');
    if ($nom) {
        try {
            $stmt = $db->prepare("INSERT INTO acc (nom, description, coordinateur) VALUES (?, ?, ?)");
            $stmt->execute([$nom, $desc, $coord]);
            $msg_ok = "ACC « $nom » créé avec succès !";
        } catch (PDOException $e) {
            $msg_err = "ACC déjà existant.";
        }
    }
}

// ---- Supprimer producteur ----
if (isset($_GET['del_prod'])) {
    $stmt = $db->prepare("UPDATE producteurs SET actif = 0 WHERE id = ?");
    $stmt->execute([intval($_GET['del_prod'])]);
    header('Location: admin.php'); exit;
}

// ---- Supprimer consommateur ----
if (isset($_GET['del_cons'])) {
    $stmt = $db->prepare("UPDATE consommateurs SET actif = 0 WHERE id = ?");
    $stmt->execute([intval($_GET['del_cons'])]);
    header('Location: admin.php'); exit;
}

// ---- Stats globales ----
$stats = $db->query("SELECT
    (SELECT COUNT(*) FROM acc WHERE actif = 1) as nb_acc,
    (SELECT COUNT(*) FROM producteurs WHERE actif = 1) as nb_prod,
    (SELECT COUNT(*) FROM consommateurs WHERE actif = 1) as nb_cons,
    (SELECT COUNT(*) FROM mesures WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)) as mesures_h,
    (SELECT COUNT(*) FROM mesures) as total_mesures
")->fetch();

// ---- Tous les ACC ----
$accs = $db->query("SELECT a.*,
    COUNT(DISTINCT p.id) as nb_prod,
    COUNT(DISTINCT c.id) as nb_cons,
    (SELECT SUM(m2.surplus)
     FROM mesures m2
     JOIN producteurs p2 ON m2.producteur_id = p2.id
     WHERE p2.acc_id = a.id
     AND m2.id IN (SELECT MAX(id) FROM mesures GROUP BY producteur_id)
    ) as surplus_now
FROM acc a
LEFT JOIN producteurs p ON p.acc_id = a.id AND p.actif = 1
LEFT JOIN consommateurs c ON c.acc_id = a.id AND c.actif = 1
GROUP BY a.id ORDER BY a.nom")->fetchAll();

// ---- Tous les producteurs ----
$producteurs = $db->query("SELECT p.*, a.nom as acc_nom,
    m.tension, m.courant, m.puissance, m.surplus, m.est_surplus,
    m.energie_in, m.energie_out, m.timestamp as derniere_mesure
FROM producteurs p
LEFT JOIN acc a ON p.acc_id = a.id
LEFT JOIN mesures m ON m.id = (
    SELECT id FROM mesures WHERE producteur_id = p.id ORDER BY timestamp DESC LIMIT 1
)
WHERE p.actif = 1
ORDER BY a.nom, p.module_id")->fetchAll();

// ---- Tous les consommateurs ----
$consommateurs = $db->query("SELECT c.*, a.nom as acc_nom
FROM consommateurs c
LEFT JOIN acc a ON c.acc_id = a.id
WHERE c.actif = 1
ORDER BY a.nom, c.nom")->fetchAll();

// ---- Dernières mesures ----
$mesures = $db->query("SELECT m.*, p.module_id, a.nom as acc_nom
FROM mesures m
JOIN producteurs p ON m.producteur_id = p.id
JOIN acc a ON p.acc_id = a.id
ORDER BY m.timestamp DESC
LIMIT 50")->fetchAll();

// ---- Notifications log ----
$notifs = $db->query("SELECT n.*, a.nom as acc_nom
FROM notifications_log n
JOIN acc a ON n.acc_id = a.id
ORDER BY n.timestamp DESC
LIMIT 20")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LoKy ACC — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;--green:#00ff9d;
  --warn:#ffaa00;--danger:#ff3860;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;min-height:100vh;}
body::after{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);
  background-size:28px 28px;opacity:.12;pointer-events:none;z-index:0;}
.wrap{position:relative;z-index:1;max-width:1400px;margin:0 auto;padding:20px 16px;}

/* Header */
.header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
  padding:18px 24px;background:var(--surface);border:1px solid var(--border);
  border-radius:14px;margin-bottom:20px;box-shadow:0 0 30px rgba(0,212,255,.1);}
.logo{display:flex;align-items:center;gap:12px;}
.logo-icon{width:44px;height:44px;background:linear-gradient(135deg,var(--accent),var(--green));
  border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;}
.logo h1{font-size:1.4rem;font-weight:800;background:linear-gradient(90deg,var(--accent),var(--green));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.logo p{font-size:.68rem;color:var(--muted);font-family:'Space Mono',monospace;letter-spacing:2px;}
.nav{display:flex;gap:8px;flex-wrap:wrap;}
.nav a{padding:8px 14px;border-radius:8px;border:1px solid var(--border);background:var(--card);
  color:var(--muted);font-family:'Space Mono',monospace;font-size:.65rem;text-decoration:none;transition:all .2s;}
.nav a:hover,.nav a.active{border-color:var(--accent);color:var(--accent);}
.nav a.primary{background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);border:none;font-weight:700;}

/* Stats */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:24px;}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;
  position:relative;overflow:hidden;text-align:center;}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--accent),var(--green));}
.stat-l{font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:2px;
  font-family:'Space Mono',monospace;margin-bottom:6px;}
.stat-v{font-family:'Space Mono',monospace;font-size:1.8rem;font-weight:700;color:var(--accent);}
.stat-v.g{color:var(--green);}
.stat-u{font-size:.68rem;color:var(--muted);margin-top:2px;}

/* Tabs */
.tabs{display:flex;gap:4px;margin-bottom:20px;flex-wrap:wrap;}
.tab{padding:10px 18px;border-radius:8px;border:1px solid var(--border);background:var(--card);
  color:var(--muted);font-family:'Space Mono',monospace;font-size:.7rem;cursor:pointer;transition:all .2s;}
.tab:hover,.tab.active{background:var(--surface);border-color:var(--accent);color:var(--accent);}
.tab-content{display:none;}
.tab-content.active{display:block;}

/* Section */
.section{background:var(--surface);border:1px solid var(--border);border-radius:14px;
  overflow:hidden;margin-bottom:20px;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;}
.section-title{font-family:'Space Mono',monospace;font-size:.8rem;color:var(--accent);font-weight:700;}
.section-count{font-family:'Space Mono',monospace;font-size:.65rem;color:var(--muted);}

/* Formulaire créer ACC */
.form-create{padding:20px;}
.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;}
.form-group label{display:block;font-size:.7rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;}
.form-input{width:100%;background:var(--card);color:var(--text);border:1px solid var(--border);
  border-radius:8px;padding:10px 14px;font-family:'Outfit',sans-serif;font-size:.9rem;
  transition:border-color .2s;outline:none;}
.form-input:focus{border-color:var(--accent);}
.btn{padding:10px 20px;border-radius:8px;border:none;cursor:pointer;
  font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:600;transition:all .2s;}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);}
.btn-primary:hover{box-shadow:0 0 15px rgba(0,212,255,.3);}
.btn-danger{background:rgba(255,56,96,.1);color:var(--danger);border:1px solid rgba(255,56,96,.3);}
.btn-danger:hover{background:var(--danger);color:#fff;}
.btn-sm{padding:5px 10px;font-size:.7rem;border-radius:6px;}

/* Messages */
.msg-ok{background:rgba(0,255,157,.1);border:1px solid rgba(0,255,157,.3);color:var(--green);
  padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem;}
.msg-err{background:rgba(255,56,96,.1);border:1px solid rgba(255,56,96,.3);color:var(--danger);
  padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem;}

/* Table */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{padding:10px 16px;text-align:left;font-family:'Space Mono',monospace;font-size:.6rem;
  color:var(--muted);text-transform:uppercase;letter-spacing:2px;border-bottom:1px solid var(--border);}
td{padding:10px 16px;font-size:.8rem;border-bottom:1px solid var(--border);}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--card);}

/* Badges */
.badge{padding:3px 10px;border-radius:20px;font-family:'Space Mono',monospace;font-size:.6rem;display:inline-block;}
.b-acc{background:rgba(0,212,255,.1);color:var(--accent);border:1px solid rgba(0,212,255,.2);}
.b-ok{background:rgba(0,255,157,.1);color:var(--green);border:1px solid rgba(0,255,157,.2);}
.b-warn{background:rgba(255,170,0,.1);color:var(--warn);border:1px solid rgba(255,170,0,.2);}
.b-dead{background:rgba(255,56,96,.1);color:var(--danger);border:1px solid rgba(255,56,96,.2);}
.b-surplus{background:rgba(0,255,157,.15);color:var(--green);border:1px solid rgba(0,255,157,.3);font-weight:700;}

/* ACC cards grid */
.acc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;padding:16px;}
.acc-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;transition:all .3s;}
.acc-card:hover{border-color:var(--accent);}
.acc-card.surplus{border-color:rgba(0,255,157,.4);}
.acc-card-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;}
.acc-card-name{font-family:'Space Mono',monospace;font-size:.9rem;font-weight:700;color:var(--accent);}
.acc-card-desc{font-size:.75rem;color:var(--muted);margin-top:3px;}
.acc-card-stats{display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;}
.acc-mini-stat{text-align:center;}
.acc-mini-val{font-family:'Space Mono',monospace;font-size:1.1rem;font-weight:700;color:var(--text);}
.acc-mini-val.g{color:var(--green);}
.acc-mini-lbl{font-size:.58rem;color:var(--muted);font-family:'Space Mono',monospace;}
.acc-card-actions{display:flex;gap:6px;margin-top:12px;padding-top:10px;border-top:1px solid var(--border);}
.acc-card-actions a{flex:1;text-align:center;padding:7px;border-radius:6px;text-decoration:none;
  font-size:.72rem;font-family:'Space Mono',monospace;transition:all .2s;}
.acc-link-view{background:rgba(0,212,255,.1);color:var(--accent);border:1px solid rgba(0,212,255,.2);}
.acc-link-view:hover{background:var(--accent);color:var(--bg);}

/* Puissance */
.pwr-surplus{color:var(--green);font-weight:700;}
.pwr-normal{color:var(--text);}

/* Dot status */
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px;}
.dot-ok{background:var(--green);box-shadow:0 0 4px var(--green);}
.dot-warn{background:var(--warn);}
.dot-dead{background:var(--danger);}

/* Empty */
.empty-row td{text-align:center;padding:24px;color:var(--muted);font-family:'Space Mono',monospace;font-size:.75rem;}

/* Footer */
.footer{text-align:center;padding:20px;margin-top:20px;font-family:'Space Mono',monospace;
  font-size:.6rem;color:var(--muted);border-top:1px solid var(--border);}

@media(max-width:768px){
  .stats{grid-template-columns:1fr 1fr;}
  .header{flex-direction:column;}
}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="header">
  <div class="logo">
    <div class="logo-icon">⚡</div>
    <div>
      <h1>LoKy ACC</h1>
      <p>Dashboard Administrateur</p>
    </div>
  </div>
  <div class="nav">
    <a href="home.php">🏠 Accueil</a>
    <a href="consumer.php">👤 Consommateur</a>
    <a href="admin.php" class="active">⚙️ Admin</a>
    <a href="?" class="primary">↺ Refresh</a>
  </div>
</div>

<!-- Messages -->
<?php if (isset($msg_ok)):  ?><div class="msg-ok">✅ <?= $msg_ok ?></div><?php endif; ?>
<?php if (isset($msg_err)): ?><div class="msg-err">❌ <?= $msg_err ?></div><?php endif; ?>

<!-- Stats globales -->
<div class="stats">
  <div class="stat">
    <div class="stat-l">Groupes ACC</div>
    <div class="stat-v"><?= $stats['nb_acc'] ?></div>
    <div class="stat-u">ACC actifs</div>
  </div>
  <div class="stat">
    <div class="stat-l">Producteurs</div>
    <div class="stat-v"><?= $stats['nb_prod'] ?></div>
    <div class="stat-u">Modules LoKy</div>
  </div>
  <div class="stat">
    <div class="stat-l">Consommateurs</div>
    <div class="stat-v"><?= $stats['nb_cons'] ?></div>
    <div class="stat-u">Abonnés Push</div>
  </div>
  <div class="stat">
    <div class="stat-l">Mesures/heure</div>
    <div class="stat-v g"><?= $stats['mesures_h'] ?></div>
    <div class="stat-u">Dernière heure</div>
  </div>
  <div class="stat">
    <div class="stat-l">Total mesures</div>
    <div class="stat-v"><?= number_format($stats['total_mesures']) ?></div>
    <div class="stat-u">En base</div>
  </div>
</div>

<!-- Tabs -->
<div class="tabs">
  <div class="tab active" onclick="showTab('acc')">⚡ ACC (<?= count($accs) ?>)</div>
  <div class="tab" onclick="showTab('prod')">📡 Producteurs (<?= count($producteurs) ?>)</div>
  <div class="tab" onclick="showTab('cons')">👤 Consommateurs (<?= count($consommateurs) ?>)</div>
  <div class="tab" onclick="showTab('mesures')">📊 Mesures</div>
  <div class="tab" onclick="showTab('notifs')">🔔 Notifications</div>
  <div class="tab" onclick="showTab('create')">➕ Créer ACC</div>
</div>

<!-- Tab ACC -->
<div class="tab-content active" id="tab-acc">
  <div class="section">
    <div class="section-header">
      <span class="section-title">⚡ Groupes ACC</span>
      <span class="section-count"><?= count($accs) ?> groupe(s)</span>
    </div>
    <?php if (empty($accs)): ?>
    <div style="padding:30px;text-align:center;color:var(--muted);font-family:'Space Mono',monospace;font-size:.8rem;">
      Aucun ACC — cliquez sur "Créer ACC"
    </div>
    <?php else: ?>
    <div class="acc-grid">
      <?php foreach ($accs as $acc):
        $surplus = floatval($acc['surplus_now'] ?? 0);
        $isSurp  = $surplus >= SURPLUS_THRESHOLD;
      ?>
      <div class="acc-card <?= $isSurp ? 'surplus' : '' ?>">
        <div class="acc-card-top">
          <div>
            <div class="acc-card-name"><?= htmlspecialchars($acc['nom']) ?></div>
            <div class="acc-card-desc"><?= htmlspecialchars($acc['description'] ?? '') ?></div>
            <?php if ($acc['coordinateur']): ?>
            <div style="font-size:.65rem;color:var(--muted);margin-top:2px;font-family:'Space Mono',monospace;">
              👤 <?= htmlspecialchars($acc['coordinateur']) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($isSurp): ?>
          <span class="badge b-surplus">🌞 <?= number_format($surplus) ?>W</span>
          <?php else: ?>
          <span class="badge b-warn">🌙 Standby</span>
          <?php endif; ?>
        </div>
        <div class="acc-card-stats">
          <div class="acc-mini-stat">
            <div class="acc-mini-val"><?= $acc['nb_prod'] ?></div>
            <div class="acc-mini-lbl">Producteurs</div>
          </div>
          <div class="acc-mini-stat">
            <div class="acc-mini-val g"><?= $acc['nb_cons'] ?></div>
            <div class="acc-mini-lbl">Abonnés</div>
          </div>
          <div class="acc-mini-stat">
            <div class="acc-mini-val" style="font-size:.8rem;color:var(--muted);">
              <?= date('d/m', strtotime($acc['date_creation'])) ?>
            </div>
            <div class="acc-mini-lbl">Création</div>
          </div>
        </div>
        <div class="acc-card-actions">
          <a href="consumer.php?acc=<?= urlencode($acc['nom']) ?>" class="acc-link-view">
            👤 Espace conso
          </a>
          <a href="home.php#<?= urlencode($acc['nom']) ?>" class="acc-link-view">
            🏠 Accueil
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Tab Producteurs -->
<div class="tab-content" id="tab-prod">
  <div class="section">
    <div class="section-header">
      <span class="section-title">📡 Modules LoKy (Producteurs)</span>
      <span class="section-count"><?= count($producteurs) ?> module(s)</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>État</th>
            <th>Module ID</th>
            <th>ACC</th>
            <th>Puissance</th>
            <th>Surplus</th>
            <th>Tension</th>
            <th>Énergie IN</th>
            <th>Énergie OUT</th>
            <th>IP</th>
            <th>RSSI</th>
            <th>Firmware</th>
            <th>Dernière MAJ</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($producteurs)): ?>
          <tr class="empty-row"><td colspan="13">Aucun module enregistré</td></tr>
          <?php else: ?>
          <?php foreach ($producteurs as $p):
            $age = $p['derniere_mesure'] ? time() - strtotime($p['derniere_mesure']) : 9999;
            if ($age < 120)      { $dotC = 'dot-ok';   $badge = 'b-ok';   $label = 'En ligne'; }
            elseif ($age < 600)  { $dotC = 'dot-warn'; $badge = 'b-warn'; $label = 'Ralenti'; }
            else                 { $dotC = 'dot-dead'; $badge = 'b-dead'; $label = 'Hors ligne'; }
            $isSurp = boolval($p['est_surplus'] ?? 0);
          ?>
          <tr>
            <td><span class="dot <?= $dotC ?>"></span><span class="badge <?= $badge ?>"><?= $label ?></span></td>
            <td style="font-family:'Space Mono',monospace;color:var(--accent);"><?= htmlspecialchars($p['module_id']) ?></td>
            <td><span class="badge b-acc"><?= htmlspecialchars($p['acc_nom'] ?? '—') ?></span></td>
            <td class="<?= $isSurp ? 'pwr-surplus' : 'pwr-normal' ?>" style="font-family:'Space Mono',monospace;">
              <?= $isSurp ? '⚡ -' : '' ?><?= number_format(floatval($p['puissance'] ?? 0), 1) ?> W
            </td>
            <td style="font-family:'Space Mono',monospace;color:var(--green);">
              <?= $isSurp ? number_format(floatval($p['surplus'] ?? 0), 1) . ' W' : '—' ?>
            </td>
            <td style="font-family:'Space Mono',monospace;"><?= number_format(floatval($p['tension'] ?? 0), 1) ?> V</td>
            <td style="font-family:'Space Mono',monospace;"><?= number_format(floatval($p['energie_in'] ?? 0), 3) ?> kWh</td>
            <td style="font-family:'Space Mono',monospace;color:var(--green);"><?= number_format(floatval($p['energie_out'] ?? 0), 3) ?> kWh</td>
            <td style="font-family:'Space Mono',monospace;font-size:.7rem;"><?= htmlspecialchars($p['ip'] ?? '—') ?></td>
            <td style="font-family:'Space Mono',monospace;"><?= $p['rssi'] ?? '—' ?> dBm</td>
            <td style="font-family:'Space Mono',monospace;font-size:.65rem;"><?= htmlspecialchars($p['firmware'] ?? '—') ?></td>
            <td style="font-family:'Space Mono',monospace;font-size:.65rem;"><?= $p['derniere_mesure'] ?? '—' ?></td>
            <td>
              <a href="?del_prod=<?= $p['id'] ?>" class="btn btn-danger btn-sm"
                 onclick="return confirm('Supprimer ce module ?')">✕</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab Consommateurs -->
<div class="tab-content" id="tab-cons">
  <div class="section">
    <div class="section-header">
      <span class="section-title">👤 Consommateurs abonnés</span>
      <span class="section-count"><?= count($consommateurs) ?> abonné(s)</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Nom</th>
            <th>ACC</th>
            <th>Date inscription</th>
            <th>Dernière notif</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($consommateurs)): ?>
          <tr class="empty-row"><td colspan="5">Aucun abonné</td></tr>
          <?php else: ?>
          <?php foreach ($consommateurs as $c): ?>
          <tr>
            <td style="font-family:'Space Mono',monospace;">👤 <?= htmlspecialchars($c['nom']) ?></td>
            <td><span class="badge b-acc"><?= htmlspecialchars($c['acc_nom'] ?? '—') ?></span></td>
            <td style="font-family:'Space Mono',monospace;font-size:.72rem;"><?= $c['date_inscription'] ?></td>
            <td style="font-family:'Space Mono',monospace;font-size:.72rem;"><?= $c['derniere_notif'] ?? '—' ?></td>
            <td>
              <a href="?del_cons=<?= $c['id'] ?>" class="btn btn-danger btn-sm"
                 onclick="return confirm('Désabonner ?')">✕</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab Mesures -->
<div class="tab-content" id="tab-mesures">
  <div class="section">
    <div class="section-header">
      <span class="section-title">📊 Dernières mesures</span>
      <span class="section-count">50 dernières</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Module</th>
            <th>ACC</th>
            <th>Puissance</th>
            <th>Surplus</th>
            <th>Tension</th>
            <th>Courant</th>
            <th>E. IN</th>
            <th>E. OUT</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($mesures)): ?>
          <tr class="empty-row"><td colspan="9">Aucune mesure</td></tr>
          <?php else: ?>
          <?php foreach ($mesures as $m): ?>
          <tr>
            <td style="font-family:'Space Mono',monospace;font-size:.65rem;"><?= $m['timestamp'] ?></td>
            <td style="font-family:'Space Mono',monospace;color:var(--accent);"><?= htmlspecialchars($m['module_id']) ?></td>
            <td><span class="badge b-acc"><?= htmlspecialchars($m['acc_nom']) ?></span></td>
            <td style="font-family:'Space Mono',monospace;<?= $m['est_surplus'] ? 'color:var(--green)' : '' ?>">
              <?= $m['est_surplus'] ? '⚡ -' : '' ?><?= number_format(floatval($m['puissance']), 1) ?> W
            </td>
            <td style="font-family:'Space Mono',monospace;color:var(--green);">
              <?= $m['est_surplus'] ? number_format(floatval($m['surplus']), 1) . ' W' : '—' ?>
            </td>
            <td style="font-family:'Space Mono',monospace;"><?= number_format(floatval($m['tension']), 1) ?> V</td>
            <td style="font-family:'Space Mono',monospace;"><?= number_format(floatval($m['courant']), 3) ?> A</td>
            <td style="font-family:'Space Mono',monospace;"><?= number_format(floatval($m['energie_in']), 3) ?></td>
            <td style="font-family:'Space Mono',monospace;color:var(--green);"><?= number_format(floatval($m['energie_out']), 3) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab Notifications -->
<div class="tab-content" id="tab-notifs">
  <div class="section">
    <div class="section-header">
      <span class="section-title">🔔 Journal des notifications</span>
      <span class="section-count">20 dernières</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Timestamp</th><th>ACC</th><th>Surplus</th><th>Envoyées</th></tr>
        </thead>
        <tbody>
          <?php if (empty($notifs)): ?>
          <tr class="empty-row"><td colspan="4">Aucune notification envoyée</td></tr>
          <?php else: ?>
          <?php foreach ($notifs as $n): ?>
          <tr>
            <td style="font-family:'Space Mono',monospace;font-size:.7rem;"><?= $n['timestamp'] ?></td>
            <td><span class="badge b-acc"><?= htmlspecialchars($n['acc_nom']) ?></span></td>
            <td style="font-family:'Space Mono',monospace;color:var(--green);"><?= $n['surplus_w'] ?> W</td>
            <td style="font-family:'Space Mono',monospace;">📱 <?= $n['nb_envoyes'] ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab Créer ACC -->
<div class="tab-content" id="tab-create">
  <div class="section">
    <div class="section-header">
      <span class="section-title">➕ Créer un nouveau groupe ACC</span>
    </div>
    <div class="form-create">
      <form method="POST">
        <div class="form-row">
          <div class="form-group">
            <label>Nom de l'ACC *</label>
            <input type="text" name="nom" class="form-input"
                   placeholder="Ex: ACC_DUPONT" required
                   pattern="[A-Za-z0-9_-]+"
                   title="Lettres, chiffres, tirets et underscores uniquement">
          </div>
          <div class="form-group">
            <label>Coordinateur</label>
            <input type="text" name="coord" class="form-input" placeholder="Nom du responsable">
          </div>
          <div class="form-group">
            <label>Description</label>
            <input type="text" name="desc" class="form-input" placeholder="Description courte">
          </div>
        </div>
        <button type="submit" name="create_acc" class="btn btn-primary">
          ➕ Créer l'ACC
        </button>
      </form>

      <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--border);">
        <p style="font-size:.75rem;color:var(--muted);font-family:'Space Mono',monospace;line-height:1.8;">
          ℹ️ <strong style="color:var(--accent);">Comment ça marche :</strong><br>
          1. Créez l'ACC ici avec le nom exact<br>
          2. Donnez ce nom aux modules ESP32 lors de la configuration WiFi<br>
          3. Les modules s'enregistrent automatiquement au premier envoi<br>
          4. Les consommateurs rejoignent l'ACC via <a href="consumer.php" style="color:var(--accent);">consumer.php</a>
        </p>
      </div>
    </div>
  </div>
</div>

<div class="footer">
  LoKy ACC Admin &nbsp;·&nbsp; <?= date('d/m/Y H:i:s') ?> &nbsp;·&nbsp;
  <?= $stats['nb_acc'] ?> ACC · <?= $stats['nb_prod'] ?> modules · <?= $stats['nb_cons'] ?> abonnés
</div>

</div>

<script>
function showTab(name) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  event.target.classList.add('active');
}
// Auto-refresh 60s
setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>

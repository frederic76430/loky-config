<?php
// ================================================
// LoKy ACC — Page d'accueil
// home.php
// ================================================

require_once 'config.php';
$db = getDB();

// Tous les ACC avec stats
$accs = $db->query("
    SELECT a.*,
        COUNT(DISTINCT p.id) as nb_prod,
        COUNT(DISTINCT c.id) as nb_cons,
        COALESCE((
            SELECT SUM(m2.surplus)
            FROM mesures m2
            JOIN producteurs p2 ON m2.producteur_id = p2.id
            WHERE p2.acc_id = a.id
            AND m2.id IN (
                SELECT MAX(id) FROM mesures GROUP BY producteur_id
            )
        ), 0) as surplus_now,
        COALESCE((
            SELECT SUM(m3.energie_out)
            FROM mesures m3
            JOIN producteurs p3 ON m3.producteur_id = p3.id
            WHERE p3.acc_id = a.id
            AND m3.id IN (
                SELECT MAX(id) FROM mesures GROUP BY producteur_id
            )
        ), 0) as total_injecte
    FROM acc a
    LEFT JOIN producteurs p ON p.acc_id = a.id AND p.actif = 1
    LEFT JOIN consommateurs c ON c.acc_id = a.id AND c.actif = 1
    WHERE a.actif = 1
    GROUP BY a.id
    ORDER BY a.nom
")->fetchAll();

// Stats globales
$stats = $db->query("SELECT
    (SELECT COUNT(*) FROM acc WHERE actif = 1) as nb_acc,
    (SELECT COUNT(*) FROM producteurs WHERE actif = 1) as nb_prod,
    (SELECT COUNT(*) FROM consommateurs WHERE actif = 1) as nb_cons,
    (SELECT COUNT(*) FROM mesures WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as mesures_24h
")->fetch();

$total_surplus = array_sum(array_column($accs, 'surplus_now'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#050d1a">
<title>LoKy ACC — Accueil</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;--green:#00ff9d;
  --warn:#ffaa00;--danger:#ff3860;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;min-height:100vh;}
body::after{content:'';position:fixed;inset:0;
  background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);
  background-size:28px 28px;opacity:.12;pointer-events:none;z-index:0;}
.wrap{position:relative;z-index:1;max-width:1100px;margin:0 auto;padding:20px 16px;}

/* Header */
.header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;
  padding:20px 28px;background:var(--surface);border:1px solid var(--border);
  border-radius:16px;margin-bottom:24px;box-shadow:0 0 40px rgba(0,212,255,.1);}
.logo{display:flex;align-items:center;gap:14px;}
.logo-icon{width:52px;height:52px;background:linear-gradient(135deg,var(--accent),var(--green));
  border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:26px;
  box-shadow:0 0 20px rgba(0,212,255,.4);}
.logo h1{font-size:1.6rem;font-weight:800;
  background:linear-gradient(90deg,var(--accent),var(--green));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.logo p{font-size:.7rem;color:var(--muted);font-family:'Space Mono',monospace;
  letter-spacing:2px;margin-top:2px;}
.nav{display:flex;gap:8px;flex-wrap:wrap;}
.nav a{padding:10px 18px;border-radius:10px;border:1px solid var(--border);
  background:var(--card);color:var(--muted);font-family:'Space Mono',monospace;
  font-size:.7rem;text-decoration:none;transition:all .2s;}
.nav a:hover{border-color:var(--accent);color:var(--accent);}
.nav a.primary{background:linear-gradient(135deg,var(--accent),var(--green));
  color:var(--bg);border:none;font-weight:700;}

/* Hero surplus global */
.hero{padding:28px;background:var(--surface);border:1px solid var(--border);
  border-radius:16px;margin-bottom:24px;text-align:center;position:relative;overflow:hidden;}
.hero.active{border-color:rgba(0,255,157,.4);
  background:linear-gradient(135deg,rgba(0,255,157,.05),rgba(0,212,255,.03));}
.hero.active::before{content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at center,rgba(0,255,157,.06) 0%,transparent 70%);
  animation:glow 3s infinite;}
@keyframes glow{0%,100%{opacity:.5}50%{opacity:1}}
.hero-icon{font-size:4rem;margin-bottom:10px;}
.hero-power{font-family:'Space Mono',monospace;font-size:3.5rem;font-weight:700;line-height:1;}
.hero-power.active{color:var(--green);}
.hero-power.inactive{color:var(--muted);}
.hero-label{font-size:1rem;margin-top:10px;color:var(--muted);}
.hero-label.active{color:var(--green);}

/* Stats */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
  gap:14px;margin-bottom:24px;}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:12px;
  padding:16px;text-align:center;position:relative;overflow:hidden;}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--accent),var(--green));}
.stat-l{font-size:.6rem;color:var(--muted);text-transform:uppercase;
  letter-spacing:2px;font-family:'Space Mono',monospace;margin-bottom:6px;}
.stat-v{font-family:'Space Mono',monospace;font-size:1.8rem;font-weight:700;color:var(--accent);}
.stat-v.g{color:var(--green);}
.stat-u{font-size:.68rem;color:var(--muted);margin-top:2px;}

/* Section titre */
.section-title{font-size:.65rem;color:var(--muted);text-transform:uppercase;
  letter-spacing:3px;font-family:'Space Mono',monospace;margin-bottom:16px;
  display:flex;align-items:center;gap:10px;}
.section-title::after{content:'';flex:1;height:1px;background:var(--border);}

/* Grille ACC */
.accs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;}

/* Carte ACC */
.acc-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;
  overflow:hidden;transition:all .3s;cursor:pointer;}
.acc-card:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,212,255,.1);}
.acc-card.surplus{border-color:rgba(0,255,157,.4);}
.acc-card.surplus .acc-top{background:linear-gradient(135deg,rgba(0,255,157,.08),rgba(0,212,255,.04));}

/* Top carte */
.acc-top{padding:20px;border-bottom:1px solid var(--border);}
.acc-top-row{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px;}
.acc-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;
  justify-content:center;font-size:22px;flex-shrink:0;margin-right:12px;}
.acc-icon.active{background:rgba(0,255,157,.15);border:1px solid rgba(0,255,157,.3);}
.acc-icon.inactive{background:var(--card);border:1px solid var(--border);}
.acc-info{flex:1;}
.acc-name{font-family:'Space Mono',monospace;font-size:1rem;font-weight:700;color:var(--accent);}
.acc-desc{font-size:.75rem;color:var(--muted);margin-top:3px;}
.acc-coord{font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:2px;}

/* Badge surplus */
.surplus-badge{padding:6px 14px;border-radius:20px;font-family:'Space Mono',monospace;
  font-size:.75rem;font-weight:700;white-space:nowrap;}
.sb-active{background:rgba(0,255,157,.15);color:var(--green);border:1px solid rgba(0,255,157,.3);}
.sb-inactive{background:var(--card);color:var(--muted);border:1px solid var(--border);}

/* Barre puissance */
.power-bar-wrap{margin-bottom:0;}
.power-bar-labels{display:flex;justify-content:space-between;
  font-family:'Space Mono',monospace;font-size:.65rem;color:var(--muted);margin-bottom:5px;}
.power-bar-labels span.active{color:var(--green);font-weight:700;}
.power-track{height:6px;background:var(--border);border-radius:3px;overflow:hidden;}
.power-fill{height:100%;border-radius:3px;transition:width .5s ease;}
.power-fill.active{background:linear-gradient(90deg,var(--green),var(--accent));
  box-shadow:0 0 8px rgba(0,255,157,.4);}
.power-fill.inactive{width:0;}

/* Mini stats ACC */
.acc-stats{display:grid;grid-template-columns:repeat(3,1fr);
  padding:14px 20px;border-bottom:1px solid var(--border);gap:10px;}
.mini-stat{text-align:center;}
.mini-val{font-family:'Space Mono',monospace;font-size:1.1rem;font-weight:700;color:var(--text);}
.mini-val.g{color:var(--green);}
.mini-val.a{color:var(--accent);}
.mini-lbl{font-size:.58rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-transform:uppercase;letter-spacing:1px;margin-top:2px;}

/* Membres */
.acc-members{padding:14px 20px;border-bottom:1px solid var(--border);}
.members-label{font-size:.6rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-transform:uppercase;letter-spacing:2px;margin-bottom:10px;}
.member-row{display:flex;align-items:center;justify-content:space-between;
  padding:7px 10px;background:var(--card);border-radius:8px;margin-bottom:5px;}
.member-dot{width:8px;height:8px;border-radius:50%;margin-right:8px;flex-shrink:0;}
.dot-ok{background:var(--green);box-shadow:0 0 5px var(--green);}
.dot-warn{background:var(--warn);}
.dot-dead{background:var(--danger);}
.member-name{font-family:'Space Mono',monospace;font-size:.75rem;color:var(--text);}
.member-pwr{font-family:'Space Mono',monospace;font-size:.75rem;font-weight:700;}
.member-pwr.s{color:var(--green);}
.member-pwr.c{color:var(--warn);}

/* Abonnés */
.acc-subs{padding:12px 20px;border-bottom:1px solid var(--border);}
.subs-row{display:flex;align-items:center;justify-content:space-between;}
.subs-avatars{display:flex;gap:5px;}
.sub-av{width:30px;height:30px;border-radius:50%;background:rgba(0,212,255,.1);
  border:1px solid rgba(0,212,255,.3);display:flex;align-items:center;
  justify-content:center;font-size:13px;}
.sub-empty{font-size:.72rem;color:var(--muted);font-family:'Space Mono',monospace;font-style:italic;}

/* Footer carte */
.acc-footer{display:flex;gap:8px;padding:14px 20px;}
.acc-btn{flex:1;text-align:center;padding:10px;border-radius:8px;text-decoration:none;
  font-size:.8rem;font-family:'Outfit',monospace;font-weight:600;transition:all .2s;border:none;cursor:pointer;}
.acc-btn-main{background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);}
.acc-btn-main:hover{box-shadow:0 0 15px rgba(0,212,255,.3);}
.acc-btn-sec{background:var(--card);color:var(--muted);border:1px solid var(--border);}
.acc-btn-sec:hover{border-color:var(--accent);color:var(--accent);}

/* Empty */
.empty{text-align:center;padding:60px 20px;background:var(--surface);
  border:1px dashed var(--border);border-radius:16px;}
.empty .ico{font-size:3rem;margin-bottom:16px;}

/* Footer page */
.footer{text-align:center;padding:24px;margin-top:28px;
  font-family:'Space Mono',monospace;font-size:.62rem;color:var(--muted);
  border-top:1px solid var(--border);}

/* Live dot */
.live{display:flex;align-items:center;gap:8px;font-family:'Space Mono',monospace;
  font-size:.65rem;color:var(--green);}
.live-dot{width:8px;height:8px;border-radius:50%;background:var(--green);
  box-shadow:0 0 6px var(--green);animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

@media(max-width:600px){
  .accs-grid{grid-template-columns:1fr;}
  .header{flex-direction:column;}
  .stats{grid-template-columns:1fr 1fr;}
  .hero-power{font-size:2.5rem;}
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
      <p>Portail Autoconsommation Collective</p>
    </div>
  </div>
  <div class="nav">
    <div class="live"><div class="live-dot"></div><?= date('H:i:s') ?></div>
    <a href="consumer.php" class="primary">👤 Mon espace</a>
    <a href="admin.php">⚙️ Admin</a>
    <a href="?">↺</a>
  </div>
</div>

<!-- Hero surplus global -->
<?php $isSurplus = $total_surplus >= SURPLUS_THRESHOLD; ?>
<div class="hero <?= $isSurplus ? 'active' : '' ?>">
  <div class="hero-icon"><?= $isSurplus ? '🌞' : '🌙' ?></div>
  <div class="hero-power <?= $isSurplus ? 'active' : 'inactive' ?>">
    <?= number_format($total_surplus) ?> W
  </div>
  <div class="hero-label <?= $isSurplus ? 'active' : '' ?>">
    <?= $isSurplus
      ? '⚡ Surplus disponible — Les consommateurs peuvent brancher leurs appareils !'
      : 'Pas de surplus pour le moment (seuil : ' . SURPLUS_THRESHOLD . 'W)' ?>
  </div>
</div>

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
    <div class="stat-v g"><?= $stats['nb_cons'] ?></div>
    <div class="stat-u">Abonnés Push</div>
  </div>
  <div class="stat">
    <div class="stat-l">Mesures 24h</div>
    <div class="stat-v"><?= number_format($stats['mesures_24h']) ?></div>
    <div class="stat-u">Points enregistrés</div>
  </div>
</div>

<!-- Grille ACC -->
<div class="section-title">Groupes ACC</div>

<?php if (empty($accs)): ?>
<div class="empty">
  <div class="ico">📡</div>
  <h2 style="color:var(--muted);font-weight:400;margin-bottom:8px;">Aucun ACC enregistré</h2>
  <p style="color:var(--muted);font-size:.8rem;font-family:'Space Mono',monospace;">
    Créez votre premier ACC dans l'<a href="admin.php" style="color:var(--accent);">interface admin</a>
  </p>
</div>

<?php else: ?>
<div class="accs-grid">
<?php
// Pour chaque ACC, récupérer les producteurs avec dernière mesure
foreach ($accs as $acc):
  $surplus   = floatval($acc['surplus_now']);
  $isSurp    = $surplus >= SURPLUS_THRESHOLD;
  $pct       = min(100, round($surplus / 3000 * 100));

  // Producteurs de cet ACC
  $stmt = $db->prepare("
    SELECT p.module_id, p.rssi,
           m.puissance, m.surplus as m_surplus, m.est_surplus,
           m.timestamp as derniere_mesure
    FROM producteurs p
    LEFT JOIN mesures m ON m.id = (
        SELECT id FROM mesures WHERE producteur_id = p.id ORDER BY timestamp DESC LIMIT 1
    )
    WHERE p.acc_id = ? AND p.actif = 1
    ORDER BY p.module_id
  ");
  $stmt->execute([$acc['id']]);
  $membres = $stmt->fetchAll();

  // Abonnés
  $stmt2 = $db->prepare("SELECT nom FROM consommateurs WHERE acc_id = ? AND actif = 1 LIMIT 8");
  $stmt2->execute([$acc['id']]);
  $subs = $stmt2->fetchAll();
?>
<div class="acc-card <?= $isSurp ? 'surplus' : '' ?>"
     onclick="window.location='consumer.php?acc=<?= urlencode($acc['nom']) ?>'">

  <!-- Top -->
  <div class="acc-top">
    <div class="acc-top-row">
      <div style="display:flex;align-items:flex-start;">
        <div class="acc-icon <?= $isSurp ? 'active' : 'inactive' ?>">
          <?= $isSurp ? '🌞' : '⚡' ?>
        </div>
        <div class="acc-info">
          <div class="acc-name"><?= htmlspecialchars($acc['nom']) ?></div>
          <?php if ($acc['description']): ?>
          <div class="acc-desc"><?= htmlspecialchars($acc['description']) ?></div>
          <?php endif; ?>
          <?php if ($acc['coordinateur']): ?>
          <div class="acc-coord">👤 <?= htmlspecialchars($acc['coordinateur']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <span class="surplus-badge <?= $isSurp ? 'sb-active' : 'sb-inactive' ?>">
        <?= $isSurp ? '🌞 ' . number_format($surplus) . 'W' : '🌙 Standby' ?>
      </span>
    </div>
    <!-- Barre puissance -->
    <div class="power-bar-wrap">
      <div class="power-bar-labels">
        <span>Surplus disponible</span>
        <span class="<?= $isSurp ? 'active' : '' ?>"><?= number_format($surplus) ?> W</span>
      </div>
      <div class="power-track">
        <div class="power-fill <?= $isSurp ? 'active' : 'inactive' ?>"
             style="width:<?= $pct ?>%"></div>
      </div>
    </div>
  </div>

  <!-- Mini stats -->
  <div class="acc-stats">
    <div class="mini-stat">
      <div class="mini-val a"><?= $acc['nb_prod'] ?></div>
      <div class="mini-lbl">Producteurs</div>
    </div>
    <div class="mini-stat">
      <div class="mini-val g"><?= $acc['nb_cons'] ?></div>
      <div class="mini-lbl">Abonnés</div>
    </div>
    <div class="mini-stat">
      <div class="mini-val" style="font-size:.85rem;">
        <?= number_format(floatval($acc['total_injecte']), 1) ?>
      </div>
      <div class="mini-lbl">kWh injectés</div>
    </div>
  </div>

  <!-- Membres producteurs -->
  <div class="acc-members">
    <div class="members-label">⚡ Producteurs</div>
    <?php if (empty($membres)): ?>
    <div style="font-size:.72rem;color:var(--muted);font-style:italic;">Aucun module enregistré</div>
    <?php else: ?>
    <?php foreach ($membres as $m):
      $age     = $m['derniere_mesure'] ? time() - strtotime($m['derniere_mesure']) : 9999;
      $dotC    = $age < 120 ? 'dot-ok' : ($age < 600 ? 'dot-warn' : 'dot-dead');
      $pwr     = floatval($m['puissance'] ?? 0);
      $isSurpM = boolval($m['est_surplus'] ?? 0);
    ?>
    <div class="member-row">
      <div style="display:flex;align-items:center;">
        <div class="member-dot <?= $dotC ?>"></div>
        <span class="member-name"><?= htmlspecialchars($m['module_id']) ?></span>
      </div>
      <span class="member-pwr <?= $isSurpM ? 's' : 'c' ?>">
        <?= $isSurpM ? '⚡ -' : '+' ?><?= number_format(abs($pwr), 1) ?> W
      </span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Abonnés consommateurs -->
  <div class="acc-subs">
    <div class="members-label" style="margin-bottom:8px;">📱 Consommateurs abonnés</div>
    <div class="subs-row">
      <?php if (empty($subs)): ?>
      <span class="sub-empty">Aucun abonné</span>
      <?php else: ?>
      <div class="subs-avatars">
        <?php foreach (array_slice($subs, 0, 6) as $sub): ?>
        <div class="sub-av" title="<?= htmlspecialchars($sub['nom']) ?>">👤</div>
        <?php endforeach; ?>
        <?php if (count($subs) > 6): ?>
        <div class="sub-av">+<?= count($subs) - 6 ?></div>
        <?php endif; ?>
      </div>
      <span style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--muted);">
        <?= count($subs) ?> abonné(s)
      </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Boutons -->
  <div class="acc-footer" onclick="event.stopPropagation()">
    <a href="consumer.php?acc=<?= urlencode($acc['nom']) ?>" class="acc-btn acc-btn-main">
      👤 Rejoindre cet ACC
    </a>
    <a href="admin.php" class="acc-btn acc-btn-sec">⚙️ Admin</a>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="footer">
  LoKy ACC &nbsp;·&nbsp; <?= date('d/m/Y H:i:s') ?> &nbsp;·&nbsp;
  <?= $stats['nb_acc'] ?> ACC · <?= $stats['nb_prod'] ?> modules · <?= $stats['nb_cons'] ?> abonnés
  &nbsp;·&nbsp; <a href="admin.php" style="color:var(--accent);text-decoration:none;">Admin</a>
</div>

</div>
<script>
// Auto-refresh 30s
setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>

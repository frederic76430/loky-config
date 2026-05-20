<?php
// ================================================
// LoKy ACC — Espace Producteur
// producer.php
// ================================================

require_once 'auth.php';
requireLogin();
if (!isProducteur() && !isAdmin()) {
    header('Location: login.php'); exit;
}

$db   = getDB();
$user = getUser();

// Récupérer producteur lié au compte
$stmt = $db->prepare("
    SELECT p.*, a.nom as acc_nom
    FROM producteurs p
    LEFT JOIN acc a ON p.acc_id = a.id
    WHERE p.module_id = ?
");
$stmt->execute([$user['numero_compteur']]);
$producteur = $stmt->fetch();

// Dernières mesures
$mesures = [];
$stats   = null;
if ($producteur) {
    $stmt = $db->prepare("
        SELECT * FROM mesures
        WHERE producteur_id = ?
        ORDER BY timestamp DESC LIMIT 50
    ");
    $stmt->execute([$producteur['id']]);
    $mesures = $stmt->fetchAll();

    // Stats 24h
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as nb_mesures,
            AVG(ABS(puissance)) as avg_pwr,
            MAX(surplus) as max_surplus,
            SUM(CASE WHEN est_surplus=1 THEN 1 ELSE 0 END) as nb_surplus,
            MAX(energie_out) as total_injecte,
            MAX(energie_in) as total_consomme,
            AVG(tension) as avg_tension
        FROM mesures
        WHERE producteur_id = ?
        AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$producteur['id']]);
    $stats = $stmt->fetch();

    // Graphique 24h
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(timestamp, '%H:00') as heure,
               AVG(ABS(puissance)) as avg_pwr,
               MAX(surplus) as max_surplus
        FROM mesures
        WHERE producteur_id = ?
        AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY DATE_FORMAT(timestamp, '%Y-%m-%d %H')
        ORDER BY heure
    ");
    $stmt->execute([$producteur['id']]);
    $graphdata = $stmt->fetchAll();
}

// Dernière mesure
$lastMesure = $mesures[0] ?? null;
$isSurplus  = boolval($lastMesure['est_surplus'] ?? false);
$surplus    = floatval($lastMesure['surplus'] ?? 0);
$puissance  = floatval($lastMesure['puissance'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LoKy ACC — Mon installation</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;--green:#00ff9d;
  --warn:#ffaa00;--danger:#ff3860;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;min-height:100vh;}
body::after{content:'';position:fixed;inset:0;
  background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);
  background-size:28px 28px;opacity:.12;pointer-events:none;z-index:0;}
.wrap{position:relative;z-index:1;max-width:900px;margin:0 auto;padding:20px 16px;}

.header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;
  padding:14px 20px;background:var(--surface);border:1px solid var(--border);
  border-radius:14px;margin-bottom:18px;}
.header-left{display:flex;align-items:center;gap:10px;}
.logo-icon{width:40px;height:40px;background:linear-gradient(135deg,var(--accent),var(--green));
  border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;}
.user-info h2{font-size:.95rem;font-weight:700;}
.user-info p{font-size:.62rem;color:var(--muted);font-family:'Space Mono',monospace;}
.nav{display:flex;gap:6px;}
.nav-btn{padding:7px 12px;border-radius:8px;border:1px solid var(--border);
  background:var(--card);color:var(--muted);font-family:'Space Mono',monospace;
  font-size:.62rem;text-decoration:none;transition:all .2s;}
.nav-btn:hover{border-color:var(--accent);color:var(--accent);}
.nav-btn.danger:hover{border-color:var(--danger);color:var(--danger);}

.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;
  padding:20px;margin-bottom:14px;position:relative;overflow:hidden;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--accent),var(--green));}
.card-title{font-size:.95rem;font-weight:700;color:var(--accent);margin-bottom:14px;
  display:flex;align-items:center;gap:8px;}

/* Pas de module */
.no-module{text-align:center;padding:40px;}
.no-module .ico{font-size:3rem;margin-bottom:14px;}
.no-module h3{color:var(--muted);font-weight:400;margin-bottom:8px;}
.no-module p{font-size:.8rem;color:var(--muted);font-family:'Space Mono',monospace;line-height:1.6;}
.info-box{background:rgba(0,212,255,.05);border:1px solid rgba(0,212,255,.2);
  border-radius:10px;padding:14px;margin-top:16px;text-align:left;}
.info-box h4{color:var(--accent);font-size:.85rem;margin-bottom:8px;}
.info-row{display:flex;gap:10px;padding:5px 0;border-bottom:1px solid var(--border);font-size:.78rem;}
.info-row:last-child{border-bottom:none;}
.info-key{color:var(--muted);font-family:'Space Mono',monospace;min-width:120px;}
.info-val{color:var(--text);font-weight:600;}

/* Banner */
.banner{border-radius:14px;padding:20px;text-align:center;margin-bottom:14px;
  position:relative;overflow:hidden;}
.banner.active{background:linear-gradient(135deg,rgba(0,255,157,.1),rgba(0,212,255,.04));
  border:1px solid rgba(0,255,157,.3);}
.banner.active::before{content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at center,rgba(0,255,157,.07),transparent 70%);
  animation:glow 3s infinite;}
@keyframes glow{0%,100%{opacity:.5}50%{opacity:1}}
.banner.inactive{background:var(--surface);border:1px solid var(--border);}
.banner-power{font-family:'Space Mono',monospace;font-size:2.5rem;font-weight:700;}
.banner-power.active{color:var(--green);}
.banner-power.inactive{color:var(--muted);}
.banner-label{font-size:.82rem;margin-top:6px;}
.banner-label.active{color:var(--green);}
.banner-label.inactive{color:var(--muted);}

/* Stats grid */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px;}
.stat{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center;}
.stat-l{font-size:.58rem;color:var(--muted);text-transform:uppercase;letter-spacing:2px;
  font-family:'Space Mono',monospace;margin-bottom:4px;}
.stat-v{font-family:'Space Mono',monospace;font-size:1.2rem;font-weight:700;color:var(--accent);}
.stat-v.g{color:var(--green);}
.stat-u{font-size:.62rem;color:var(--muted);}

/* Module info */
.module-info{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
.info-item{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px 12px;}
.info-item-l{font-size:.6rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;}
.info-item-v{font-family:'Space Mono',monospace;font-size:.8rem;color:var(--text);}
.info-item-v.ok{color:var(--green);}
.info-item-v.warn{color:var(--warn);}

/* Graphique */
.chart-wrap{height:120px;position:relative;margin-bottom:8px;}

/* Table mesures */
.sec-title{font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:3px;
  font-family:'Space Mono',monospace;margin-bottom:10px;
  display:flex;align-items:center;gap:8px;}
.sec-title::after{content:'';flex:1;height:1px;background:var(--border);}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{padding:8px 12px;text-align:left;font-family:'Space Mono',monospace;font-size:.58rem;
  color:var(--muted);text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid var(--border);}
td{padding:8px 12px;font-size:.75rem;border-bottom:1px solid var(--border);font-family:'Space Mono',monospace;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--card);}
.surplus-row td{color:var(--green);}

@media(max-width:600px){
  .header{flex-direction:column;}
  .module-info{grid-template-columns:1fr;}
  .stats{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="header">
  <div class="header-left">
    <div class="logo-icon">⚡</div>
    <div class="user-info">
      <h2><?= htmlspecialchars($user['nom']) ?></h2>
      <p>Producteur
        <?php if ($user['acc_nom']): ?>
        · <span style="color:var(--accent)"><?= htmlspecialchars($user['acc_nom']) ?></span>
        <?php endif; ?>
        · N°<?= htmlspecialchars($user['numero_compteur']) ?>
      </p>
    </div>
  </div>
  <div class="nav">
    <a href="home.php" class="nav-btn">🏠 Accueil</a>
    <a href="logout.php" class="nav-btn danger">Déconnexion</a>
  </div>
</div>

<?php if (!$producteur): ?>
<!-- Module pas encore enregistré -->
<div class="card">
  <div class="no-module">
    <div class="ico">📡</div>
    <h3>Module LoKy non détecté</h3>
    <p>
      Votre module LoKy n'a pas encore envoyé de données.<br>
      Assurez-vous qu'il est bien configuré avec votre numéro de compteur.
    </p>
    <div class="info-box">
      <h4>Configuration du module LoKy</h4>
      <div class="info-row">
        <span class="info-key">ID Module :</span>
        <span class="info-val"><?= htmlspecialchars($user['numero_compteur']) ?></span>
      </div>
      <div class="info-row">
        <span class="info-key">ACC :</span>
        <span class="info-val"><?= htmlspecialchars($user['acc_nom'] ?? 'Non défini') ?></span>
      </div>
      <div class="info-row">
        <span class="info-key">URL serveur :</span>
        <span class="info-val">https://<?= $_SERVER['HTTP_HOST'] ?>/fred/loky/api.php</span>
      </div>
      <div class="info-row">
        <span class="info-key">Firmware :</span>
        <span class="info-val">LoKy v3.0</span>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Module détecté -->

<!-- Statut temps réel -->
<div class="banner <?= $isSurplus ? 'active' : 'inactive' ?>">
  <div style="font-size:2.5rem;margin-bottom:6px;"><?= $isSurplus ? '🌞' : '⚡' ?></div>
  <div class="banner-power <?= $isSurplus ? 'active' : 'inactive' ?>">
    <?= $isSurplus ? number_format($surplus, 1) . ' W injectés' : number_format(abs($puissance), 1) . ' W' ?>
  </div>
  <div class="banner-label <?= $isSurplus ? 'active' : 'inactive' ?>">
    <?= $isSurplus
      ? '⚡ Surplus en cours — vos consommateurs sont notifiés !'
      : ($puissance < 0 ? 'Injection réseau' : 'Consommation') ?>
  </div>
  <?php if ($lastMesure): ?>
  <div style="font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:6px;">
    Dernière mesure : <?= $lastMesure['timestamp'] ?>
  </div>
  <?php endif; ?>
</div>

<!-- Infos module -->
<div class="card">
  <div class="card-title">📡 Mon module LoKy</div>
  <div class="module-info">
    <div class="info-item">
      <div class="info-item-l">Module ID</div>
      <div class="info-item-v ok"><?= htmlspecialchars($producteur['module_id']) ?></div>
    </div>
    <div class="info-item">
      <div class="info-item-l">ACC</div>
      <div class="info-item-v ok"><?= htmlspecialchars($producteur['acc_nom'] ?? '—') ?></div>
    </div>
    <div class="info-item">
      <div class="info-item-l">Adresse IP</div>
      <div class="info-item-v"><?= htmlspecialchars($producteur['ip'] ?? '—') ?></div>
    </div>
    <div class="info-item">
      <div class="info-item-l">WiFi (RSSI)</div>
      <div class="info-item-v <?= ($producteur['rssi'] ?? 0) > -70 ? 'ok' : 'warn' ?>">
        <?= $producteur['rssi'] ?? '—' ?> dBm
      </div>
    </div>
    <div class="info-item">
      <div class="info-item-l">Firmware</div>
      <div class="info-item-v"><?= htmlspecialchars($producteur['firmware'] ?? '—') ?></div>
    </div>
    <div class="info-item">
      <div class="info-item-l">Dernière vue</div>
      <div class="info-item-v"><?= $producteur['derniere_vue'] ?? '—' ?></div>
    </div>
  </div>
</div>

<!-- Stats 24h -->
<?php if ($stats): ?>
<div class="stats">
  <div class="stat">
    <div class="stat-l">Puissance moy.</div>
    <div class="stat-v"><?= number_format(floatval($stats['avg_pwr']), 1) ?></div>
    <div class="stat-u">Watts (24h)</div>
  </div>
  <div class="stat">
    <div class="stat-l">Max surplus</div>
    <div class="stat-v g"><?= number_format(floatval($stats['max_surplus']), 1) ?></div>
    <div class="stat-u">Watts</div>
  </div>
  <div class="stat">
    <div class="stat-l">Total injecté</div>
    <div class="stat-v g"><?= number_format(floatval($stats['total_injecte']), 3) ?></div>
    <div class="stat-u">kWh</div>
  </div>
  <div class="stat">
    <div class="stat-l">Total consommé</div>
    <div class="stat-v"><?= number_format(floatval($stats['total_consomme']), 3) ?></div>
    <div class="stat-u">kWh</div>
  </div>
  <div class="stat">
    <div class="stat-l">Tension moy.</div>
    <div class="stat-v"><?= number_format(floatval($stats['avg_tension']), 1) ?></div>
    <div class="stat-u">Volts</div>
  </div>
  <div class="stat">
    <div class="stat-l">Mesures</div>
    <div class="stat-v"><?= number_format($stats['nb_mesures']) ?></div>
    <div class="stat-u">Points 24h</div>
  </div>
</div>
<?php endif; ?>

<!-- Graphique 24h -->
<?php if (!empty($graphdata)): ?>
<div class="card">
  <div class="card-title">📊 Production 24 heures</div>
  <div class="chart-wrap"><canvas id="chart"></canvas></div>
  <div style="font-size:.6rem;color:var(--muted);font-family:'Space Mono',monospace;
              text-align:center;margin-top:6px;">Puissance moyenne par heure (W)</div>
</div>
<?php endif; ?>

<!-- Dernières mesures -->
<div class="card">
  <div class="card-title">📋 Dernières mesures</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>Puissance</th>
          <th>Surplus</th>
          <th>Tension</th>
          <th>Courant</th>
          <th>E. IN</th>
          <th>E. OUT</th>
          <th>cos φ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($mesures, 0, 30) as $m): ?>
        <tr class="<?= $m['est_surplus'] ? 'surplus-row' : '' ?>">
          <td><?= $m['timestamp'] ?></td>
          <td><?= $m['est_surplus'] ? '⚡ -' : '' ?><?= number_format(floatval($m['puissance']), 1) ?> W</td>
          <td><?= $m['est_surplus'] ? number_format(floatval($m['surplus']), 1) . ' W' : '—' ?></td>
          <td><?= number_format(floatval($m['tension']), 1) ?> V</td>
          <td><?= number_format(floatval($m['courant']), 3) ?> A</td>
          <td><?= number_format(floatval($m['energie_in']), 3) ?></td>
          <td style="color:var(--green)"><?= number_format(floatval($m['energie_out']), 3) ?></td>
          <td><?= number_format(floatval($m['facteur_puiss']), 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

</div>

<script>
<?php if (!empty($graphdata)): ?>
window.addEventListener('load', () => {
  const data   = <?= json_encode($graphdata) ?>;
  const canvas = document.getElementById('chart');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const w = canvas.offsetWidth; const h = canvas.offsetHeight;
  canvas.width = w; canvas.height = h;
  const pwrs = data.map(d => Math.abs(parseFloat(d.avg_pwr||0)));
  const max  = Math.max(...pwrs, 1);
  const barW = (w-30)/data.length;
  const padL=30,padB=18,padT=8,cH=h-padB-padT;
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
});
<?php endif; ?>
// Auto-refresh 30s
setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>

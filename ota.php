<?php
// ================================================
// LoKy ACC — Gestion OTA / Firmware
// ota.php — Accès admin uniquement
// ================================================

require_once 'auth.php';
requireAdmin();

$firmware_dir = __DIR__ . '/firmware/';
$config_file  = $firmware_dir . 'config.json';
$msg_ok = $msg_err = '';

// Créer le dossier firmware si absent
if (!is_dir($firmware_dir)) mkdir($firmware_dir, 0755, true);

// Lire config actuelle
$config = [];
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true) ?? [];
}

// ---- Upload nouveau firmware ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['firmware'])) {
    $file    = $_FILES['firmware'];
    $version = trim($_POST['version'] ?? '');
    $api_url = trim($_POST['api_url'] ?? $config['api_url'] ?? '');
    $force   = isset($_POST['force_update']);
    $message = trim($_POST['message'] ?? '');

    if (!$version || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        $msg_err = 'Version invalide (format: X.Y.Z)';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $msg_err = 'Erreur upload : ' . $file['error'];
    } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'bin') {
        $msg_err = 'Fichier .bin requis';
    } elseif ($file['size'] > 2 * 1024 * 1024) {
        $msg_err = 'Fichier trop grand (max 2MB)';
    } else {
        $dest = $firmware_dir . 'loky_v' . $version . '.bin';
        if (move_uploaded_file($file['tmp_name'], $dest)) {

            // Mettre à jour config.json
            $newConfig = [
                'firmware_version' => $version,
                'api_url'          => $api_url,
                'update_url'       => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/firmware/',
                'min_version'      => $config['min_version'] ?? '3.0.0',
                'force_update'     => $force,
                'message'          => $message,
                'updated'          => date('Y-m-d H:i:s'),
                'size'             => $file['size'],
                'md5'              => md5_file($dest),
            ];

            file_put_contents($config_file, json_encode($newConfig, JSON_PRETTY_PRINT));
            $config  = $newConfig;
            $msg_ok  = "Firmware v$version uploadé ! Les modules se mettront à jour automatiquement.";
        } else {
            $msg_err = 'Erreur déplacement fichier';
        }
    }
}

// ---- Modifier uniquement l'URL API ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_api_only'])) {
    $api_url = trim($_POST['api_url_only'] ?? '');
    if ($api_url && filter_var($api_url, FILTER_VALIDATE_URL)) {
        $config['api_url'] = $api_url;
        $config['updated'] = date('Y-m-d H:i:s');
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
        $msg_ok = "URL API mise à jour ! Les modules récupèreront la nouvelle URL dans l'heure.";
    } else {
        $msg_err = 'URL invalide';
    }
}

// ---- Supprimer un firmware ----
if (isset($_GET['del'])) {
    $f = basename($_GET['del']);
    if (preg_match('/^loky_v[\d.]+\.bin$/', $f)) {
        unlink($firmware_dir . $f);
        header('Location: ota.php'); exit;
    }
}

// Lister les firmwares disponibles
$firmwares = [];
foreach (glob($firmware_dir . '*.bin') as $f) {
    $firmwares[] = [
        'name'    => basename($f),
        'size'    => filesize($f),
        'date'    => date('Y-m-d H:i', filemtime($f)),
        'version' => preg_replace('/loky_v([\d.]+)\.bin/', '$1', basename($f)),
    ];
}
usort($firmwares, fn($a, $b) => version_compare($b['version'], $a['version']));

// Compter les modules connectés
$db = getDB();
$modules = $db->query("SELECT module_id, firmware, derniere_vue FROM producteurs WHERE actif=1 ORDER BY module_id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LoKy ACC — OTA Firmware</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;--green:#00ff9d;
  --warn:#ffaa00;--danger:#ff3860;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;min-height:100vh;}
body::after{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);background-size:28px 28px;opacity:.12;pointer-events:none;}
.wrap{position:relative;z-index:1;max-width:1000px;margin:0 auto;padding:20px 16px;}
.header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:16px 20px;background:var(--surface);border:1px solid var(--border);border-radius:14px;margin-bottom:18px;}
.logo{display:flex;align-items:center;gap:10px;}
.logo-icon{width:40px;height:40px;background:linear-gradient(135deg,var(--accent),var(--green));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;}
.logo h1{font-size:1.2rem;font-weight:800;background:linear-gradient(90deg,var(--accent),var(--green));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.nav{display:flex;gap:6px;}
.nav a{padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--muted);font-family:'Space Mono',monospace;font-size:.62rem;text-decoration:none;transition:all .2s;}
.nav a:hover{border-color:var(--accent);color:var(--accent);}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:16px;position:relative;overflow:hidden;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--green));}
.card h2{color:var(--accent);font-size:.95rem;margin-bottom:14px;}
.fg{margin-bottom:12px;}
.fl{display:block;font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;}
.fi{width:100%;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:.85rem;font-family:'Outfit',sans-serif;outline:none;transition:all .2s;}
.fi:focus{border-color:var(--accent);}
.fi-file{padding:10px;cursor:pointer;}
.fn{font-size:.6rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:3px;}
.check-label{display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--muted);cursor:pointer;}
.check-label input{width:auto;}
.fr2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn{padding:11px 20px;border-radius:8px;border:none;cursor:pointer;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:700;transition:all .2s;}
.btn-p{background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);}
.btn-p:hover{box-shadow:0 0 16px rgba(0,212,255,.4);}
.btn-s{background:var(--card);color:var(--accent);border:1px solid rgba(0,212,255,.3);}
.btn-d{background:rgba(255,56,96,.1);color:var(--danger);border:1px solid rgba(255,56,96,.3);padding:5px 10px;font-size:.7rem;}
.msg-ok{background:rgba(0,255,157,.1);border:1px solid rgba(0,255,157,.3);color:var(--green);padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:.82rem;}
.msg-err{background:rgba(255,56,96,.1);border:1px solid rgba(255,56,96,.3);color:var(--danger);padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:.82rem;}
.sec{font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:3px;font-family:'Space Mono',monospace;margin-bottom:10px;display:flex;align-items:center;gap:8px;}
.sec::after{content:'';flex:1;height:1px;background:var(--border);}
table{width:100%;border-collapse:collapse;}
th{padding:8px 12px;text-align:left;font-family:'Space Mono',monospace;font-size:.58rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid var(--border);}
td{padding:8px 12px;font-size:.78rem;border-bottom:1px solid var(--border);font-family:'Space Mono',monospace;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--card);}
.badge{padding:2px 8px;border-radius:10px;font-size:.62rem;}
.b-ok{background:rgba(0,255,157,.1);color:var(--green);border:1px solid rgba(0,255,157,.2);}
.b-old{background:rgba(255,170,0,.1);color:var(--warn);border:1px solid rgba(255,170,0,.2);}
.b-latest{background:rgba(0,212,255,.1);color:var(--accent);border:1px solid rgba(0,212,255,.2);}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;}
.stat{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center;}
.stat-l{font-size:.58rem;color:var(--muted);text-transform:uppercase;letter-spacing:2px;font-family:'Space Mono',monospace;margin-bottom:4px;}
.stat-v{font-family:'Space Mono',monospace;font-size:1.4rem;font-weight:700;color:var(--accent);}
.stat-v.g{color:var(--green);}
.drag-zone{border:2px dashed var(--border);border-radius:10px;padding:30px;text-align:center;cursor:pointer;transition:all .2s;margin-bottom:12px;}
.drag-zone:hover,.drag-zone.drag-over{border-color:var(--accent);background:rgba(0,212,255,.04);}
.drag-icon{font-size:2rem;margin-bottom:8px;}
.drag-text{font-size:.82rem;color:var(--muted);}
.drag-text strong{color:var(--accent);}
.url-box{background:var(--card);border:1px solid rgba(0,212,255,.2);border-radius:8px;padding:10px 12px;font-family:'Space Mono',monospace;font-size:.7rem;color:var(--green);word-break:break-all;margin-top:6px;}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="header">
  <div class="logo">
    <div class="logo-icon">🔄</div>
    <div><h1>LoKy ACC — OTA</h1></div>
  </div>
  <div class="nav">
    <a href="admin.php">⚙️ Admin</a>
    <a href="home.php">🏠 Accueil</a>
    <a href="logout.php">Déconnexion</a>
  </div>
</div>

<?php if ($msg_ok): ?><div class="msg-ok">✅ <?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
<?php if ($msg_err): ?><div class="msg-err">❌ <?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

<!-- Stats -->
<div class="stats-row">
  <div class="stat">
    <div class="stat-l">Version déployée</div>
    <div class="stat-v"><?= htmlspecialchars($config['firmware_version'] ?? '—') ?></div>
  </div>
  <div class="stat">
    <div class="stat-l">Modules actifs</div>
    <div class="stat-v g"><?= count($modules) ?></div>
  </div>
  <div class="stat">
    <div class="stat-l">Firmwares dispo</div>
    <div class="stat-v"><?= count($firmwares) ?></div>
  </div>
  <div class="stat">
    <div class="stat-l">Dernière MAJ</div>
    <div class="stat-v" style="font-size:.9rem;color:var(--muted);">
      <?= isset($config['updated']) ? date('d/m H:i', strtotime($config['updated'])) : '—' ?>
    </div>
  </div>
</div>

<!-- URL API rapide -->
<div class="card">
  <h2>🔗 Modifier l'URL API (sans changer le firmware)</h2>
  <p style="font-size:.75rem;color:var(--muted);font-family:'Space Mono',monospace;margin-bottom:14px;line-height:1.6;">
    Si vous changez de domaine, mettez à jour l'URL ici.<br>
    Les modules récupèreront la nouvelle URL dans l'heure automatiquement.
  </p>
  <div style="background:rgba(0,255,157,.05);border:1px solid rgba(0,255,157,.2);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.75rem;color:var(--green);">
    📡 URL actuelle : <strong><?= htmlspecialchars($config['api_url'] ?? '—') ?></strong>
  </div>
  <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;">
    <input type="url" name="api_url_only" class="fi" style="flex:1;min-width:200px;"
           placeholder="https://nouveau-domaine.fr/loky/api.php"
           value="<?= htmlspecialchars($config['api_url'] ?? '') ?>" required>
    <button type="submit" name="update_api_only" class="btn btn-s">
      🔗 Mettre à jour l'URL
    </button>
  </form>
</div>

<!-- Upload firmware -->
<div class="card">
  <h2>⬆️ Déployer un nouveau firmware</h2>
  <p style="font-size:.75rem;color:var(--muted);font-family:'Space Mono',monospace;margin-bottom:14px;line-height:1.6;">
    Compilez le firmware dans Arduino IDE :<br>
    <strong>Croquis → Exporter le binaire compilé</strong> → upload le fichier .bin ici.
  </p>

  <form method="POST" enctype="multipart/form-data" onsubmit="return confirmDeploy()">

    <!-- Zone drag & drop -->
    <div class="drag-zone" id="drag-zone" onclick="document.getElementById('fw-file').click()">
      <div class="drag-icon">📦</div>
      <div class="drag-text"><strong>Cliquer ou glisser</strong> le fichier .bin ici</div>
      <div id="file-name" style="margin-top:8px;font-family:'Space Mono',monospace;font-size:.7rem;color:var(--accent);"></div>
    </div>
    <input type="file" id="fw-file" name="firmware" accept=".bin" style="display:none"
           onchange="document.getElementById('file-name').textContent=this.files[0]?.name||''">

    <div class="fr2">
      <div class="fg">
        <label class="fl">Version du firmware <span style="color:var(--danger)">*</span></label>
        <input type="text" name="version" class="fi" placeholder="4.1.0" required
               pattern="\d+\.\d+\.\d+" title="Format: X.Y.Z">
        <div class="fn">Doit correspondre à FIRMWARE_VERSION dans le .ino</div>
      </div>
      <div class="fg">
        <label class="fl">URL API</label>
        <input type="url" name="api_url" class="fi"
               value="<?= htmlspecialchars($config['api_url'] ?? DEFAULT_API_URL ?? '') ?>"
               placeholder="https://...">
      </div>
    </div>

    <div class="fg">
      <label class="fl">Message (optionnel)</label>
      <input type="text" name="message" class="fi" placeholder="Correctif bug WiFi, nouvelle fonctionnalité...">
    </div>

    <div class="fg">
      <label class="check-label">
        <input type="checkbox" name="force_update">
        Forcer la mise à jour (même version identique)
      </label>
    </div>

    <button type="submit" class="btn btn-p">
      🚀 Déployer le firmware
    </button>
  </form>

  <!-- URL config.json -->
  <div style="margin-top:16px;">
    <div style="font-size:.62rem;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
      URL hardcodée dans le firmware (ne change jamais)
    </div>
    <div class="url-box">
      <?= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) ?>/firmware/config.json
    </div>
  </div>
</div>

<!-- Firmwares disponibles -->
<div class="card">
  <h2>📦 Firmwares disponibles</h2>
  <?php if (empty($firmwares)): ?>
  <div style="text-align:center;padding:20px;color:var(--muted);font-family:'Space Mono',monospace;font-size:.78rem;">
    Aucun firmware uploadé
  </div>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>Fichier</th><th>Version</th><th>Taille</th><th>Date</th><th>Statut</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($firmwares as $i => $fw): ?>
      <tr>
        <td><?= htmlspecialchars($fw['name']) ?></td>
        <td style="color:var(--accent);">v<?= htmlspecialchars($fw['version']) ?></td>
        <td><?= number_format($fw['size'] / 1024, 1) ?> KB</td>
        <td><?= $fw['date'] ?></td>
        <td>
          <?php if ($i === 0): ?>
          <span class="badge b-latest">✅ Déployée</span>
          <?php else: ?>
          <span class="badge b-old">Ancienne</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="?del=<?= urlencode($fw['name']) ?>" class="btn btn-d"
             onclick="return confirm('Supprimer ce firmware ?')">✕</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- État des modules -->
<div class="card">
  <h2>📡 État des modules</h2>
  <?php if (empty($modules)): ?>
  <div style="text-align:center;padding:20px;color:var(--muted);font-family:'Space Mono',monospace;font-size:.78rem;">Aucun module enregistré</div>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>Module</th><th>Version actuelle</th><th>Dernière vue</th><th>Statut MAJ</th></tr>
    </thead>
    <tbody>
      <?php
      $latestVer = $config['firmware_version'] ?? '0.0.0';
      foreach ($modules as $m):
        $mVer   = $m['firmware'] ?? '?';
        $isUpToDate = $mVer === $latestVer;
        $age    = $m['derniere_vue'] ? time() - strtotime($m['derniere_vue']) : 9999;
        $status = $age < 120 ? 'En ligne' : ($age < 600 ? 'Ralenti' : 'Hors ligne');
      ?>
      <tr>
        <td style="color:var(--accent);"><?= htmlspecialchars($m['module_id']) ?></td>
        <td>
          <span style="font-family:'Space Mono',monospace;color:<?= $isUpToDate ? 'var(--green)' : 'var(--warn)' ?>;">
            v<?= htmlspecialchars($mVer) ?>
          </span>
        </td>
        <td><?= $m['derniere_vue'] ?? '—' ?></td>
        <td>
          <?php if ($isUpToDate): ?>
          <span class="badge b-ok">✅ À jour</span>
          <?php else: ?>
          <span class="badge b-old">⚠️ MAJ disponible v<?= $latestVer ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</div>

<script>
// Drag & drop
const zone = document.getElementById('drag-zone');
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', e => {
  e.preventDefault(); zone.classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  if (file && file.name.endsWith('.bin')) {
    document.getElementById('fw-file').files = e.dataTransfer.files;
    document.getElementById('file-name').textContent = file.name;
  }
});

function confirmDeploy() {
  const v = document.querySelector('[name=version]').value;
  const f = document.getElementById('fw-file').files[0];
  if (!f) { alert('Sélectionnez un fichier .bin'); return false; }
  return confirm(`Déployer le firmware v${v} ?\n\nTous les modules se mettront à jour automatiquement dans l'heure.`);
}
// Auto-refresh 60s
setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>

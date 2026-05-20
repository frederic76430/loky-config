<?php
// ================================================
// LoKy ACC — Générateur clés VAPID
// vapid_setup.php
// À supprimer après utilisation !
// ================================================

require_once 'config.php';

$db      = getDB();
$message = '';
$error   = '';

// ================================================
// FONCTIONS VAPID
// ================================================

function generateVAPIDKeys(): array {
    $config = [
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];

    $key = openssl_pkey_new($config);
    if (!$key) {
        throw new Exception('OpenSSL non disponible : ' . openssl_error_string());
    }

    $details = openssl_pkey_get_details($key);

    // Clé publique (format non compressé 0x04 + x + y)
    $pubKey = base64url_encode(
        "\x04"
        . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT)
    );

    // Clé privée
    $privKey = base64url_encode(
        str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT)
    );

    return [
        'public'  => $pubKey,
        'private' => $privKey,
    ];
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Sauvegarder en base
function saveVAPIDKeys(PDO $db, string $public, string $private, string $subject): void {
    $stmt = $db->prepare(
        "INSERT INTO parametres (`cle`, `valeur`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `valeur` = VALUES(`valeur`)"
    );
    $stmt->execute(['vapid_public_key',  $public]);
    $stmt->execute(['vapid_private_key', $private]);
    $stmt->execute(['vapid_subject',     $subject]);
}

// Lire depuis la base
function getVAPIDKeys(PDO $db): array {
    $stmt = $db->query(
        "SELECT `cle`, `valeur` FROM parametres
         WHERE `cle` IN ('vapid_public_key', 'vapid_private_key', 'vapid_subject')"
    );
    $rows = $stmt->fetchAll();
    $keys = [];
    foreach ($rows as $row) {
        $keys[$row['cle']] = $row['valeur'];
    }
    return $keys;
}

// ================================================
// TRAITEMENT FORMULAIRE
// ================================================

// Générer et sauvegarder
if (isset($_POST['generate'])) {
    try {
        $subject = filter_var($_POST['subject'] ?? '', FILTER_VALIDATE_EMAIL)
                   ? 'mailto:' . $_POST['subject']
                   : 'mailto:admin@loky-acc.fr';

        $keys = generateVAPIDKeys();
        saveVAPIDKeys($db, $keys['public'], $keys['private'], $subject);
        $message = 'Clés VAPID générées et sauvegardées en base !';
    } catch (Exception $e) {
        $error = 'Erreur : ' . $e->getMessage();
    }
}

// Régénérer
if (isset($_POST['regenerate'])) {
    try {
        $subject = $_POST['subject'] ?? 'mailto:admin@loky-acc.fr';
        $keys    = generateVAPIDKeys();
        saveVAPIDKeys($db, $keys['public'], $keys['private'], $subject);
        $message = '⚠️ Clés régénérées ! Tous les abonnés Push existants devront se réabonner.';

        // Désactiver tous les Push existants
        $db->exec("UPDATE utilisateurs SET push_actif = 0, push_endpoint = NULL");
        $message .= ' Les abonnements Push ont été réinitialisés.';
    } catch (Exception $e) {
        $error = 'Erreur : ' . $e->getMessage();
    }
}

// Récupérer les clés existantes
$existingKeys = getVAPIDKeys($db);
$hasKeys      = !empty($existingKeys['vapid_public_key']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LoKy ACC — Configuration VAPID</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;
  --green:#00ff9d;--warn:#ffaa00;--danger:#ff3860;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;
  min-height:100vh;padding:30px 16px;}
body::after{content:'';position:fixed;inset:0;
  background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);
  background-size:28px 28px;opacity:.12;pointer-events:none;}
.wrap{position:relative;z-index:1;max-width:640px;margin:0 auto;}

.logo{text-align:center;margin-bottom:28px;}
.logo-icon{width:60px;height:60px;background:linear-gradient(135deg,var(--accent),var(--green));
  border-radius:16px;display:flex;align-items:center;justify-content:center;
  font-size:28px;margin:0 auto 12px;box-shadow:0 0 30px rgba(0,212,255,.3);}
.logo h1{font-size:1.6rem;font-weight:800;
  background:linear-gradient(90deg,var(--accent),var(--green));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.logo p{font-size:.7rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:4px;}

.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;
  padding:24px;margin-bottom:18px;position:relative;overflow:hidden;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--accent),var(--green));}

.card-title{font-size:1rem;font-weight:700;color:var(--accent);margin-bottom:6px;}
.card-sub{font-size:.75rem;color:var(--muted);font-family:'Space Mono',monospace;
  margin-bottom:18px;line-height:1.6;}

/* Statut */
.status-box{display:flex;align-items:center;gap:12px;padding:14px 16px;
  border-radius:10px;margin-bottom:18px;}
.status-box.ok{background:rgba(0,255,157,.08);border:1px solid rgba(0,255,157,.3);}
.status-box.missing{background:rgba(255,170,0,.08);border:1px solid rgba(255,170,0,.3);}
.status-icon{font-size:1.8rem;}
.status-text{flex:1;}
.status-title{font-size:.9rem;font-weight:700;}
.status-title.ok{color:var(--green);}
.status-title.missing{color:var(--warn);}
.status-desc{font-size:.72rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:2px;}

/* Clés affichées */
.key-box{background:var(--card);border:1px solid var(--border);border-radius:10px;
  padding:14px 16px;margin-bottom:12px;}
.key-label{font-size:.62rem;color:var(--muted);text-transform:uppercase;
  letter-spacing:2px;font-family:'Space Mono',monospace;margin-bottom:8px;}
.key-value{font-family:'Space Mono',monospace;font-size:.72rem;color:var(--green);
  word-break:break-all;line-height:1.6;}
.key-actions{display:flex;gap:8px;margin-top:8px;}
.btn-copy{padding:5px 12px;border-radius:6px;border:1px solid rgba(0,212,255,.3);
  background:rgba(0,212,255,.05);color:var(--accent);font-family:'Space Mono',monospace;
  font-size:.65rem;cursor:pointer;transition:all .2s;}
.btn-copy:hover{background:var(--accent);color:var(--bg);}

/* Formulaire */
.form-group{margin-bottom:14px;}
.form-label{display:block;font-size:.68rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;}
.form-input{width:100%;background:var(--card);color:var(--text);
  border:1px solid var(--border);border-radius:8px;padding:12px 14px;
  font-size:.9rem;font-family:'Outfit',sans-serif;outline:none;transition:all .2s;}
.form-input:focus{border-color:var(--accent);}
.form-input::placeholder{color:var(--muted);}

/* Boutons */
.btn{padding:12px 20px;border-radius:10px;border:none;cursor:pointer;
  font-family:'Outfit',sans-serif;font-size:.9rem;font-weight:700;
  transition:all .2s;width:100%;margin-top:6px;}
.btn-generate{background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);}
.btn-generate:hover{box-shadow:0 0 25px rgba(0,212,255,.4);}
.btn-regen{background:rgba(255,170,0,.1);color:var(--warn);border:1px solid rgba(255,170,0,.3);}
.btn-regen:hover{background:var(--warn);color:var(--bg);}

/* Messages */
.msg-ok{background:rgba(0,255,157,.1);border:1px solid rgba(0,255,157,.3);
  color:var(--green);padding:12px 16px;border-radius:8px;margin-bottom:16px;
  font-size:.85rem;line-height:1.6;}
.msg-err{background:rgba(255,56,96,.1);border:1px solid rgba(255,56,96,.3);
  color:var(--danger);padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem;}

/* Avertissement */
.warn-box{background:rgba(255,56,96,.08);border:1px solid rgba(255,56,96,.3);
  border-radius:10px;padding:14px 16px;margin-bottom:18px;}
.warn-box h4{color:var(--danger);font-size:.9rem;margin-bottom:6px;}
.warn-box p{font-size:.75rem;color:var(--muted);line-height:1.6;}

/* Explication */
.explain{background:var(--card);border:1px solid var(--border);border-radius:10px;
  padding:16px;margin-bottom:18px;}
.explain h4{color:var(--accent);font-size:.85rem;margin-bottom:10px;}
.explain-row{display:flex;gap:10px;padding:6px 0;border-bottom:1px solid var(--border);
  font-size:.78rem;}
.explain-row:last-child{border-bottom:none;}
.explain-icon{font-size:1.1rem;flex-shrink:0;}
.explain-text{color:var(--muted);line-height:1.5;}

/* Config.php info */
.config-info{background:rgba(0,212,255,.05);border:1px solid rgba(0,212,255,.2);
  border-radius:10px;padding:14px 16px;margin-top:16px;}
.config-info h4{color:var(--accent);font-size:.82rem;margin-bottom:8px;}
.config-code{font-family:'Space Mono',monospace;font-size:.68rem;color:var(--green);
  background:var(--card);padding:10px;border-radius:6px;line-height:1.8;
  white-space:pre-wrap;word-break:break-all;}

.footer-note{text-align:center;padding:16px;font-family:'Space Mono',monospace;
  font-size:.62rem;color:var(--muted);}
</style>
</head>
<body>
<div class="wrap">

  <!-- Logo -->
  <div class="logo">
    <div class="logo-icon">🔐</div>
    <h1>LoKy ACC</h1>
    <p>Configuration des clés VAPID</p>
  </div>

  <!-- Messages -->
  <?php if ($message): ?>
  <div class="msg-ok">✅ <?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="msg-err">❌ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Avertissement sécurité -->
  <div class="warn-box">
    <h4>⚠️ Fichier sensible</h4>
    <p>
      Ce fichier génère des clés cryptographiques privées.<br>
      <strong>Supprimez-le du serveur après utilisation !</strong><br>
      Ne le laissez jamais accessible publiquement.
    </p>
  </div>

  <!-- Statut actuel -->
  <div class="card">
    <div class="card-title">📊 Statut des clés VAPID</div>

    <?php if ($hasKeys): ?>
    <div class="status-box ok">
      <div class="status-icon">✅</div>
      <div class="status-text">
        <div class="status-title ok">Clés configurées</div>
        <div class="status-desc">Les notifications Push sont opérationnelles</div>
      </div>
    </div>

    <!-- Afficher les clés -->
    <div class="key-box">
      <div class="key-label">Clé publique VAPID</div>
      <div class="key-value" id="pub-key">
        <?= htmlspecialchars($existingKeys['vapid_public_key'] ?? '') ?>
      </div>
      <div class="key-actions">
        <button class="btn-copy" onclick="copyKey('pub-key')">📋 Copier</button>
      </div>
    </div>

    <div class="key-box">
      <div class="key-label">Clé privée VAPID</div>
      <div class="key-value" id="priv-key" style="filter:blur(4px);cursor:pointer;"
           onclick="this.style.filter='none'">
        <?= htmlspecialchars($existingKeys['vapid_private_key'] ?? '') ?>
      </div>
      <div class="key-actions">
        <button class="btn-copy" onclick="this.previousElementSibling.style.filter='none';copyKey('priv-key')">
          👁️ Révéler et copier
        </button>
      </div>
    </div>

    <div class="key-box">
      <div class="key-label">Subject (contact)</div>
      <div class="key-value"><?= htmlspecialchars($existingKeys['vapid_subject'] ?? '') ?></div>
    </div>

    <!-- Régénérer -->
    <form method="POST" style="margin-top:16px;"
          onsubmit="return confirm('⚠️ Régénérer les clés va déconnecter tous les abonnés Push existants. Continuer ?')">
      <div class="form-group">
        <label class="form-label">Email de contact</label>
        <input type="email" name="subject" class="form-input"
               placeholder="votre@email.fr"
               value="<?= htmlspecialchars(str_replace('mailto:', '', $existingKeys['vapid_subject'] ?? '')) ?>">
      </div>
      <button type="submit" name="regenerate" class="btn btn-regen">
        🔄 Régénérer les clés (reset abonnements)
      </button>
    </form>

    <?php else: ?>
    <div class="status-box missing">
      <div class="status-icon">⚠️</div>
      <div class="status-text">
        <div class="status-title missing">Clés manquantes</div>
        <div class="status-desc">Les notifications Push ne fonctionneront pas sans clés VAPID</div>
      </div>
    </div>

    <!-- Générer -->
    <form method="POST">
      <div class="form-group">
        <label class="form-label">Votre email de contact *</label>
        <input type="email" name="subject" class="form-input"
               placeholder="votre@email.fr" required
               value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
        <div style="font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:5px;">
          Utilisé comme identifiant VAPID — jamais affiché aux utilisateurs
        </div>
      </div>
      <button type="submit" name="generate" class="btn btn-generate">
        🔐 Générer les clés VAPID
      </button>
    </form>
    <?php endif; ?>
  </div>

  <!-- Explication -->
  <div class="card">
    <div class="card-title">ℹ️ Comment ça marche</div>
    <div class="explain">
      <div class="explain-row">
        <div class="explain-icon">🔐</div>
        <div class="explain-text">
          Les clés VAPID authentifient votre serveur auprès des services Push (Google, Mozilla...).
          Sans elles, les navigateurs refusent les notifications.
        </div>
      </div>
      <div class="explain-row">
        <div class="explain-icon">💾</div>
        <div class="explain-text">
          Les clés sont stockées dans la table <code>parametres</code> de votre base MySQL.
          Elles sont lues automatiquement par tous les fichiers PHP via <code>config.php</code>.
        </div>
      </div>
      <div class="explain-row">
        <div class="explain-icon">🔑</div>
        <div class="explain-text">
          La clé <strong>publique</strong> est envoyée aux navigateurs pour s'abonner.
          La clé <strong>privée</strong> signe les notifications — ne la partagez jamais !
        </div>
      </div>
      <div class="explain-row">
        <div class="explain-icon">⚠️</div>
        <div class="explain-text">
          Si vous régénérez les clés, tous les abonnements Push existants deviennent invalides.
          Vos consommateurs devront réactiver les notifications.
        </div>
      </div>
    </div>

    <!-- Info config.php -->
    <div class="config-info">
      <h4>📄 config.php — Plus besoin de modifier !</h4>
      <p style="font-size:.72rem;color:var(--muted);margin-bottom:10px;">
        Les clés sont maintenant lues automatiquement depuis la base de données.
        La fonction <code>getVAPIDConfig()</code> dans config.php s'en occupe.
      </p>
      <div class="config-code">// Dans config.php — automatique !
function getVAPIDConfig(): array {
    static $vapid = null;
    if ($vapid === null) {
        $db = getDB();
        $rows = $db->query("SELECT cle, valeur FROM parametres
            WHERE cle IN ('vapid_public_key','vapid_private_key','vapid_subject')"
        )->fetchAll();
        foreach ($rows as $r) $vapid[$r['cle']] = $r['valeur'];
    }
    return $vapid;
}</div>
    </div>
  </div>

  <div class="footer-note">
    ⚠️ Supprimez ce fichier (vapid_setup.php) après configuration !
    <br>
    <a href="admin.php" style="color:var(--accent);">→ Retour admin</a>
  </div>

</div>

<script>
function copyKey(id) {
  const text = document.getElementById(id).textContent.trim();
  navigator.clipboard.writeText(text).then(() => {
    showToast('✅ Clé copiée !');
  }).catch(() => {
    // Fallback
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
    showToast('✅ Clé copiée !');
  });
}

function showToast(msg) {
  const t = document.createElement('div');
  t.textContent = msg;
  t.style.cssText = `position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
    background:#00ff9d;color:#050d1a;padding:10px 24px;border-radius:10px;
    font-weight:700;font-size:.85rem;box-shadow:0 4px 20px rgba(0,0,0,.4);z-index:9999;`;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2500);
}
</script>
</body>
</html>

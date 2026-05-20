<?php
// ================================================
// LoKy ACC — Inscription
// register.php
// ================================================

require_once 'auth.php';

if (isLogged()) {
    header('Location: ' . (getRole() === 'admin' ? 'admin.php' :
           (getRole() === 'producteur' ? 'producer.php' : 'consumer.php')));
    exit;
}

$db     = getDB();
$error  = '';
$success = '';

// Récupérer liste des ACC disponibles
$accs = $db->query("SELECT nom, description FROM acc WHERE actif=1 ORDER BY nom")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = register($_POST);
    if ($result['success']) {
        $success = "Inscription envoyée ! Un administrateur doit valider votre compte avant que vous puissiez vous connecter.";
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LoKy ACC — Inscription</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;
  --green:#00ff9d;--danger:#ff3860;--warn:#ffaa00;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
body::after{content:'';position:fixed;inset:0;
  background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);
  background-size:28px 28px;opacity:.12;pointer-events:none;}

.wrap{position:relative;z-index:1;width:100%;max-width:480px;}

.logo{text-align:center;margin-bottom:24px;}
.logo-icon{width:60px;height:60px;background:linear-gradient(135deg,var(--accent),var(--green));
  border-radius:16px;display:flex;align-items:center;justify-content:center;
  font-size:28px;margin:0 auto 12px;box-shadow:0 0 30px rgba(0,212,255,.3);}
.logo h1{font-size:1.6rem;font-weight:800;
  background:linear-gradient(90deg,var(--accent),var(--green));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}

.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;
  padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.4);}

.card-title{font-size:1.1rem;font-weight:700;color:var(--accent);text-align:center;margin-bottom:4px;}
.card-sub{font-size:.72rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-align:center;margin-bottom:24px;}

.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:.7rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-transform:uppercase;letter-spacing:1px;margin-bottom:7px;}
.form-input,.form-select{width:100%;background:var(--card);color:var(--text);
  border:1px solid var(--border);border-radius:10px;padding:13px 16px;
  font-size:.95rem;font-family:'Outfit',sans-serif;outline:none;transition:all .2s;}
.form-input:focus,.form-select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,212,255,.1);}
.form-input::placeholder{color:var(--muted);}
.form-select option{background:var(--card);}

/* Sélecteur de rôle */
.role-select{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;}
.role-btn{padding:14px;border-radius:10px;border:1px solid var(--border);
  background:var(--card);cursor:pointer;text-align:center;transition:all .2s;}
.role-btn:hover{border-color:var(--accent);}
.role-btn.selected{border-color:var(--accent);background:rgba(0,212,255,.08);}
.role-btn input{display:none;}
.role-icon{font-size:1.5rem;margin-bottom:4px;}
.role-name{font-size:.82rem;font-weight:600;color:var(--text);}
.role-desc{font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:2px;}

.btn-register{width:100%;padding:15px;border-radius:10px;border:none;cursor:pointer;
  background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);
  font-family:'Outfit',sans-serif;font-size:1rem;font-weight:700;
  transition:all .2s;margin-top:8px;}
.btn-register:hover{box-shadow:0 0 25px rgba(0,212,255,.4);}

.msg-error{background:rgba(255,56,96,.1);border:1px solid rgba(255,56,96,.3);
  color:var(--danger);padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem;}
.msg-success{background:rgba(0,255,157,.1);border:1px solid rgba(0,255,157,.3);
  color:var(--green);padding:14px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem;line-height:1.6;}

.info-box{background:rgba(255,170,0,.05);border:1px solid rgba(255,170,0,.2);
  border-radius:10px;padding:12px 16px;margin-bottom:20px;
  font-size:.78rem;color:var(--warn);line-height:1.6;}

.links{display:flex;justify-content:center;margin-top:18px;}
.link{font-size:.75rem;color:var(--muted);text-decoration:none;
  font-family:'Space Mono',monospace;transition:color .2s;}
.link:hover{color:var(--accent);}

.divider{display:flex;align-items:center;gap:12px;margin:16px 0;}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border);}
.divider span{font-size:.62rem;color:var(--muted);font-family:'Space Mono',monospace;}
</style>
</head>
<body>
<div class="wrap">

  <div class="logo">
    <div class="logo-icon">⚡</div>
    <h1>LoKy ACC</h1>
  </div>

  <div class="card">
    <div class="card-title">📝 Inscription</div>
    <div class="card-sub">Créez votre compte LoKy ACC</div>

    <?php if ($success): ?>
    <div class="msg-success">
      ✅ <?= htmlspecialchars($success) ?><br><br>
      <a href="login.php" style="color:var(--green);font-weight:bold;">→ Retour à la connexion</a>
    </div>
    <?php else: ?>

    <?php if ($error): ?>
    <div class="msg-error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="info-box">
      ℹ️ Votre inscription sera examinée par un administrateur avant validation.
      Vous recevrez l'accès une fois votre compte approuvé.
    </div>

    <form method="POST" id="register-form">

      <!-- Sélection rôle -->
      <div class="form-group">
        <div class="form-label">Je suis un(e)</div>
        <div class="role-select">
          <label class="role-btn <?= ($_POST['role'] ?? 'consommateur') === 'producteur' ? 'selected' : '' ?>"
                 onclick="selectRole(this, 'producteur')">
            <input type="radio" name="role" value="producteur"
                   <?= ($_POST['role'] ?? '') === 'producteur' ? 'checked' : '' ?>>
            <div class="role-icon">⚡</div>
            <div class="role-name">Producteur</div>
            <div class="role-desc">J'ai des panneaux solaires</div>
          </label>
          <label class="role-btn <?= ($_POST['role'] ?? 'consommateur') === 'consommateur' ? 'selected' : '' ?>"
                 onclick="selectRole(this, 'consommateur')">
            <input type="radio" name="role" value="consommateur"
                   <?= ($_POST['role'] ?? 'consommateur') === 'consommateur' ? 'checked' : '' ?>>
            <div class="role-icon">👤</div>
            <div class="role-name">Consommateur</div>
            <div class="role-desc">Je veux profiter du surplus</div>
          </label>
        </div>
      </div>

      <!-- Nom -->
      <div class="form-group">
        <label class="form-label">Nom complet *</label>
        <input type="text" name="nom" class="form-input"
               placeholder="Ex: Marie Dupont"
               value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>"
               required>
      </div>

      <!-- Numéro compteur -->
      <div class="form-group">
        <label class="form-label">Numéro de compteur Linky *</label>
        <input type="text" name="numero" class="form-input"
               placeholder="14 chiffres sur votre compteur"
               value="<?= htmlspecialchars($_POST['numero'] ?? '') ?>"
               maxlength="20" required>
        <div style="font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:5px;">
          📍 Visible sur votre compteur Linky ou sur votre facture d'électricité
        </div>
      </div>

      <!-- ACC -->
      <div class="form-group">
        <label class="form-label">Rejoindre un ACC</label>
        <?php if (empty($accs)): ?>
        <input type="text" name="acc_nom" class="form-input"
               placeholder="Nom de votre ACC (ex: ACC_DUPONT)"
               value="<?= htmlspecialchars($_POST['acc_nom'] ?? '') ?>">
        <?php else: ?>
        <select name="acc_nom" class="form-select">
          <option value="">-- Choisir votre ACC --</option>
          <?php foreach ($accs as $acc): ?>
          <option value="<?= htmlspecialchars($acc['nom']) ?>"
                  <?= ($_POST['acc_nom'] ?? '') === $acc['nom'] ? 'selected' : '' ?>>
            ⚡ <?= htmlspecialchars($acc['nom']) ?>
            <?= $acc['description'] ? ' — ' . htmlspecialchars($acc['description']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div style="font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:5px;">
          Vous pourrez changer d'ACC après votre inscription
        </div>
      </div>

      <button type="submit" class="btn-register">
        📝 Envoyer ma demande d'inscription
      </button>
    </form>

    <?php endif; ?>

    <div class="divider"><span>Déjà un compte ?</span></div>
    <div class="links">
      <a href="login.php" class="link">→ Se connecter</a>
    </div>
  </div>

</div>

<script>
function selectRole(el, role) {
  document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('selected'));
  el.classList.add('selected');
  el.querySelector('input').checked = true;
}
</script>
</body>
</html>

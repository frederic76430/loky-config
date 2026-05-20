<?php
// ================================================
// LoKy ACC — Connexion
// login.php
// ================================================

require_once 'auth.php';

// Déjà connecté → rediriger
if (isLogged()) {
    $role = getRole();
    header('Location: ' . ($role === 'admin' ? 'admin.php' :
           ($role === 'producteur' ? 'producer.php' : 'consumer.php')));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero = trim($_POST['numero'] ?? '');
    $nom    = trim($_POST['nom']    ?? '');

    if (!$numero || !$nom) {
        $error = 'Veuillez remplir tous les champs';
    } else {
        $result = login($numero, $nom);
        if ($result['success']) {
            $role = $result['user']['role'];
            header('Location: ' . ($role === 'admin' ? 'admin.php' :
                   ($role === 'producteur' ? 'producer.php' : 'consumer.php')));
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$redirect_msg = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LoKy ACC — Connexion</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;
  --green:#00ff9d;--danger:#ff3860;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
body::before{content:'';position:fixed;inset:0;
  background:radial-gradient(ellipse 80% 60% at 50% 30%,rgba(0,212,255,.06) 0%,transparent 60%);
  pointer-events:none;}
body::after{content:'';position:fixed;inset:0;
  background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);
  background-size:28px 28px;opacity:.12;pointer-events:none;}

.login-wrap{position:relative;z-index:1;width:100%;max-width:420px;}

/* Logo */
.logo{text-align:center;margin-bottom:32px;}
.logo-icon{width:80px;height:80px;background:linear-gradient(135deg,var(--accent),var(--green));
  border-radius:22px;display:flex;align-items:center;justify-content:center;
  font-size:40px;margin:0 auto 16px;box-shadow:0 0 40px rgba(0,212,255,.4);}
.logo h1{font-size:2rem;font-weight:800;
  background:linear-gradient(90deg,var(--accent),var(--green));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.logo p{font-size:.75rem;color:var(--muted);font-family:'Space Mono',monospace;
  letter-spacing:2px;margin-top:4px;}

/* Card */
.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;
  padding:32px;box-shadow:0 20px 60px rgba(0,0,0,.4);}

.card-title{font-size:1.1rem;font-weight:700;color:var(--accent);
  margin-bottom:6px;text-align:center;}
.card-sub{font-size:.75rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-align:center;margin-bottom:24px;}

/* Formulaire */
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:.72rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;}
.form-input{width:100%;background:var(--card);color:var(--text);
  border:1px solid var(--border);border-radius:10px;padding:14px 16px;
  font-size:1rem;font-family:'Outfit',sans-serif;outline:none;transition:all .2s;}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,212,255,.1);}
.form-input::placeholder{color:var(--muted);}

.btn-login{width:100%;padding:16px;border-radius:10px;border:none;cursor:pointer;
  background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);
  font-family:'Outfit',sans-serif;font-size:1rem;font-weight:700;
  transition:all .2s;margin-top:8px;}
.btn-login:hover{box-shadow:0 0 30px rgba(0,212,255,.4);transform:translateY(-1px);}

/* Messages */
.msg-error{background:rgba(255,56,96,.1);border:1px solid rgba(255,56,96,.3);
  color:var(--danger);padding:12px 16px;border-radius:8px;margin-bottom:16px;
  font-size:.85rem;text-align:center;}
.msg-success{background:rgba(0,255,157,.1);border:1px solid rgba(0,255,157,.3);
  color:var(--green);padding:12px 16px;border-radius:8px;margin-bottom:16px;
  font-size:.85rem;text-align:center;}

/* Aide */
.help-box{background:var(--card);border:1px solid var(--border);border-radius:10px;
  padding:14px 16px;margin-top:16px;}
.help-title{font-size:.68rem;color:var(--muted);font-family:'Space Mono',monospace;
  text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;}
.help-row{display:flex;align-items:center;gap:10px;padding:6px 0;
  border-bottom:1px solid var(--border);font-size:.8rem;}
.help-row:last-child{border-bottom:none;}
.help-badge{padding:2px 10px;border-radius:10px;font-family:'Space Mono',monospace;
  font-size:.62rem;white-space:nowrap;}
.hb-admin{background:rgba(255,170,0,.1);color:var(--warn);border:1px solid rgba(255,170,0,.2);}
.hb-prod{background:rgba(0,212,255,.1);color:var(--accent);border:1px solid rgba(0,212,255,.2);}
.hb-cons{background:rgba(0,255,157,.1);color:var(--green);border:1px solid rgba(0,255,157,.2);}

/* Footer links */
.links{display:flex;justify-content:space-between;margin-top:20px;flex-wrap:wrap;gap:8px;}
.link{font-size:.75rem;color:var(--muted);text-decoration:none;
  font-family:'Space Mono',monospace;transition:color .2s;}
.link:hover{color:var(--accent);}

/* Divider */
.divider{display:flex;align-items:center;gap:12px;margin:20px 0;}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border);}
.divider span{font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;}
</style>
</head>
<body>
<div class="login-wrap">

  <!-- Logo -->
  <div class="logo">
    <div class="logo-icon">⚡</div>
    <h1>LoKy ACC</h1>
    <p>Autoconsommation Collective</p>
  </div>

  <!-- Card connexion -->
  <div class="card">
    <div class="card-title">🔐 Connexion</div>
    <div class="card-sub">Numéro de compteur + nom complet</div>

    <?php if ($error): ?>
    <div class="msg-error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($redirect_msg === 'access_denied'): ?>
    <div class="msg-error">⛔ Accès refusé — droits insuffisants</div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label class="form-label">Numéro de compteur</label>
        <input type="text" name="numero" class="form-input"
               placeholder="Ex: 12345678901234"
               value="<?= htmlspecialchars($_POST['numero'] ?? '') ?>"
               autocomplete="username" required>
      </div>
      <div class="form-group">
        <label class="form-label">Nom complet</label>
        <input type="text" name="nom" class="form-input"
               placeholder="Ex: Marie Dupont"
               value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>"
               autocomplete="name" required>
      </div>
      <button type="submit" class="btn-login">
        → Se connecter
      </button>
    </form>

    <!-- Aide rôles -->
    <div class="help-box">
      <div class="help-title">Accès selon votre profil</div>
      <div class="help-row">
        <span class="help-badge hb-admin">👑 Admin</span>
        Gestion complète du système
      </div>
      <div class="help-row">
        <span class="help-badge hb-prod">⚡ Producteur</span>
        Données de votre installation
      </div>
      <div class="help-row">
        <span class="help-badge hb-cons">👤 Consommateur</span>
        Alertes surplus de votre ACC
      </div>
    </div>

    <div class="divider"><span>Pas encore de compte ?</span></div>

    <div class="links">
      <a href="register.php" class="link">📝 S'inscrire</a>
      <a href="home.php" class="link">🏠 Accueil public</a>
    </div>
  </div>

</div>
</body>
</html>

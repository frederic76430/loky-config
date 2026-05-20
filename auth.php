<?php
// ================================================
// LoKy ACC — Gestion authentification
// auth.php — inclus dans tous les fichiers
// ================================================

require_once __DIR__ . '/config.php';

session_start();

// ================================================
// FONCTIONS AUTH
// ================================================

function isLogged(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function getUser(): array {
    return $_SESSION['user'] ?? [];
}

function getRole(): string {
    return $_SESSION['role'] ?? '';
}

function isAdmin(): bool {
    return getRole() === 'admin';
}

function isProducteur(): bool {
    return getRole() === 'producteur';
}

function isConsommateur(): bool {
    return getRole() === 'consommateur';
}

function requireLogin(string $redirect = 'login.php'): void {
    if (!isLogged()) {
        header('Location: ' . $redirect);
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: home.php?error=access_denied');
        exit;
    }
}

function requireRole(string $role): void {
    requireLogin();
    if (getRole() !== $role && !isAdmin()) {
        header('Location: home.php?error=access_denied');
        exit;
    }
}

// Login utilisateur
function login(string $numero, string $nom): array {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT * FROM utilisateurs 
         WHERE numero_compteur = ? AND actif = 1"
    );
    $stmt->execute([strtoupper(trim($numero))]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Numéro de compteur introuvable ou compte non validé'];
    }

    // Vérifier le nom (insensible à la casse)
    if (strtolower(trim($user['nom'])) !== strtolower(trim($nom))) {
        return ['success' => false, 'error' => 'Nom incorrect'];
    }

    // Mettre à jour dernière connexion
    $stmt = $db->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);

    // Récupérer infos ACC si lié
    $acc = null;
    if ($user['acc_id']) {
        $stmt = $db->prepare("SELECT * FROM acc WHERE id = ?");
        $stmt->execute([$user['acc_id']]);
        $acc = $stmt->fetch();
    }

    // Créer la session
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['user']     = [
        'id'              => $user['id'],
        'nom'             => $user['nom'],
        'numero_compteur' => $user['numero_compteur'],
        'role'            => $user['role'],
        'acc_id'          => $user['acc_id'],
        'acc_nom'         => $acc['nom'] ?? null,
        'push_actif'      => $user['push_actif'],
    ];

    return ['success' => true, 'user' => $_SESSION['user']];
}

// Déconnexion
function logout(): void {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Inscription
function register(array $data): array {
    $db = getDB();

    $nom     = sanitize($data['nom']      ?? '');
    $numero  = strtoupper(sanitize($data['numero'] ?? ''));
    $role    = sanitize($data['role']     ?? 'consommateur');
    $acc_nom = sanitize($data['acc_nom']  ?? '');

    if (!$nom || !$numero) {
        return ['success' => false, 'error' => 'Nom et numéro de compteur obligatoires'];
    }

    if (!in_array($role, ['producteur', 'consommateur'])) {
        return ['success' => false, 'error' => 'Rôle invalide'];
    }

    // Vérifier si numéro déjà utilisé
    $stmt = $db->prepare("SELECT id FROM utilisateurs WHERE numero_compteur = ?");
    $stmt->execute([$numero]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'Ce numéro de compteur est déjà enregistré'];
    }

    // Trouver l'ACC
    $acc_id = null;
    if ($acc_nom) {
        $stmt = $db->prepare("SELECT id FROM acc WHERE nom = ?");
        $stmt->execute([$acc_nom]);
        $acc = $stmt->fetch();
        if ($acc) $acc_id = $acc['id'];
    }

    // Hash du numéro de compteur comme mot de passe
    $hash = password_hash($numero . $nom, PASSWORD_BCRYPT);

    try {
        $stmt = $db->prepare(
            "INSERT INTO utilisateurs (role, nom, numero_compteur, password_hash, acc_id, actif)
             VALUES (?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([$role, $nom, $numero, $hash, $acc_id]);
        return ['success' => true, 'id' => $db->lastInsertId()];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => 'Erreur inscription : ' . $e->getMessage()];
    }
}

// Rafraîchir les données de session
function refreshSession(): void {
    if (!isLogged()) return;
    $db   = getDB();
    $stmt = $db->prepare("SELECT u.*, a.nom as acc_nom FROM utilisateurs u LEFT JOIN acc a ON u.acc_id = a.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user']['acc_id']  = $user['acc_id'];
        $_SESSION['user']['acc_nom'] = $user['acc_nom'];
        $_SESSION['user']['push_actif'] = $user['push_actif'];
    }
}

<?php
// ============================================================
//  SERENO SMS — AuthController
//  Routes : POST /auth/login | /auth/logout | /auth/refresh
//           POST /auth/register (admin seulement)
//           GET  /auth/moi
// ============================================================

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../middleware/auth.php';

class AuthController {

    // ----------------------------------------------------------
    // POST /auth/login
    // Body: { "email": "...", "mot_de_passe": "..." }
    // ----------------------------------------------------------
    public static function login(): void {
        $data = self::bodyJson();

        $email    = trim($data['email'] ?? '');
        $motDePasse = $data['mot_de_passe'] ?? '';

        if (!$email || !$motDePasse) {
            self::erreur(400, 'Email et mot de passe requis.');
        }

        $db   = Database::connect();
        $stmt = $db->prepare(
            'SELECT id, nom, prenom, email, mot_de_passe, role, actif, photo
             FROM utilisateurs WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($motDePasse, $user['mot_de_passe'])) {
            self::erreur(401, 'Email ou mot de passe incorrect.');
        }

        if (!$user['actif']) {
            self::erreur(403, 'Votre compte est désactivé. Contactez un administrateur.');
        }

        // Générer les tokens
        $accessToken  = JWT::genererToken([
            'id'    => $user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ]);
        $refreshToken = JWT::genererRefreshToken();

        // Sauvegarder le refresh token en base
        $expireRefresh = date('Y-m-d H:i:s', time() + JWT_REFRESH);
        $db->prepare(
            'INSERT INTO refresh_tokens (utilisateur_id, token, expire_le) VALUES (?, ?, ?)'
        )->execute([$user['id'], $refreshToken, $expireRefresh]);

        // Nettoyer les anciens refresh tokens expirés de cet utilisateur
        $db->prepare(
            'DELETE FROM refresh_tokens WHERE utilisateur_id = ? AND expire_le < NOW()'
        )->execute([$user['id']]);

        unset($user['mot_de_passe'], $user['actif']);

        self::succes([
            'utilisateur'   => $user,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expire_dans'   => JWT_EXPIRE,
            'type'          => 'Bearer',
        ], 'Connexion réussie.');
    }

    // ----------------------------------------------------------
    // POST /auth/refresh
    // Body: { "refresh_token": "..." }
    // ----------------------------------------------------------
    public static function refresh(): void {
        $data = self::bodyJson();
        $refreshToken = trim($data['refresh_token'] ?? '');

        if (!$refreshToken) {
            self::erreur(400, 'refresh_token requis.');
        }

        $db   = Database::connect();
        $stmt = $db->prepare(
            'SELECT rt.utilisateur_id, rt.expire_le, u.id, u.email, u.role, u.nom, u.prenom, u.actif
             FROM refresh_tokens rt
             JOIN utilisateurs u ON u.id = rt.utilisateur_id
             WHERE rt.token = ? LIMIT 1'
        );
        $stmt->execute([$refreshToken]);
        $row = $stmt->fetch();

        if (!$row) {
            self::erreur(401, 'Refresh token invalide.');
        }

        if (strtotime($row['expire_le']) < time()) {
            // Supprimer le token expiré
            $db->prepare('DELETE FROM refresh_tokens WHERE token = ?')->execute([$refreshToken]);
            self::erreur(401, 'Session expirée. Veuillez vous reconnecter.');
        }

        if (!$row['actif']) {
            self::erreur(403, 'Compte désactivé.');
        }

        // Générer un nouveau access token
        $newAccessToken = JWT::genererToken([
            'id'    => $row['id'],
            'email' => $row['email'],
            'role'  => $row['role'],
        ]);

        self::succes([
            'access_token' => $newAccessToken,
            'expire_dans'  => JWT_EXPIRE,
            'type'         => 'Bearer',
        ], 'Token renouvelé.');
    }

    // ----------------------------------------------------------
    // POST /auth/logout
    // Header: Authorization: Bearer <token>
    // Body: { "refresh_token": "..." }
    // ----------------------------------------------------------
    public static function logout(): void {
        $user = AuthMiddleware::authentifier();
        $data = self::bodyJson();
        $refreshToken = trim($data['refresh_token'] ?? '');

        $db = Database::connect();

        if ($refreshToken) {
            // Supprimer ce refresh token spécifique
            $db->prepare('DELETE FROM refresh_tokens WHERE token = ? AND utilisateur_id = ?')
               ->execute([$refreshToken, $user['id']]);
        } else {
            // Déconnecter toutes les sessions
            $db->prepare('DELETE FROM refresh_tokens WHERE utilisateur_id = ?')
               ->execute([$user['id']]);
        }

        self::succes([], 'Déconnexion réussie.');
    }

    // ----------------------------------------------------------
    // GET /auth/moi
    // Header: Authorization: Bearer <token>
    // ----------------------------------------------------------
    public static function moi(): void {
        $user = AuthMiddleware::authentifier();

        $db   = Database::connect();
        $stmt = $db->prepare(
            'SELECT id, nom, prenom, email, telephone, role, photo, created_at
             FROM utilisateurs WHERE id = ?'
        );
        $stmt->execute([$user['id']]);
        $profil = $stmt->fetch();

        self::succes(['utilisateur' => $profil]);
    }

    // ----------------------------------------------------------
    // POST /auth/register  (admin seulement)
    // Body: { nom, prenom, email, mot_de_passe, telephone, role }
    // ----------------------------------------------------------
    public static function register(): void {
        AuthMiddleware::adminSeulement();

        $data = self::bodyJson();

        $champs = ['nom', 'prenom', 'email', 'mot_de_passe'];
        foreach ($champs as $c) {
            if (empty($data[$c])) {
                self::erreur(400, "Champ requis : $c");
            }
        }

        $rolesValides = ['admin', 'commercial', 'technicien', 'client'];
        $role = $data['role'] ?? 'client';
        if (!in_array($role, $rolesValides)) {
            self::erreur(400, 'Rôle invalide. Valeurs : ' . implode(', ', $rolesValides));
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            self::erreur(400, 'Adresse email invalide.');
        }

        if (strlen($data['mot_de_passe']) < 8) {
            self::erreur(400, 'Le mot de passe doit contenir au moins 8 caractères.');
        }

        $db = Database::connect();

        // Vérifier unicité email
        $stmt = $db->prepare('SELECT id FROM utilisateurs WHERE email = ?');
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            self::erreur(409, 'Un compte existe déjà avec cet email.');
        }

        $hash = password_hash($data['mot_de_passe'], PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $db->prepare(
            'INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, telephone, role)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim($data['nom']),
            trim($data['prenom']),
            strtolower(trim($data['email'])),
            $hash,
            $data['telephone'] ?? null,
            $role,
        ]);

        $newId = $db->lastInsertId();

        self::succes(['id' => $newId, 'role' => $role], 'Compte créé avec succès.', 201);
    }

    // ----------------------------------------------------------
    // POST /auth/changer-mot-de-passe
    // ----------------------------------------------------------
    public static function changerMotDePasse(): void {
        $user = AuthMiddleware::authentifier();
        $data = self::bodyJson();

        if (empty($data['ancien']) || empty($data['nouveau'])) {
            self::erreur(400, 'Champs requis : ancien, nouveau.');
        }

        if (strlen($data['nouveau']) < 8) {
            self::erreur(400, 'Nouveau mot de passe : 8 caractères minimum.');
        }

        $db   = Database::connect();
        $stmt = $db->prepare('SELECT mot_de_passe FROM utilisateurs WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row  = $stmt->fetch();

        if (!password_verify($data['ancien'], $row['mot_de_passe'])) {
            self::erreur(401, 'Ancien mot de passe incorrect.');
        }

        $newHash = password_hash($data['nouveau'], PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
           ->execute([$newHash, $user['id']]);

        // Invalider toutes les sessions (sécurité)
        $db->prepare('DELETE FROM refresh_tokens WHERE utilisateur_id = ?')
           ->execute([$user['id']]);

        self::succes([], 'Mot de passe modifié. Veuillez vous reconnecter.');
    }

    // ----------------------------------------------------------
    //  Helpers
    // ----------------------------------------------------------
    private static function bodyJson(): array {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    private static function succes(array $data, string $message = 'OK', int $code = 200): void {
        http_response_code($code);
        echo json_encode(['succes' => true, 'message' => $message, 'data' => $data]);
        exit;
    }

    private static function erreur(int $code, string $message): never {
        http_response_code($code);
        echo json_encode(['succes' => false, 'erreur' => $message]);
        exit;
    }
}

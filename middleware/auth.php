<?php
// ============================================================
//  SERENO SMS — Middleware d'authentification & rôles
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';

class AuthMiddleware {

    private static ?array $utilisateurCourant = null;

    // ---- Vérifier que l'utilisateur est connecté ----
    public static function authentifier(): array {
        $token = JWT::extraireDeHeader();

        if (!$token) {
            self::erreur(401, 'Token manquant. Veuillez vous connecter.');
        }

        try {
            $payload = JWT::verifier($token);
        } catch (Exception $e) {
            self::erreur($e->getCode() ?: 401, $e->getMessage());
        }

        // Vérifier que l'utilisateur existe encore et est actif
        $db   = Database::connect();
        $stmt = $db->prepare('SELECT id, nom, prenom, email, role, actif FROM utilisateurs WHERE id = ?');
        $stmt->execute([$payload['id']]);
        $user = $stmt->fetch();

        if (!$user || !$user['actif']) {
            self::erreur(401, 'Compte introuvable ou désactivé.');
        }

        self::$utilisateurCourant = $user;
        return $user;
    }

    // ---- Vérifier un ou plusieurs rôles autorisés ----
    public static function autoriser(string|array $rolesAutorises): array {
        $user = self::authentifier();

        $rolesAutorises = (array) $rolesAutorises;

        if (!in_array($user['role'], $rolesAutorises)) {
            self::erreur(403, sprintf(
                'Accès refusé. Rôle requis : %s. Votre rôle : %s.',
                implode(' ou ', $rolesAutorises),
                $user['role']
            ));
        }

        return $user;
    }

    // ---- Admin uniquement ----
    public static function adminSeulement(): array {
        return self::autoriser('admin');
    }

    // ---- Admin ou commercial ----
    public static function adminOuCommercial(): array {
        return self::autoriser(['admin', 'commercial']);
    }

    // ---- Toute l'équipe interne (pas client) ----
    public static function equipeInterne(): array {
        return self::autoriser(['admin', 'commercial', 'technicien']);
    }

    // ---- Récupérer l'utilisateur courant (après authentifier) ----
    public static function utilisateurCourant(): ?array {
        return self::$utilisateurCourant;
    }

    // ---- Répondre et stopper ----
    private static function erreur(int $code, string $message): never {
        http_response_code($code);
        echo json_encode(['succes' => false, 'erreur' => $message]);
        exit;
    }
}

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
        $stmt = $db->prepare(
            'SELECT u.id, u.nom, u.prenom, u.email, u.role, u.actif, u.garage_id,
                    g.nom AS garage_nom, g.plan AS garage_plan,
                    g.actif AS garage_actif, g.abonnement_fin
             FROM utilisateurs u
             LEFT JOIN garages_sms g ON g.id = u.garage_id
             WHERE u.id = ?'
        );
        $stmt->execute([$payload['id']]);
        $user = $stmt->fetch();

        if (!$user || !$user['actif']) {
            self::erreur(401, 'Compte introuvable ou désactivé.');
        }

        // ---- Contrôle d'abonnement SaaS du garage (7 jours de grâce) ----
        // Les clients finaux gardent l'accès à leur espace.
        if ($user['garage_id'] !== null && $user['role'] !== 'client') {
            if (!$user['garage_actif']) {
                self::erreur(402, 'Le compte de votre garage est suspendu. Contactez SERENO SMS.');
            }
            if ($user['abonnement_fin'] !== null
                && strtotime($user['abonnement_fin'] . ' +7 days') < strtotime(date('Y-m-d'))) {
                self::erreur(402, sprintf(
                    'Abonnement %s expiré le %s. Renouvelez pour retrouver l\'accès.',
                    $user['garage_plan'],
                    date('d/m/Y', strtotime($user['abonnement_fin']))
                ));
            }
        }

        self::$utilisateurCourant = $user;
        return $user;
    }

    // ---- Super-admin plateforme (garage_id NULL) ----
    public static function estSuperAdmin(array $user): bool {
        return $user['role'] === 'admin' && $user['garage_id'] === null;
    }

    public static function superAdminSeulement(): array {
        $user = self::authentifier();
        if (!self::estSuperAdmin($user)) {
            self::erreur(403, 'Réservé à l\'administration de la plateforme SERENO SMS.');
        }
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

<?php
// ============================================================
//  SERENO SMS — Socle commun des contrôleurs
//  Helpers : JSON, réponses, pagination, références
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../middleware/auth.php';

abstract class BaseController {

    // ---- Lire le corps JSON de la requête ----
    protected static function bodyJson(): array {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    // ---- Réponse succès ----
    protected static function succes(array $data = [], string $message = 'OK', int $code = 200): never {
        http_response_code($code);
        echo json_encode(['succes' => true, 'message' => $message, 'data' => $data]);
        exit;
    }

    // ---- Réponse erreur ----
    protected static function erreur(int $code, string $message): never {
        http_response_code($code);
        echo json_encode(['succes' => false, 'erreur' => $message]);
        exit;
    }

    // ---- Valider la présence de champs requis ----
    protected static function requis(array $data, array $champs): void {
        foreach ($champs as $c) {
            if (!isset($data[$c]) || $data[$c] === '' || $data[$c] === null) {
                self::erreur(400, "Champ requis : $c");
            }
        }
    }

    // ---- Pagination standard : ?page=1&limite=20 ----
    protected static function pagination(int $defaut = 20, int $max = 100): array {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limite = min($max, max(1, (int)($_GET['limite'] ?? $defaut)));
        $offset = ($page - 1) * $limite;
        return [$page, $limite, $offset];
    }

    // ---- Générer une référence unique : PREFIX-2026-0001 ----
    protected static function genererReference(PDO $db, string $table, string $prefixe): string {
        $annee = date('Y');
        $stmt  = $db->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE reference LIKE ?"
        );
        $stmt->execute(["$prefixe-$annee-%"]);
        $numero = (int)$stmt->fetchColumn() + 1;
        // Boucle de sécurité en cas de collision
        do {
            $ref  = sprintf('%s-%s-%04d', $prefixe, $annee, $numero);
            $test = $db->prepare("SELECT id FROM `$table` WHERE reference = ?");
            $test->execute([$ref]);
            $numero++;
        } while ($test->fetch());
        return $ref;
    }

    // ---- Vérifier qu'une ligne existe, sinon 404 ----
    protected static function trouverOu404(PDO $db, string $table, int $id, string $nom = 'Ressource'): array {
        $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        $ligne = $stmt->fetch();
        if (!$ligne) {
            self::erreur(404, "$nom introuvable (id $id).");
        }
        return $ligne;
    }

    // ---- Décoder un champ JSON stocké en base ----
    protected static function decoderJson(?string $valeur): array {
        if (!$valeur) return [];
        return json_decode($valeur, true) ?? [];
    }

    // ==========================================================
    //  MULTI-TENANT (SaaS) — cloisonnement par garage
    // ==========================================================

    /** Plans SaaS : mensualité (F CFA) et quota de véhicules suivis. */
    public const PLANS_SAAS = [
        'starter'    => ['mensualite' => 25000,  'quota_vehicules' => 100],
        'pro'        => ['mensualite' => 50000,  'quota_vehicules' => 500],
        'enterprise' => ['mensualite' => 100000, 'quota_vehicules' => null], // illimité
    ];

    /** Garage de l'utilisateur, ou null = super-admin (accès global). */
    protected static function garageDe(array $user): ?int {
        return $user['garage_id'] !== null ? (int)$user['garage_id'] : null;
    }

    /**
     * Vérifie qu'une ressource appartient au garage de l'utilisateur.
     * 404 volontaire (et non 403) pour ne pas révéler l'existence
     * de données d'un autre garage.
     */
    protected static function verifierGarage(?int $garageUser, int|string|null $garageRessource): void {
        if ($garageUser !== null && (int)$garageRessource !== $garageUser) {
            self::erreur(404, 'Ressource introuvable.');
        }
    }

    /** garage_id d'un client. */
    protected static function garageDuClient(PDO $db, int $clientId): int {
        $stmt = $db->prepare('SELECT garage_id FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $g = $stmt->fetchColumn();
        if ($g === false) self::erreur(404, "Client introuvable (id $clientId).");
        return (int)$g;
    }

    /** garage_id d'un véhicule (via son client). */
    protected static function garageDuVehicule(PDO $db, int $vehiculeId): int {
        $stmt = $db->prepare(
            'SELECT c.garage_id FROM vehicules v JOIN clients c ON c.id = v.client_id WHERE v.id = ?'
        );
        $stmt->execute([$vehiculeId]);
        $g = $stmt->fetchColumn();
        if ($g === false) self::erreur(404, "Véhicule introuvable (id $vehiculeId).");
        return (int)$g;
    }

    /** garage_id d'un contrat (via son client). */
    protected static function garageDuContrat(PDO $db, int $contratId): int {
        $stmt = $db->prepare(
            'SELECT c.garage_id FROM contrats_csa ct JOIN clients c ON c.id = ct.client_id WHERE ct.id = ?'
        );
        $stmt->execute([$contratId]);
        $g = $stmt->fetchColumn();
        if ($g === false) self::erreur(404, "Contrat introuvable (id $contratId).");
        return (int)$g;
    }
}

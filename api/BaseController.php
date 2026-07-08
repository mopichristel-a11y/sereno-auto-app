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
}

<?php
// ============================================================
//  SERENO SMS — GarageController
//  Garages partenaires du réseau SERENO (base du futur SaaS)
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class GarageController extends BaseController {

    // ----------------------------------------------------------
    // GET /garages?actif=1&recherche=
    // ----------------------------------------------------------
    public static function index(): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $where  = [];
        $params = [];
        if (isset($_GET['actif']) && $_GET['actif'] !== '') {
            $where[] = 'actif = :actif';
            $params[':actif'] = (int)$_GET['actif'];
        }
        if (!empty($_GET['recherche'])) {
            $where[] = '(nom LIKE :q OR adresse LIKE :q)';
            $params[':q'] = '%' . trim($_GET['recherche']) . '%';
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("SELECT * FROM garages_partenaires $sqlWhere ORDER BY note DESC, nom ASC");
        $stmt->execute($params);
        $garages = $stmt->fetchAll();
        foreach ($garages as &$g) {
            $g['specialites'] = self::decoderJson($g['specialites']);
        }

        self::succes(['garages' => $garages]);
    }

    // ----------------------------------------------------------
    // POST /garages  (admin)
    // Body: { nom, adresse?, telephone?, email?, specialites?: [..],
    //         latitude?, longitude? }
    // ----------------------------------------------------------
    public static function store(): void {
        AuthMiddleware::adminSeulement();
        $data = self::bodyJson();
        self::requis($data, ['nom']);

        $specialites = $data['specialites'] ?? [];
        if (!is_array($specialites)) self::erreur(400, 'specialites doit être un tableau.');

        $db = Database::connect();
        $db->prepare(
            'INSERT INTO garages_partenaires (nom, adresse, telephone, email, specialites, latitude, longitude)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            trim($data['nom']),
            $data['adresse'] ?? null,
            $data['telephone'] ?? null,
            $data['email'] ?? null,
            json_encode($specialites, JSON_UNESCAPED_UNICODE),
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
        ]);

        self::succes(['id' => (int)$db->lastInsertId()], 'Garage partenaire ajouté.', 201);
    }

    // ----------------------------------------------------------
    // PUT /garages/{id}  (admin)
    // ----------------------------------------------------------
    public static function update(int $id): void {
        AuthMiddleware::adminSeulement();
        $data = self::bodyJson();
        $db   = Database::connect();

        self::trouverOu404($db, 'garages_partenaires', $id, 'Garage');

        $set    = [];
        $params = [];
        foreach (['nom', 'adresse', 'telephone', 'email', 'latitude', 'longitude', 'note', 'actif'] as $c) {
            if (array_key_exists($c, $data)) {
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        if (array_key_exists('specialites', $data)) {
            if (!is_array($data['specialites'])) self::erreur(400, 'specialites doit être un tableau.');
            $set[]    = 'specialites = ?';
            $params[] = json_encode($data['specialites'], JSON_UNESCAPED_UNICODE);
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE garages_partenaires SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Garage mis à jour.');
    }

    // ----------------------------------------------------------
    // DELETE /garages/{id}  (admin) — désactivation logique
    // ----------------------------------------------------------
    public static function destroy(int $id): void {
        AuthMiddleware::adminSeulement();
        $db = Database::connect();
        self::trouverOu404($db, 'garages_partenaires', $id, 'Garage');
        $db->prepare('UPDATE garages_partenaires SET actif = 0 WHERE id = ?')->execute([$id]);
        self::succes([], 'Garage désactivé.');
    }
}

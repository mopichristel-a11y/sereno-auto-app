<?php
// ============================================================
//  SERENO SMS — InterventionController (Module 5)
//  Planifier, démarrer, clôturer une intervention.
//  La clôture alimente automatiquement le carnet d'entretien.
// ============================================================

require_once __DIR__ . '/../BaseController.php';
require_once __DIR__ . '/../vehicules/VehiculeController.php';

class InterventionController extends BaseController {

    // ----------------------------------------------------------
    // GET /interventions?statut=&vehicule_id=&technicien_id=&page=
    // ----------------------------------------------------------
    public static function index(): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();
        [$page, $limite, $offset] = self::pagination();

        $where  = [];
        $params = [];

        if (($garage = self::garageDe($user)) !== null) {
            $where[] = 'c.garage_id = :garage';
            $params[':garage'] = $garage;
        }
        if (!empty($_GET['statut'])) {
            $where[] = 'i.statut = :statut';
            $params[':statut'] = $_GET['statut'];
        }
        if (!empty($_GET['vehicule_id'])) {
            $where[] = 'i.vehicule_id = :vid';
            $params[':vid'] = (int)$_GET['vehicule_id'];
        }
        if (!empty($_GET['technicien_id'])) {
            $where[] = 'i.technicien_id = :tid';
            $params[':tid'] = (int)$_GET['technicien_id'];
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $db->prepare(
            "SELECT COUNT(*) FROM interventions i
             JOIN vehicules v ON v.id = i.vehicule_id
             JOIN clients c   ON c.id = v.client_id
             $sqlWhere"
        );
        $total->execute($params);
        $nbTotal = (int)$total->fetchColumn();

        $stmt = $db->prepare(
            "SELECT i.*, v.marque, v.modele, v.immatriculation,
                    c.id AS client_id, c.nom AS client_nom,
                    CONCAT(u.prenom, ' ', u.nom) AS technicien,
                    d.reference AS devis_reference, d.total AS devis_total
             FROM interventions i
             JOIN vehicules v ON v.id = i.vehicule_id
             JOIN clients c   ON c.id = v.client_id
             LEFT JOIN utilisateurs u ON u.id = i.technicien_id
             LEFT JOIN devis d        ON d.id = i.devis_id
             $sqlWhere
             ORDER BY FIELD(i.statut, 'en_cours', 'planifié', 'terminé', 'annulé'), i.created_at DESC
             LIMIT $limite OFFSET $offset"
        );
        $stmt->execute($params);
        $interventions = $stmt->fetchAll();
        foreach ($interventions as &$i) {
            $i['photos'] = self::decoderJson($i['photos']);
        }

        self::succes([
            'interventions' => $interventions,
            'total'         => $nbTotal,
            'page'          => $page,
            'pages'         => (int)ceil($nbTotal / $limite),
        ]);
    }

    // ----------------------------------------------------------
    // GET /interventions/{id}
    // ----------------------------------------------------------
    public static function show(int $id): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $stmt = $db->prepare(
            "SELECT i.*, v.marque, v.modele, v.immatriculation, v.kilometrage AS vehicule_km,
                    c.id AS client_id, c.nom AS client_nom, c.telephone AS client_telephone,
                    CONCAT(u.prenom, ' ', u.nom) AS technicien,
                    d.reference AS devis_reference, d.total AS devis_total, d.lignes AS devis_lignes
             FROM interventions i
             JOIN vehicules v ON v.id = i.vehicule_id
             JOIN clients c   ON c.id = v.client_id
             LEFT JOIN utilisateurs u ON u.id = i.technicien_id
             LEFT JOIN devis d        ON d.id = i.devis_id
             WHERE i.id = ?"
        );
        $stmt->execute([$id]);
        $intervention = $stmt->fetch();
        if (!$intervention) self::erreur(404, "Intervention introuvable (id $id).");
        self::verifierGarage(
            self::garageDe(AuthMiddleware::utilisateurCourant()),
            self::garageDuVehicule($db, (int)$intervention['vehicule_id'])
        );

        $intervention['photos']       = self::decoderJson($intervention['photos']);
        $intervention['devis_lignes'] = self::decoderJson($intervention['devis_lignes']);

        $carnet = $db->prepare(
            'SELECT * FROM carnet_entretien WHERE intervention_id = ? ORDER BY id ASC'
        );
        $carnet->execute([$id]);

        self::succes([
            'intervention' => $intervention,
            'carnet'       => $carnet->fetchAll(),
        ]);
    }

    // ----------------------------------------------------------
    // POST /interventions — planifier
    // Body: { vehicule_id, devis_id?, technicien_id?, date_debut? }
    // ----------------------------------------------------------
    public static function store(): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['vehicule_id']);

        $db = Database::connect();
        self::trouverOu404($db, 'vehicules', (int)$data['vehicule_id'], 'Véhicule');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, (int)$data['vehicule_id']));

        if (!empty($data['devis_id'])) {
            self::trouverOu404($db, 'devis', (int)$data['devis_id'], 'Devis');
        }
        if (!empty($data['technicien_id'])) {
            $tech = $db->prepare("SELECT id FROM utilisateurs WHERE id = ? AND role IN ('technicien','admin')");
            $tech->execute([(int)$data['technicien_id']]);
            if (!$tech->fetch()) self::erreur(404, 'Technicien introuvable.');
        }

        $reference = self::genererReference($db, 'interventions', 'INT');

        $db->prepare(
            'INSERT INTO interventions (reference, devis_id, vehicule_id, technicien_id, date_debut, statut)
             VALUES (?, ?, ?, ?, ?, "planifié")'
        )->execute([
            $reference,
            !empty($data['devis_id']) ? (int)$data['devis_id'] : null,
            (int)$data['vehicule_id'],
            !empty($data['technicien_id']) ? (int)$data['technicien_id'] : null,
            $data['date_debut'] ?? null,
        ]);

        self::succes(['id' => (int)$db->lastInsertId(), 'reference' => $reference],
                     'Intervention planifiée.', 201);
    }

    // ----------------------------------------------------------
    // PUT /interventions/{id} — réaffecter technicien / dates
    // ----------------------------------------------------------
    public static function update(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        $db   = Database::connect();

        $intervention = self::trouverOu404($db, 'interventions', $id, 'Intervention');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, (int)$intervention['vehicule_id']));
        if (in_array($intervention['statut'], ['terminé', 'annulé'])) {
            self::erreur(409, "Intervention {$intervention['statut']} : modification impossible.");
        }

        $set    = [];
        $params = [];
        foreach (['technicien_id', 'date_debut', 'date_fin', 'rapport'] as $c) {
            if (array_key_exists($c, $data)) {
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        if (array_key_exists('photos', $data)) {
            if (!is_array($data['photos'])) self::erreur(400, 'photos doit être un tableau.');
            $set[]    = 'photos = ?';
            $params[] = json_encode($data['photos'], JSON_UNESCAPED_UNICODE);
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE interventions SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Intervention mise à jour.');
    }

    // ----------------------------------------------------------
    // POST /interventions/{id}/demarrer
    // ----------------------------------------------------------
    public static function demarrer(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $db   = Database::connect();

        $intervention = self::trouverOu404($db, 'interventions', $id, 'Intervention');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, (int)$intervention['vehicule_id']));
        if ($intervention['statut'] !== 'planifié') {
            self::erreur(409, "Seule une intervention planifiée peut démarrer (statut actuel : {$intervention['statut']}).");
        }

        // Le technicien qui démarre est affecté s'il n'y en a pas
        $technicienId = $intervention['technicien_id'] ?: ($user['role'] === 'technicien' ? $user['id'] : null);

        $db->prepare(
            "UPDATE interventions SET statut = 'en_cours', date_debut = NOW(), technicien_id = ? WHERE id = ?"
        )->execute([$technicienId, $id]);

        self::succes([], 'Intervention démarrée.');
    }

    // ----------------------------------------------------------
    // POST /interventions/{id}/cloturer
    // Body: { rapport, photos?: [url], kilometrage?,
    //         entretiens?: [{type_entretien, notes?}] }
    // → statut terminé + entrées carnet + prochaines échéances
    // ----------------------------------------------------------
    public static function cloturer(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['rapport']);

        $db = Database::connect();
        $intervention = self::trouverOu404($db, 'interventions', $id, 'Intervention');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, (int)$intervention['vehicule_id']));
        if (!in_array($intervention['statut'], ['planifié', 'en_cours'])) {
            self::erreur(409, "Intervention déjà {$intervention['statut']}.");
        }

        $vehicule = self::trouverOu404($db, 'vehicules', (int)$intervention['vehicule_id'], 'Véhicule');
        $km = isset($data['kilometrage']) ? (int)$data['kilometrage'] : (int)$vehicule['kilometrage'];

        $photos = $data['photos'] ?? [];
        if (!is_array($photos)) self::erreur(400, 'photos doit être un tableau.');

        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE interventions
                 SET statut = 'terminé', date_fin = NOW(), rapport = ?, photos = ?
                 WHERE id = ?"
            )->execute([
                trim($data['rapport']),
                json_encode($photos, JSON_UNESCAPED_UNICODE),
                $id,
            ]);

            // Alimenter le carnet d'entretien
            $entretiens = $data['entretiens'] ?? [];
            $ajoutes    = [];
            foreach ($entretiens as $e) {
                $type = $e['type_entretien'] ?? '';
                if (!isset(VehiculeController::INTERVALLES[$type])) {
                    throw new InvalidArgumentException("type_entretien invalide : $type");
                }
                [$kmIntervalle, $moisIntervalle] = VehiculeController::INTERVALLES[$type];
                $prochainKm    = $kmIntervalle ? $km + $kmIntervalle : null;
                $prochaineDate = $moisIntervalle ? date('Y-m-d', strtotime("+$moisIntervalle months")) : null;

                $db->prepare(
                    'INSERT INTO carnet_entretien (vehicule_id, intervention_id, type_entretien,
                                                   date_intervention, kilometrage, prochain_km, prochaine_date, notes)
                     VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?)'
                )->execute([
                    (int)$intervention['vehicule_id'],
                    $id,
                    $type,
                    $km,
                    $prochainKm,
                    $prochaineDate,
                    $e['notes'] ?? ('Intervention ' . $intervention['reference']),
                ]);
                $ajoutes[] = [
                    'type'           => $type,
                    'prochain_km'    => $prochainKm,
                    'prochaine_date' => $prochaineDate,
                ];
            }

            // Kilométrage véhicule
            if ($km > (int)$vehicule['kilometrage']) {
                $db->prepare('UPDATE vehicules SET kilometrage = ? WHERE id = ?')
                   ->execute([$km, (int)$intervention['vehicule_id']]);
            }

            $db->commit();
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            self::erreur(400, $e->getMessage());
        } catch (Throwable $e) {
            $db->rollBack();
            self::erreur(500, 'Erreur lors de la clôture : ' . $e->getMessage());
        }

        self::succes(['carnet' => $ajoutes], 'Intervention clôturée et carnet mis à jour.');
    }

    // ----------------------------------------------------------
    // POST /interventions/{id}/annuler
    // ----------------------------------------------------------
    public static function annuler(int $id): void {
        $user = AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        $db   = Database::connect();

        $intervention = self::trouverOu404($db, 'interventions', $id, 'Intervention');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, (int)$intervention['vehicule_id']));
        if ($intervention['statut'] === 'terminé') {
            self::erreur(409, 'Intervention terminée : annulation impossible.');
        }

        $motif = trim($data['motif'] ?? '');
        $db->prepare(
            "UPDATE interventions SET statut = 'annulé',
                    rapport = CONCAT(COALESCE(rapport, ''), ?)
             WHERE id = ?"
        )->execute(["\n[Annulée le " . date('d/m/Y') . ($motif ? " — $motif" : '') . ']', $id]);

        self::succes([], 'Intervention annulée.');
    }
}

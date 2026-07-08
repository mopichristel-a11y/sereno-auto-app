<?php
// ============================================================
//  SERENO SMS — VehiculeController (Module 2)
//  CRUD véhicules + moteur de rappels automatiques
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class VehiculeController extends BaseController {

    /**
     * Intervalles d'entretien recommandés : [km, mois]
     * null = critère non applicable
     */
    public const INTERVALLES = [
        'vidange'          => [5000,  6],
        'filtre_air'       => [15000, 12],
        'filtre_habitacle' => [15000, 12],
        'filtre_carburant' => [30000, 24],
        'filtre_boite'     => [60000, 48],
        'plaquettes'       => [30000, 24],
        'disques'          => [60000, 48],
        'pneus'            => [40000, 48],
        'batterie'         => [null,  36],
        'amortisseurs'     => [80000, 60],
        'courroie'         => [90000, 60],
        'climatisation'    => [null,  24],
        'diagnostic'       => [null,  12],
        'reparation_autre' => [null,  null],
    ];

    public const LIBELLES = [
        'vidange'          => 'Vidange moteur',
        'filtre_air'       => 'Filtre à air',
        'filtre_habitacle' => 'Filtre habitacle',
        'filtre_carburant' => 'Filtre à carburant',
        'filtre_boite'     => 'Filtre / huile de boîte',
        'plaquettes'       => 'Plaquettes de frein',
        'disques'          => 'Disques de frein',
        'pneus'            => 'Pneus',
        'batterie'         => 'Batterie',
        'amortisseurs'     => 'Amortisseurs',
        'courroie'         => 'Courroie de distribution',
        'climatisation'    => 'Climatisation',
        'diagnostic'       => 'Diagnostic électronique',
        'reparation_autre' => 'Autre réparation',
    ];

    // ----------------------------------------------------------
    // GET /vehicules?client_id=&recherche=&page=&limite=
    // ----------------------------------------------------------
    public static function index(): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();
        [$page, $limite, $offset] = self::pagination();

        $where  = [];
        $params = [];

        if (!empty($_GET['client_id'])) {
            $where[] = 'v.client_id = :cid';
            $params[':cid'] = (int)$_GET['client_id'];
        }
        if (!empty($_GET['recherche'])) {
            $where[] = '(v.marque LIKE :q OR v.modele LIKE :q OR v.immatriculation LIKE :q
                         OR v.vin LIKE :q OR c.nom LIKE :q)';
            $params[':q'] = '%' . trim($_GET['recherche']) . '%';
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $db->prepare("SELECT COUNT(*) FROM vehicules v JOIN clients c ON c.id = v.client_id $sqlWhere");
        $total->execute($params);
        $nbTotal = (int)$total->fetchColumn();

        $stmt = $db->prepare(
            "SELECT v.*, c.nom AS client_nom, c.telephone AS client_telephone,
                    (SELECT ct.statut FROM contrats_csa ct WHERE ct.vehicule_id = v.id
                     AND ct.statut = 'actif' LIMIT 1) AS contrat_statut,
                    (SELECT f.nom FROM contrats_csa ct JOIN formules_csa f ON f.id = ct.formule_id
                     WHERE ct.vehicule_id = v.id AND ct.statut = 'actif' LIMIT 1) AS formule,
                    (SELECT d.date_diagnostic FROM diagnostics d WHERE d.vehicule_id = v.id
                     ORDER BY d.date_diagnostic DESC LIMIT 1) AS dernier_diagnostic
             FROM vehicules v
             JOIN clients c ON c.id = v.client_id
             $sqlWhere
             ORDER BY v.created_at DESC
             LIMIT $limite OFFSET $offset"
        );
        $stmt->execute($params);

        self::succes([
            'vehicules' => $stmt->fetchAll(),
            'total'     => $nbTotal,
            'page'      => $page,
            'pages'     => (int)ceil($nbTotal / $limite),
        ]);
    }

    // ----------------------------------------------------------
    // GET /vehicules/{id}
    // ----------------------------------------------------------
    public static function show(int $id): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $stmt = $db->prepare(
            'SELECT v.*, c.nom AS client_nom, c.telephone AS client_telephone
             FROM vehicules v JOIN clients c ON c.id = v.client_id
             WHERE v.id = ?'
        );
        $stmt->execute([$id]);
        $vehicule = $stmt->fetch();
        if (!$vehicule) self::erreur(404, "Véhicule introuvable (id $id).");

        $contrat = $db->prepare(
            "SELECT ct.*, f.nom AS formule FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             WHERE ct.vehicule_id = ? AND ct.statut = 'actif' LIMIT 1"
        );
        $contrat->execute([$id]);

        $diagnostics = $db->prepare(
            'SELECT id, reference, date_diagnostic, outil, kilometrage, statut, codes_dtc
             FROM diagnostics WHERE vehicule_id = ? ORDER BY date_diagnostic DESC LIMIT 10'
        );
        $diagnostics->execute([$id]);

        self::succes([
            'vehicule'    => $vehicule,
            'contrat'     => $contrat->fetch() ?: null,
            'diagnostics' => $diagnostics->fetchAll(),
            'rappels'     => self::calculerRappels($db, $id, (int)$vehicule['kilometrage']),
        ]);
    }

    // ----------------------------------------------------------
    // POST /vehicules
    // ----------------------------------------------------------
    public static function store(): void {
        AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['client_id', 'marque', 'modele']);

        $db = Database::connect();
        self::trouverOu404($db, 'clients', (int)$data['client_id'], 'Client');

        $stmt = $db->prepare(
            'INSERT INTO vehicules (client_id, marque, modele, annee, immatriculation, vin,
                                    kilometrage, couleur, carburant, boite_vitesses, date_achat, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$data['client_id'],
            trim($data['marque']),
            trim($data['modele']),
            $data['annee'] ?? null,
            $data['immatriculation'] ?? null,
            $data['vin'] ?? null,
            (int)($data['kilometrage'] ?? 0),
            $data['couleur'] ?? null,
            $data['carburant'] ?? 'essence',
            $data['boite_vitesses'] ?? 'manuelle',
            $data['date_achat'] ?? null,
            $data['notes'] ?? null,
        ]);

        self::succes(['id' => (int)$db->lastInsertId()], 'Véhicule enregistré.', 201);
    }

    // ----------------------------------------------------------
    // PUT /vehicules/{id}
    // ----------------------------------------------------------
    public static function update(int $id): void {
        AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        $db   = Database::connect();

        $vehicule = self::trouverOu404($db, 'vehicules', $id, 'Véhicule');

        // Le kilométrage ne doit jamais reculer
        if (isset($data['kilometrage']) && (int)$data['kilometrage'] < (int)$vehicule['kilometrage']) {
            self::erreur(400, sprintf(
                'Kilométrage incohérent : %d km est inférieur au relevé actuel (%d km).',
                (int)$data['kilometrage'], (int)$vehicule['kilometrage']
            ));
        }

        $champs = ['marque', 'modele', 'annee', 'immatriculation', 'vin', 'kilometrage',
                   'couleur', 'carburant', 'boite_vitesses', 'date_achat', 'notes',
                   'photo_1', 'photo_2', 'photo_3'];
        $set    = [];
        $params = [];
        foreach ($champs as $c) {
            if (array_key_exists($c, $data)) {
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE vehicules SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        self::succes([], 'Véhicule mis à jour.');
    }

    // ----------------------------------------------------------
    // DELETE /vehicules/{id}  (admin)
    // ----------------------------------------------------------
    public static function destroy(int $id): void {
        AuthMiddleware::adminSeulement();
        $db = Database::connect();
        self::trouverOu404($db, 'vehicules', $id, 'Véhicule');

        $actifs = $db->prepare("SELECT COUNT(*) FROM contrats_csa WHERE vehicule_id = ? AND statut = 'actif'");
        $actifs->execute([$id]);
        if ($actifs->fetchColumn() > 0) {
            self::erreur(409, 'Impossible de supprimer : un contrat CSA actif couvre ce véhicule.');
        }

        $db->prepare('DELETE FROM vehicules WHERE id = ?')->execute([$id]);
        self::succes([], 'Véhicule supprimé.');
    }

    // ----------------------------------------------------------
    // GET /vehicules/{id}/rappels
    // Moteur de rappels : croise carnet d'entretien + intervalles
    // ----------------------------------------------------------
    public static function rappels(int $id): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();
        $vehicule = self::trouverOu404($db, 'vehicules', $id, 'Véhicule');

        self::succes([
            'vehicule' => ['id' => $id, 'kilometrage' => (int)$vehicule['kilometrage']],
            'rappels'  => self::calculerRappels($db, $id, (int)$vehicule['kilometrage']),
        ]);
    }

    /**
     * Calcule l'état de chaque poste d'entretien pour un véhicule.
     * Retourne : type, libelle, derniere_date, dernier_km, prochain_km,
     *            prochaine_date, jours_restants, km_restants, urgence
     * Urgence : 'depasse' | 'urgent' (≤ 15 j ou ≤ 1000 km) | 'bientot' (≤ 45 j ou ≤ 3000 km) | 'ok' | 'inconnu'
     */
    public static function calculerRappels(PDO $db, int $vehiculeId, int $kmActuel): array {
        $stmt = $db->prepare(
            'SELECT type_entretien, date_intervention, kilometrage, prochain_km, prochaine_date
             FROM carnet_entretien
             WHERE vehicule_id = ?
             ORDER BY date_intervention DESC, id DESC'
        );
        $stmt->execute([$vehiculeId]);

        // Ne garder que la dernière entrée par type
        $derniers = [];
        foreach ($stmt->fetchAll() as $ligne) {
            if (!isset($derniers[$ligne['type_entretien']])) {
                $derniers[$ligne['type_entretien']] = $ligne;
            }
        }

        $rappels = [];
        foreach (self::INTERVALLES as $type => [$kmIntervalle, $moisIntervalle]) {
            if ($type === 'reparation_autre') continue;

            $dernier = $derniers[$type] ?? null;

            if (!$dernier) {
                $rappels[] = [
                    'type'            => $type,
                    'libelle'         => self::LIBELLES[$type],
                    'derniere_date'   => null,
                    'dernier_km'      => null,
                    'prochain_km'     => null,
                    'prochaine_date'  => null,
                    'jours_restants'  => null,
                    'km_restants'     => null,
                    'urgence'         => 'inconnu',
                ];
                continue;
            }

            // Échéances : valeurs saisies au carnet, sinon calcul par intervalle
            $prochainKm = $dernier['prochain_km']
                ?: ($kmIntervalle && $dernier['kilometrage'] ? (int)$dernier['kilometrage'] + $kmIntervalle : null);
            $prochaineDate = $dernier['prochaine_date']
                ?: ($moisIntervalle ? date('Y-m-d', strtotime($dernier['date_intervention'] . " +$moisIntervalle months")) : null);

            $joursRestants = $prochaineDate
                ? (int)floor((strtotime($prochaineDate) - strtotime(date('Y-m-d'))) / 86400)
                : null;
            $kmRestants = $prochainKm !== null ? $prochainKm - $kmActuel : null;

            $urgence = 'ok';
            if (($joursRestants !== null && $joursRestants < 0) || ($kmRestants !== null && $kmRestants < 0)) {
                $urgence = 'depasse';
            } elseif (($joursRestants !== null && $joursRestants <= 15) || ($kmRestants !== null && $kmRestants <= 1000)) {
                $urgence = 'urgent';
            } elseif (($joursRestants !== null && $joursRestants <= 45) || ($kmRestants !== null && $kmRestants <= 3000)) {
                $urgence = 'bientot';
            }

            $rappels[] = [
                'type'            => $type,
                'libelle'         => self::LIBELLES[$type],
                'derniere_date'   => $dernier['date_intervention'],
                'dernier_km'      => $dernier['kilometrage'] !== null ? (int)$dernier['kilometrage'] : null,
                'prochain_km'     => $prochainKm,
                'prochaine_date'  => $prochaineDate,
                'jours_restants'  => $joursRestants,
                'km_restants'     => $kmRestants,
                'urgence'         => $urgence,
            ];
        }

        // Trier : dépassé > urgent > bientôt > ok > inconnu
        $ordre = ['depasse' => 0, 'urgent' => 1, 'bientot' => 2, 'ok' => 3, 'inconnu' => 4];
        usort($rappels, fn($a, $b) => $ordre[$a['urgence']] <=> $ordre[$b['urgence']]);

        return $rappels;
    }
}

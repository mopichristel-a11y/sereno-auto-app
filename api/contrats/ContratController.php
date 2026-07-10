<?php
// ============================================================
//  SERENO SMS — ContratController (Module 3)
//  Contrats CSA : création, résiliation, renouvellement,
//  alertes d'expiration, calcul du taux de couverture
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class ContratController extends BaseController {

    // ----------------------------------------------------------
    // GET /formules — les 3 formules CSA
    // ----------------------------------------------------------
    public static function formules(): void {
        AuthMiddleware::authentifier();
        $db = Database::connect();
        $formules = $db->query('SELECT * FROM formules_csa WHERE actif = 1 ORDER BY mensualite ASC')->fetchAll();
        foreach ($formules as &$f) {
            $f['garanties'] = self::decoderJson($f['garanties']);
        }
        self::succes(['formules' => $formules]);
    }

    // ----------------------------------------------------------
    // GET /contrats?statut=&client_id=&formule=&page=
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
            $where[] = 'ct.statut = :statut';
            $params[':statut'] = $_GET['statut'];
        }
        if (!empty($_GET['client_id'])) {
            $where[] = 'ct.client_id = :cid';
            $params[':cid'] = (int)$_GET['client_id'];
        }
        if (!empty($_GET['formule'])) {
            $where[] = 'f.nom = :formule';
            $params[':formule'] = $_GET['formule'];
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $db->prepare(
            "SELECT COUNT(*) FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             JOIN clients c      ON c.id = ct.client_id
             $sqlWhere"
        );
        $total->execute($params);
        $nbTotal = (int)$total->fetchColumn();

        $stmt = $db->prepare(
            "SELECT ct.*, f.nom AS formule, c.nom AS client_nom, c.telephone AS client_telephone,
                    v.marque, v.modele, v.immatriculation,
                    CONCAT(u.prenom, ' ', u.nom) AS commercial,
                    DATEDIFF(ct.date_expiration, CURDATE()) AS jours_avant_expiration,
                    (SELECT COALESCE(SUM(p.montant), 0) FROM paiements p
                     WHERE p.contrat_id = ct.id AND p.statut = 'payé') AS total_paye
             FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             JOIN clients c      ON c.id = ct.client_id
             JOIN vehicules v    ON v.id = ct.vehicule_id
             LEFT JOIN utilisateurs u ON u.id = ct.commercial_id
             $sqlWhere
             ORDER BY ct.created_at DESC
             LIMIT $limite OFFSET $offset"
        );
        $stmt->execute($params);
        $contrats = $stmt->fetchAll();

        // État de paiement : mensualités échues vs payées
        foreach ($contrats as &$ct) {
            $ct['echeances'] = self::etatEcheances($ct);
        }

        self::succes([
            'contrats' => $contrats,
            'total'    => $nbTotal,
            'page'     => $page,
            'pages'    => (int)ceil($nbTotal / $limite),
        ]);
    }

    // ----------------------------------------------------------
    // GET /contrats/{id}
    // ----------------------------------------------------------
    public static function show(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, $id));

        $stmt = $db->prepare(
            "SELECT ct.*, f.nom AS formule, f.garanties, c.nom AS client_nom, c.telephone AS client_telephone,
                    v.marque, v.modele, v.immatriculation,
                    CONCAT(u.prenom, ' ', u.nom) AS commercial,
                    DATEDIFF(ct.date_expiration, CURDATE()) AS jours_avant_expiration
             FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             JOIN clients c      ON c.id = ct.client_id
             JOIN vehicules v    ON v.id = ct.vehicule_id
             LEFT JOIN utilisateurs u ON u.id = ct.commercial_id
             WHERE ct.id = ?"
        );
        $stmt->execute([$id]);
        $contrat = $stmt->fetch();
        if (!$contrat) self::erreur(404, "Contrat introuvable (id $id).");

        $contrat['garanties'] = self::decoderJson($contrat['garanties']);

        $paiements = $db->prepare('SELECT * FROM paiements WHERE contrat_id = ? ORDER BY date_paiement DESC');
        $paiements->execute([$id]);
        $listePaiements = $paiements->fetchAll();

        $totalPaye = 0;
        foreach ($listePaiements as $p) {
            if ($p['statut'] === 'payé') $totalPaye += (float)$p['montant'];
        }
        $contrat['total_paye'] = $totalPaye;

        self::succes([
            'contrat'   => $contrat,
            'paiements' => $listePaiements,
            'echeances' => self::etatEcheances($contrat),
        ]);
    }

    // ----------------------------------------------------------
    // POST /contrats
    // Body: { client_id, vehicule_id, formule (nom) | formule_id,
    //         date_debut, duree_mois?=12, notes? }
    // ----------------------------------------------------------
    public static function store(): void {
        $user = AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        self::requis($data, ['client_id', 'vehicule_id', 'date_debut']);

        $db = Database::connect();
        $client   = self::trouverOu404($db, 'clients', (int)$data['client_id'], 'Client');
        self::verifierGarage(self::garageDe($user), $client['garage_id']);
        $vehicule = self::trouverOu404($db, 'vehicules', (int)$data['vehicule_id'], 'Véhicule');

        if ((int)$vehicule['client_id'] !== (int)$data['client_id']) {
            self::erreur(400, 'Ce véhicule n\'appartient pas à ce client.');
        }

        // Formule par id ou par nom
        if (!empty($data['formule_id'])) {
            $stmt = $db->prepare('SELECT * FROM formules_csa WHERE id = ? AND actif = 1');
            $stmt->execute([(int)$data['formule_id']]);
        } elseif (!empty($data['formule'])) {
            $stmt = $db->prepare('SELECT * FROM formules_csa WHERE nom = ? AND actif = 1');
            $stmt->execute([strtolower($data['formule'])]);
        } else {
            self::erreur(400, 'Précisez formule_id ou formule (essentiel | confort | premium).');
        }
        $formule = $stmt->fetch();
        if (!$formule) self::erreur(404, 'Formule CSA introuvable.');

        // Un seul contrat actif par véhicule
        $actif = $db->prepare("SELECT reference FROM contrats_csa WHERE vehicule_id = ? AND statut = 'actif'");
        $actif->execute([(int)$data['vehicule_id']]);
        if ($existant = $actif->fetch()) {
            self::erreur(409, "Ce véhicule a déjà un contrat actif ({$existant['reference']}).");
        }

        $dureeMois = max(1, (int)($data['duree_mois'] ?? 12));
        $dateDebut = $data['date_debut'];
        if (!strtotime($dateDebut)) self::erreur(400, 'date_debut invalide (format AAAA-MM-JJ).');
        $dateExpiration = date('Y-m-d', strtotime("$dateDebut +$dureeMois months -1 day"));

        $reference = self::genererReference($db, 'contrats_csa', 'CSA');

        $stmt = $db->prepare(
            'INSERT INTO contrats_csa (reference, client_id, vehicule_id, formule_id, date_debut,
                                       date_expiration, mensualite, couverture_pct, statut, commercial_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "actif", ?, ?)'
        );
        $stmt->execute([
            $reference,
            (int)$data['client_id'],
            (int)$data['vehicule_id'],
            (int)$formule['id'],
            $dateDebut,
            $dateExpiration,
            $formule['mensualite'],
            $formule['couverture_pct'],
            $user['id'],
            $data['notes'] ?? null,
        ]);

        self::succes([
            'id'              => (int)$db->lastInsertId(),
            'reference'       => $reference,
            'date_expiration' => $dateExpiration,
            'mensualite'      => (float)$formule['mensualite'],
            'couverture_pct'  => (int)$formule['couverture_pct'],
        ], 'Contrat CSA créé.', 201);
    }

    // ----------------------------------------------------------
    // PUT /contrats/{id} — modifier notes / dates / formule
    // ----------------------------------------------------------
    public static function update(int $id): void {
        $user = AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        $db   = Database::connect();

        $contrat = self::trouverOu404($db, 'contrats_csa', $id, 'Contrat');
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, $id));
        if ($contrat['statut'] === 'résilié') {
            self::erreur(409, 'Contrat résilié : modification impossible.');
        }

        $set    = [];
        $params = [];

        foreach (['date_debut', 'date_expiration', 'notes', 'statut'] as $c) {
            if (array_key_exists($c, $data)) {
                if ($c === 'statut' && !in_array($data[$c], ['actif', 'expiré', 'suspendu'])) {
                    self::erreur(400, 'Statut invalide (actif, expiré, suspendu). Pour résilier : POST /contrats/{id}/resilier.');
                }
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }

        // Changement de formule → recalcul mensualité + couverture
        if (!empty($data['formule_id']) || !empty($data['formule'])) {
            if (!empty($data['formule_id'])) {
                $stmt = $db->prepare('SELECT * FROM formules_csa WHERE id = ?');
                $stmt->execute([(int)$data['formule_id']]);
            } else {
                $stmt = $db->prepare('SELECT * FROM formules_csa WHERE nom = ?');
                $stmt->execute([strtolower($data['formule'])]);
            }
            $formule = $stmt->fetch();
            if (!$formule) self::erreur(404, 'Formule introuvable.');
            $set[] = 'formule_id = ?';      $params[] = (int)$formule['id'];
            $set[] = 'mensualite = ?';      $params[] = $formule['mensualite'];
            $set[] = 'couverture_pct = ?';  $params[] = $formule['couverture_pct'];
        }

        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE contrats_csa SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Contrat mis à jour.');
    }

    // ----------------------------------------------------------
    // POST /contrats/{id}/resilier
    // ----------------------------------------------------------
    public static function resilier(int $id): void {
        $user = AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        $db   = Database::connect();

        $contrat = self::trouverOu404($db, 'contrats_csa', $id, 'Contrat');
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, $id));
        if ($contrat['statut'] === 'résilié') self::erreur(409, 'Contrat déjà résilié.');

        $motif = trim($data['motif'] ?? '');
        $note  = '[Résilié le ' . date('d/m/Y') . ($motif ? " — Motif : $motif" : '') . ']';

        $db->prepare(
            "UPDATE contrats_csa SET statut = 'résilié',
                    notes = CONCAT(COALESCE(notes, ''), '\n', ?)
             WHERE id = ?"
        )->execute([$note, $id]);

        self::succes([], 'Contrat résilié.');
    }

    // ----------------------------------------------------------
    // POST /contrats/{id}/renouveler
    // Crée un nouveau contrat dans la continuité de l'ancien.
    // ----------------------------------------------------------
    public static function renouveler(int $id): void {
        $user = AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        $db   = Database::connect();

        $ancien = self::trouverOu404($db, 'contrats_csa', $id, 'Contrat');
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, $id));

        $dureeMois = max(1, (int)($data['duree_mois'] ?? 12));
        // Le nouveau contrat démarre au lendemain de l'expiration (ou aujourd'hui si déjà expiré)
        $debut = max(
            strtotime($ancien['date_expiration'] . ' +1 day'),
            strtotime(date('Y-m-d'))
        );
        $dateDebut      = date('Y-m-d', $debut);
        $dateExpiration = date('Y-m-d', strtotime("$dateDebut +$dureeMois months -1 day"));

        // Formule éventuellement changée au renouvellement
        $formuleId      = (int)$ancien['formule_id'];
        $mensualite     = $ancien['mensualite'];
        $couverturePct  = $ancien['couverture_pct'];
        if (!empty($data['formule'])) {
            $stmt = $db->prepare('SELECT * FROM formules_csa WHERE nom = ? AND actif = 1');
            $stmt->execute([strtolower($data['formule'])]);
            $formule = $stmt->fetch();
            if (!$formule) self::erreur(404, 'Formule introuvable.');
            $formuleId     = (int)$formule['id'];
            $mensualite    = $formule['mensualite'];
            $couverturePct = $formule['couverture_pct'];
        }

        // Clôturer l'ancien
        if ($ancien['statut'] === 'actif') {
            $db->prepare("UPDATE contrats_csa SET statut = 'expiré' WHERE id = ?")->execute([$id]);
        }

        $reference = self::genererReference($db, 'contrats_csa', 'CSA');
        $db->prepare(
            'INSERT INTO contrats_csa (reference, client_id, vehicule_id, formule_id, date_debut,
                                       date_expiration, mensualite, couverture_pct, statut, commercial_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "actif", ?, ?)'
        )->execute([
            $reference,
            (int)$ancien['client_id'],
            (int)$ancien['vehicule_id'],
            $formuleId,
            $dateDebut,
            $dateExpiration,
            $mensualite,
            $couverturePct,
            $user['id'],
            'Renouvellement du contrat ' . $ancien['reference'],
        ]);

        self::succes([
            'id'              => (int)$db->lastInsertId(),
            'reference'       => $reference,
            'date_debut'      => $dateDebut,
            'date_expiration' => $dateExpiration,
        ], 'Contrat renouvelé.', 201);
    }

    // ----------------------------------------------------------
    // GET /contrats/{id}/couverture?montant=500000
    // Calcule la prise en charge CSA sur une réparation.
    // ----------------------------------------------------------
    public static function couverture(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $contrat = self::trouverOu404($db, 'contrats_csa', $id, 'Contrat');
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, $id));

        $montant = (float)($_GET['montant'] ?? 0);
        if ($montant <= 0) self::erreur(400, 'Paramètre montant requis (> 0).');

        $actif = $contrat['statut'] === 'actif'
              && strtotime($contrat['date_expiration']) >= strtotime(date('Y-m-d'));

        $pct        = $actif ? (int)$contrat['couverture_pct'] : 0;
        $prisEnCharge = round($montant * $pct / 100, 2);

        self::succes([
            'contrat'         => $contrat['reference'],
            'statut'          => $contrat['statut'],
            'contrat_valide'  => $actif,
            'couverture_pct'  => $pct,
            'montant_total'   => $montant,
            'pris_en_charge'  => $prisEnCharge,
            'reste_a_charge'  => round($montant - $prisEnCharge, 2),
        ]);
    }

    // ----------------------------------------------------------
    // GET /contrats/expirations?jours=30
    // Contrats actifs expirant dans N jours (alertes J-30/15/7/1).
    // ----------------------------------------------------------
    public static function expirations(): void {
        $user  = AuthMiddleware::equipeInterne();
        $db    = Database::connect();
        $jours = max(1, (int)($_GET['jours'] ?? 30));

        $garage       = self::garageDe($user);
        $filtreGarage = $garage !== null ? 'AND c.garage_id = ' . $garage : '';

        $stmt = $db->prepare(
            "SELECT ct.id, ct.reference, ct.date_expiration, ct.mensualite,
                    f.nom AS formule, c.id AS client_id, c.nom AS client_nom, c.telephone,
                    v.marque, v.modele,
                    DATEDIFF(ct.date_expiration, CURDATE()) AS jours_restants
             FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             JOIN clients c      ON c.id = ct.client_id
             JOIN vehicules v    ON v.id = ct.vehicule_id
             WHERE ct.statut = 'actif'
               AND ct.date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
               $filtreGarage
             ORDER BY ct.date_expiration ASC"
        );
        $stmt->execute([$jours]);

        self::succes(['expirations' => $stmt->fetchAll()]);
    }

    // ----------------------------------------------------------
    //  Helper : état des échéances d'un contrat
    //  Mensualités échues depuis date_debut vs total payé.
    // ----------------------------------------------------------
    public static function etatEcheances(array $contrat): array {
        $debut  = new DateTime($contrat['date_debut']);
        $fin    = min(new DateTime(date('Y-m-d')), new DateTime($contrat['date_expiration']));

        $moisEchus = 0;
        if ($fin >= $debut) {
            $diff = $debut->diff($fin);
            $moisEchus = $diff->y * 12 + $diff->m + 1; // la 1re mensualité est due dès le début
        }

        $mensualite = (float)$contrat['mensualite'];
        $attendu    = $moisEchus * $mensualite;
        $paye       = (float)($contrat['total_paye'] ?? 0);
        $retard     = max(0, $attendu - $paye);

        return [
            'mois_echus'        => $moisEchus,
            'montant_attendu'   => $attendu,
            'montant_paye'      => $paye,
            'retard'            => $retard,
            'mensualites_retard'=> $mensualite > 0 ? (int)ceil($retard / $mensualite) : 0,
            'a_jour'            => $retard <= 0,
        ];
    }
}

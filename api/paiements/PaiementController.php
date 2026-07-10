<?php
// ============================================================
//  SERENO SMS — PaiementController (Module 3)
//  Paiements des contrats CSA : Orange Money, MTN MoMo, espèces...
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class PaiementController extends BaseController {

    private const MOYENS  = ['orange_money', 'mtn_momo', 'carte', 'espèces', 'virement'];
    private const STATUTS = ['payé', 'en_attente', 'échoué'];

    // ----------------------------------------------------------
    // GET /paiements?contrat_id=&statut=&moyen=&mois=&annee=
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

        if (!empty($_GET['contrat_id'])) {
            $where[] = 'p.contrat_id = :cid';
            $params[':cid'] = (int)$_GET['contrat_id'];
        }
        if (!empty($_GET['statut'])) {
            $where[] = 'p.statut = :statut';
            $params[':statut'] = $_GET['statut'];
        }
        if (!empty($_GET['moyen'])) {
            $where[] = 'p.moyen = :moyen';
            $params[':moyen'] = $_GET['moyen'];
        }
        if (!empty($_GET['annee'])) {
            $where[] = 'YEAR(p.date_paiement) = :annee';
            $params[':annee'] = (int)$_GET['annee'];
        }
        if (!empty($_GET['mois'])) {
            $where[] = 'MONTH(p.date_paiement) = :mois';
            $params[':mois'] = (int)$_GET['mois'];
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $db->prepare(
            "SELECT COUNT(*), COALESCE(SUM(p.montant), 0)
             FROM paiements p
             JOIN contrats_csa ct ON ct.id = p.contrat_id
             JOIN clients c       ON c.id = ct.client_id
             $sqlWhere"
        );
        $total->execute($params);
        [$nbTotal, $sommeTotal] = $total->fetch(PDO::FETCH_NUM);

        $stmt = $db->prepare(
            "SELECT p.*, ct.reference AS contrat_reference, c.nom AS client_nom
             FROM paiements p
             JOIN contrats_csa ct ON ct.id = p.contrat_id
             JOIN clients c       ON c.id = ct.client_id
             $sqlWhere
             ORDER BY p.date_paiement DESC, p.id DESC
             LIMIT $limite OFFSET $offset"
        );
        $stmt->execute($params);

        self::succes([
            'paiements' => $stmt->fetchAll(),
            'total'     => (int)$nbTotal,
            'somme'     => (float)$sommeTotal,
            'page'      => $page,
            'pages'     => (int)ceil((int)$nbTotal / $limite),
        ]);
    }

    // ----------------------------------------------------------
    // GET /contrats/{id}/paiements — historique + échéances
    // ----------------------------------------------------------
    public static function parContrat(int $contratId): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();

        require_once __DIR__ . '/../contrats/ContratController.php';
        $contrat = self::trouverOu404($db, 'contrats_csa', $contratId, 'Contrat');
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, $contratId));

        $stmt = $db->prepare('SELECT * FROM paiements WHERE contrat_id = ? ORDER BY date_paiement DESC');
        $stmt->execute([$contratId]);
        $paiements = $stmt->fetchAll();

        $totalPaye = 0;
        foreach ($paiements as $p) {
            if ($p['statut'] === 'payé') $totalPaye += (float)$p['montant'];
        }
        $contrat['total_paye'] = $totalPaye;

        self::succes([
            'paiements' => $paiements,
            'echeances' => ContratController::etatEcheances($contrat),
        ]);
    }

    // ----------------------------------------------------------
    // POST /paiements
    // Body: { contrat_id, montant, moyen, date_paiement?, reference_paiement?, statut?, notes? }
    // ----------------------------------------------------------
    public static function store(): void {
        $user = AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        self::requis($data, ['contrat_id', 'montant', 'moyen']);

        if (!in_array($data['moyen'], self::MOYENS)) {
            self::erreur(400, 'Moyen invalide. Valeurs : ' . implode(', ', self::MOYENS));
        }
        $montant = (float)$data['montant'];
        if ($montant <= 0) self::erreur(400, 'Le montant doit être supérieur à 0.');

        $statut = $data['statut'] ?? 'payé';
        if (!in_array($statut, self::STATUTS)) {
            self::erreur(400, 'Statut invalide. Valeurs : ' . implode(', ', self::STATUTS));
        }

        $db = Database::connect();
        $contrat = self::trouverOu404($db, 'contrats_csa', (int)$data['contrat_id'], 'Contrat');
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, (int)$data['contrat_id']));
        if ($contrat['statut'] === 'résilié') {
            self::erreur(409, 'Contrat résilié : aucun paiement ne peut être enregistré.');
        }

        $datePaiement = $data['date_paiement'] ?? date('Y-m-d');
        if (!strtotime($datePaiement)) self::erreur(400, 'date_paiement invalide.');

        $stmt = $db->prepare(
            'INSERT INTO paiements (contrat_id, montant, date_paiement, moyen, reference_paiement, statut, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$data['contrat_id'],
            $montant,
            $datePaiement,
            $data['moyen'],
            $data['reference_paiement'] ?? null,
            $statut,
            $data['notes'] ?? null,
        ]);

        self::succes(['id' => (int)$db->lastInsertId()], 'Paiement enregistré.', 201);
    }

    // ----------------------------------------------------------
    // PUT /paiements/{id} — corriger statut / référence / notes
    // ----------------------------------------------------------
    public static function update(int $id): void {
        $user = AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        $db   = Database::connect();

        $paiement = self::trouverOu404($db, 'paiements', $id, 'Paiement');
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, (int)$paiement['contrat_id']));

        $set    = [];
        $params = [];
        foreach (['montant', 'date_paiement', 'moyen', 'reference_paiement', 'statut', 'notes'] as $c) {
            if (array_key_exists($c, $data)) {
                if ($c === 'moyen' && !in_array($data[$c], self::MOYENS)) {
                    self::erreur(400, 'Moyen invalide.');
                }
                if ($c === 'statut' && !in_array($data[$c], self::STATUTS)) {
                    self::erreur(400, 'Statut invalide.');
                }
                if ($c === 'montant' && (float)$data[$c] <= 0) {
                    self::erreur(400, 'Le montant doit être supérieur à 0.');
                }
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE paiements SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Paiement mis à jour.');
    }

    // ----------------------------------------------------------
    // DELETE /paiements/{id}  (admin — correction d'erreur de saisie)
    // ----------------------------------------------------------
    public static function destroy(int $id): void {
        $user = AuthMiddleware::adminSeulement();
        $db = Database::connect();
        $paiement = self::trouverOu404($db, 'paiements', $id, 'Paiement');
        self::verifierGarage(self::garageDe($user), self::garageDuContrat($db, (int)$paiement['contrat_id']));
        $db->prepare('DELETE FROM paiements WHERE id = ?')->execute([$id]);
        self::succes([], 'Paiement supprimé.');
    }
}

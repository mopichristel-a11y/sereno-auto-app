<?php
// ============================================================
//  SERENO SMS — ClientController (Module 2)
//  CRUD clients + recherche + fiche détaillée
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class ClientController extends BaseController {

    // ----------------------------------------------------------
    // GET /clients?recherche=&type=&page=&limite=
    // ----------------------------------------------------------
    public static function index(): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();

        [$page, $limite, $offset] = self::pagination();

        $where  = [];
        $params = [];

        if (!empty($_GET['recherche'])) {
            $where[]  = '(c.nom LIKE :q OR c.telephone LIKE :q OR c.email LIKE :q
                          OR EXISTS (SELECT 1 FROM vehicules v WHERE v.client_id = c.id
                                     AND (v.marque LIKE :q OR v.modele LIKE :q OR v.immatriculation LIKE :q OR v.vin LIKE :q)))';
            $params[':q'] = '%' . trim($_GET['recherche']) . '%';
        }
        if (!empty($_GET['type'])) {
            $where[] = 'c.type_client = :type';
            $params[':type'] = $_GET['type'];
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $db->prepare("SELECT COUNT(*) FROM clients c $sqlWhere");
        $total->execute($params);
        $nbTotal = (int)$total->fetchColumn();

        $stmt = $db->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM vehicules v WHERE v.client_id = c.id) AS nb_vehicules,
                    (SELECT GROUP_CONCAT(CONCAT(v.marque, ' ', v.modele) SEPARATOR ', ')
                     FROM vehicules v WHERE v.client_id = c.id) AS vehicules_resume,
                    (SELECT f.nom FROM contrats_csa ct
                     JOIN formules_csa f ON f.id = ct.formule_id
                     WHERE ct.client_id = c.id AND ct.statut = 'actif'
                     ORDER BY ct.date_expiration DESC LIMIT 1) AS formule_active,
                    (SELECT MIN(ct.date_expiration) FROM contrats_csa ct
                     WHERE ct.client_id = c.id AND ct.statut = 'actif') AS prochaine_expiration
             FROM clients c
             $sqlWhere
             ORDER BY c.created_at DESC
             LIMIT $limite OFFSET $offset"
        );
        $stmt->execute($params);

        self::succes([
            'clients' => $stmt->fetchAll(),
            'total'   => $nbTotal,
            'page'    => $page,
            'pages'   => (int)ceil($nbTotal / $limite),
        ]);
    }

    // ----------------------------------------------------------
    // GET /clients/{id} — fiche complète
    // ----------------------------------------------------------
    public static function show(int $id): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $client = self::trouverOu404($db, 'clients', $id, 'Client');

        $vehicules = $db->prepare('SELECT * FROM vehicules WHERE client_id = ? ORDER BY created_at DESC');
        $vehicules->execute([$id]);

        $contrats = $db->prepare(
            "SELECT ct.*, f.nom AS formule, v.marque, v.modele, v.immatriculation
             FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             JOIN vehicules v    ON v.id = ct.vehicule_id
             WHERE ct.client_id = ?
             ORDER BY ct.created_at DESC"
        );
        $contrats->execute([$id]);

        $rdv = $db->prepare(
            "SELECT r.*, v.marque, v.modele FROM rendez_vous r
             JOIN vehicules v ON v.id = r.vehicule_id
             WHERE r.client_id = ? AND r.date_rdv >= NOW() AND r.statut != 'annulé'
             ORDER BY r.date_rdv ASC LIMIT 5"
        );
        $rdv->execute([$id]);

        self::succes([
            'client'    => $client,
            'vehicules' => $vehicules->fetchAll(),
            'contrats'  => $contrats->fetchAll(),
            'rdv'       => $rdv->fetchAll(),
        ]);
    }

    // ----------------------------------------------------------
    // POST /clients
    // ----------------------------------------------------------
    public static function store(): void {
        $user = AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();

        self::requis($data, ['nom', 'telephone']);

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            self::erreur(400, 'Adresse email invalide.');
        }
        $type = $data['type_client'] ?? 'particulier';
        if (!in_array($type, ['particulier', 'entreprise'])) {
            self::erreur(400, 'type_client invalide (particulier ou entreprise).');
        }

        $db = Database::connect();

        // Anti-doublon simple sur le téléphone
        $test = $db->prepare('SELECT id, nom FROM clients WHERE telephone = ?');
        $test->execute([trim($data['telephone'])]);
        if ($doublon = $test->fetch()) {
            self::erreur(409, "Un client existe déjà avec ce téléphone : {$doublon['nom']} (id {$doublon['id']}).");
        }

        $stmt = $db->prepare(
            'INSERT INTO clients (nom, telephone, email, adresse, piece_identite, num_piece, profession, type_client, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim($data['nom']),
            trim($data['telephone']),
            $data['email'] ?? null,
            $data['adresse'] ?? null,
            $data['piece_identite'] ?? null,
            $data['num_piece'] ?? null,
            $data['profession'] ?? null,
            $type,
            $user['id'],
        ]);

        self::succes(['id' => (int)$db->lastInsertId()], 'Client créé avec succès.', 201);
    }

    // ----------------------------------------------------------
    // PUT /clients/{id}
    // ----------------------------------------------------------
    public static function update(int $id): void {
        AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        $db   = Database::connect();

        self::trouverOu404($db, 'clients', $id, 'Client');

        $champs  = ['nom', 'telephone', 'email', 'adresse', 'piece_identite', 'num_piece', 'profession', 'type_client'];
        $set     = [];
        $params  = [];
        foreach ($champs as $c) {
            if (array_key_exists($c, $data)) {
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        if (isset($data['email']) && $data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            self::erreur(400, 'Adresse email invalide.');
        }

        $params[] = $id;
        $db->prepare('UPDATE clients SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        self::succes([], 'Client mis à jour.');
    }

    // ----------------------------------------------------------
    // POST /clients/{id}/creer-acces
    // Crée un compte utilisateur (rôle client) lié à la fiche.
    // Body: { mot_de_passe? } — généré si absent.
    // ----------------------------------------------------------
    public static function creerAcces(int $id): void {
        AuthMiddleware::adminOuCommercial();
        $data = self::bodyJson();
        $db   = Database::connect();

        $client = self::trouverOu404($db, 'clients', $id, 'Client');

        if ($client['utilisateur_id']) {
            self::erreur(409, 'Ce client possède déjà un accès à l\'espace client.');
        }
        if (empty($client['email'])) {
            self::erreur(400, 'Ajoutez d\'abord une adresse email à la fiche client.');
        }

        $test = $db->prepare('SELECT id FROM utilisateurs WHERE email = ?');
        $test->execute([strtolower($client['email'])]);
        if ($test->fetch()) {
            self::erreur(409, 'Un compte existe déjà avec cet email.');
        }

        $motDePasse = $data['mot_de_passe'] ?? null;
        if ($motDePasse !== null && strlen($motDePasse) < 8) {
            self::erreur(400, 'Mot de passe : 8 caractères minimum.');
        }
        // Mot de passe temporaire lisible si non fourni (à changer à la 1re connexion)
        $genere = $motDePasse === null;
        if ($genere) {
            $motDePasse = 'Sereno@' . random_int(100000, 999999);
        }

        // Découper le nom : dernier mot = prénom présumé, le reste = nom
        $mots   = preg_split('/\s+/', trim($client['nom']));
        $prenom = count($mots) > 1 ? array_pop($mots) : $client['nom'];
        $nom    = implode(' ', $mots) ?: $client['nom'];

        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, telephone, role)
                 VALUES (?, ?, ?, ?, ?, "client")'
            )->execute([
                $nom, $prenom,
                strtolower(trim($client['email'])),
                password_hash($motDePasse, PASSWORD_BCRYPT, ['cost' => 12]),
                $client['telephone'],
            ]);
            $utilisateurId = (int)$db->lastInsertId();

            $db->prepare('UPDATE clients SET utilisateur_id = ? WHERE id = ?')
               ->execute([$utilisateurId, $id]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            self::erreur(500, 'Création du compte impossible : ' . $e->getMessage());
        }

        self::succes([
            'utilisateur_id'          => $utilisateurId,
            'email'                   => strtolower($client['email']),
            // Retourné une seule fois pour transmission au client
            'mot_de_passe_temporaire' => $genere ? $motDePasse : null,
        ], 'Accès espace client créé. Transmettez les identifiants au client.', 201);
    }

    // ----------------------------------------------------------
    // DELETE /clients/{id}  (admin)
    // ----------------------------------------------------------
    public static function destroy(int $id): void {
        AuthMiddleware::adminSeulement();
        $db = Database::connect();

        self::trouverOu404($db, 'clients', $id, 'Client');

        // Bloquer si contrats actifs
        $actifs = $db->prepare("SELECT COUNT(*) FROM contrats_csa WHERE client_id = ? AND statut = 'actif'");
        $actifs->execute([$id]);
        if ($actifs->fetchColumn() > 0) {
            self::erreur(409, 'Impossible de supprimer : ce client a des contrats CSA actifs. Résiliez-les d\'abord.');
        }

        $db->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        self::succes([], 'Client supprimé.');
    }
}

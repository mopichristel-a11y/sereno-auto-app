<?php
// ============================================================
//  SERENO SMS — SaasController (console plateforme)
//  Réservé au super-admin (garage_id NULL) :
//  gestion des garages abonnés, abonnements, stats plateforme.
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class SaasController extends BaseController {

    // ----------------------------------------------------------
    // GET /saas/plans — les 3 plans d'abonnement
    // ----------------------------------------------------------
    public static function plans(): void {
        AuthMiddleware::superAdminSeulement();
        $plans = [];
        foreach (self::PLANS_SAAS as $nom => $p) {
            $plans[] = [
                'plan'            => $nom,
                'mensualite'      => $p['mensualite'],
                'quota_vehicules' => $p['quota_vehicules'],
            ];
        }
        self::succes(['plans' => $plans]);
    }

    // ----------------------------------------------------------
    // GET /saas/garages — tous les garages abonnés + compteurs
    // ----------------------------------------------------------
    public static function garages(): void {
        AuthMiddleware::superAdminSeulement();
        $db = Database::connect();

        $garages = $db->query(
            "SELECT g.*,
                    DATEDIFF(g.abonnement_fin, CURDATE()) AS jours_restants,
                    (SELECT COUNT(*) FROM clients cl WHERE cl.garage_id = g.id) AS nb_clients,
                    (SELECT COUNT(*) FROM vehicules v JOIN clients cl ON cl.id = v.client_id
                     WHERE cl.garage_id = g.id) AS nb_vehicules,
                    (SELECT COUNT(*) FROM contrats_csa ct JOIN clients cl ON cl.id = ct.client_id
                     WHERE cl.garage_id = g.id AND ct.statut = 'actif') AS nb_contrats,
                    (SELECT COUNT(*) FROM utilisateurs u WHERE u.garage_id = g.id AND u.role != 'client') AS nb_equipe
             FROM garages_sms g
             ORDER BY g.created_at ASC"
        )->fetchAll();

        foreach ($garages as &$g) {
            $g['quota_vehicules'] = self::PLANS_SAAS[$g['plan']]['quota_vehicules'];
            $g['mensualite_saas'] = self::PLANS_SAAS[$g['plan']]['mensualite'];
        }

        self::succes(['garages' => $garages]);
    }

    // ----------------------------------------------------------
    // POST /saas/garages — créer un garage abonné + son admin
    // Body: { nom, code?, ville?, pays?, telephone?, email?,
    //         plan?=starter, duree_mois?=1,
    //         admin: { nom, prenom, email, mot_de_passe? } }
    // ----------------------------------------------------------
    public static function creerGarage(): void {
        AuthMiddleware::superAdminSeulement();
        $data = self::bodyJson();
        self::requis($data, ['nom', 'admin']);

        $plan = $data['plan'] ?? 'starter';
        if (!isset(self::PLANS_SAAS[$plan])) {
            self::erreur(400, 'Plan invalide : starter, pro ou enterprise.');
        }

        $admin = $data['admin'];
        if (empty($admin['nom']) || empty($admin['prenom']) || empty($admin['email'])) {
            self::erreur(400, 'admin.nom, admin.prenom et admin.email sont requis.');
        }
        if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            self::erreur(400, 'Email administrateur invalide.');
        }

        $db = Database::connect();

        // Email admin unique
        $test = $db->prepare('SELECT id FROM utilisateurs WHERE email = ?');
        $test->execute([strtolower(trim($admin['email']))]);
        if ($test->fetch()) self::erreur(409, 'Un compte existe déjà avec cet email.');

        // Code garage unique (généré si absent)
        $code = strtoupper(trim($data['code'] ?? ''));
        if (!$code) {
            $base = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $data['nom']), 0, 6)) ?: 'GARAGE';
            $code = $base;
            $n = 1;
            while (true) {
                $t = $db->prepare('SELECT id FROM garages_sms WHERE code = ?');
                $t->execute([$code]);
                if (!$t->fetch()) break;
                $code = $base . '-' . (++$n);
            }
        } else {
            $t = $db->prepare('SELECT id FROM garages_sms WHERE code = ?');
            $t->execute([$code]);
            if ($t->fetch()) self::erreur(409, "Le code garage $code est déjà utilisé.");
        }

        $dureeMois     = max(1, (int)($data['duree_mois'] ?? 1));
        $abonnementFin = date('Y-m-d', strtotime("+$dureeMois months"));

        $motDePasse = $admin['mot_de_passe'] ?? ('Sereno@' . random_int(100000, 999999));
        if (strlen($motDePasse) < 8) self::erreur(400, 'Mot de passe : 8 caractères minimum.');
        $genere = empty($admin['mot_de_passe']);

        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO garages_sms (nom, code, ville, pays, telephone, email, plan, abonnement_fin)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                trim($data['nom']), $code,
                $data['ville'] ?? null, $data['pays'] ?? 'Cameroun',
                $data['telephone'] ?? null, $data['email'] ?? null,
                $plan, $abonnementFin,
            ]);
            $garageId = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, telephone, role, garage_id)
                 VALUES (?, ?, ?, ?, ?, "admin", ?)'
            )->execute([
                trim($admin['nom']), trim($admin['prenom']),
                strtolower(trim($admin['email'])),
                password_hash($motDePasse, PASSWORD_BCRYPT, ['cost' => 12]),
                $admin['telephone'] ?? ($data['telephone'] ?? null),
                $garageId,
            ]);
            $adminId = (int)$db->lastInsertId();

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            self::erreur(500, 'Création du garage impossible : ' . $e->getMessage());
        }

        self::succes([
            'garage_id'      => $garageId,
            'code'           => $code,
            'plan'           => $plan,
            'abonnement_fin' => $abonnementFin,
            'admin'          => [
                'id'    => $adminId,
                'email' => strtolower(trim($admin['email'])),
                // Retourné une seule fois pour transmission au gérant
                'mot_de_passe_temporaire' => $genere ? $motDePasse : null,
            ],
        ], 'Garage abonné créé. Transmettez les identifiants à son administrateur.', 201);
    }

    // ----------------------------------------------------------
    // PUT /saas/garages/{id} — modifier infos / plan / statut
    // ----------------------------------------------------------
    public static function modifierGarage(int $id): void {
        AuthMiddleware::superAdminSeulement();
        $data = self::bodyJson();
        $db   = Database::connect();

        self::trouverOu404($db, 'garages_sms', $id, 'Garage');

        $set    = [];
        $params = [];
        foreach (['nom', 'ville', 'pays', 'telephone', 'email', 'notes', 'actif', 'abonnement_fin'] as $c) {
            if (array_key_exists($c, $data)) {
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        if (array_key_exists('plan', $data)) {
            if (!isset(self::PLANS_SAAS[$data['plan']])) {
                self::erreur(400, 'Plan invalide : starter, pro ou enterprise.');
            }
            $set[]    = 'plan = ?';
            $params[] = $data['plan'];
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE garages_sms SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Garage mis à jour.');
    }

    // ----------------------------------------------------------
    // POST /saas/garages/{id}/renouveler
    // Body: { duree_mois?=1 } — prolonge l'abonnement.
    // ----------------------------------------------------------
    public static function renouveler(int $id): void {
        AuthMiddleware::superAdminSeulement();
        $data = self::bodyJson();
        $db   = Database::connect();

        $garage = self::trouverOu404($db, 'garages_sms', $id, 'Garage');
        $dureeMois = max(1, (int)($data['duree_mois'] ?? 1));

        // Repartir de l'expiration future ou d'aujourd'hui si déjà expiré
        $base = max(
            strtotime($garage['abonnement_fin'] ?? 'today'),
            strtotime(date('Y-m-d'))
        );
        $nouvelleFin = date('Y-m-d', strtotime("+$dureeMois months", $base));

        $db->prepare('UPDATE garages_sms SET abonnement_fin = ?, actif = 1 WHERE id = ?')
           ->execute([$nouvelleFin, $id]);

        self::succes(['abonnement_fin' => $nouvelleFin],
            "Abonnement prolongé de $dureeMois mois (jusqu'au " . date('d/m/Y', strtotime($nouvelleFin)) . ').');
    }

    // ----------------------------------------------------------
    // GET /saas/stats — vue plateforme
    // ----------------------------------------------------------
    public static function stats(): void {
        AuthMiddleware::superAdminSeulement();
        $db = Database::connect();

        $garages = $db->query('SELECT COUNT(*) FROM garages_sms WHERE actif = 1')->fetchColumn();
        $parPlan = $db->query(
            'SELECT plan, COUNT(*) AS nb FROM garages_sms WHERE actif = 1 GROUP BY plan'
        )->fetchAll();

        // Revenu SaaS mensuel récurrent (MRR) théorique
        $mrr = 0;
        foreach ($parPlan as $p) {
            $mrr += self::PLANS_SAAS[$p['plan']]['mensualite'] * (int)$p['nb'];
        }

        $expirentBientot = $db->query(
            'SELECT COUNT(*) FROM garages_sms WHERE actif = 1
             AND abonnement_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'
        )->fetchColumn();

        self::succes([
            'garages_actifs'    => (int)$garages,
            'par_plan'          => $parPlan,
            'mrr'               => $mrr,
            'expirent_30j'      => (int)$expirentBientot,
            'clients_total'     => (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn(),
            'vehicules_total'   => (int)$db->query('SELECT COUNT(*) FROM vehicules')->fetchColumn(),
            'contrats_actifs'   => (int)$db->query(
                "SELECT COUNT(*) FROM contrats_csa WHERE statut = 'actif'")->fetchColumn(),
        ]);
    }
}

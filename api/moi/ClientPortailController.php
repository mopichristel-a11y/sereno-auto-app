<?php
// ============================================================
//  SERENO SMS — ClientPortailController (Espace client)
//  Toutes les routes /moi/* : le client connecté ne voit que
//  SES données (résolution via clients.utilisateur_id).
// ============================================================

require_once __DIR__ . '/../BaseController.php';
require_once __DIR__ . '/../vehicules/VehiculeController.php';
require_once __DIR__ . '/../contrats/ContratController.php';

class ClientPortailController extends BaseController {

    /** Résout la fiche client liée à l'utilisateur connecté (rôle client). */
    private static function clientCourant(?PDO $db = null): array {
        // Authentifier AVANT toute connexion à la base
        $user = AuthMiddleware::autoriser('client');
        $db ??= Database::connect();
        $stmt = $db->prepare('SELECT * FROM clients WHERE utilisateur_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $client = $stmt->fetch();
        if (!$client) {
            self::erreur(404, 'Aucune fiche client liée à votre compte. Contactez SERENO AUTO.');
        }
        return $client;
    }

    // ----------------------------------------------------------
    // GET /moi/tableau-bord
    // ----------------------------------------------------------
    public static function tableauBord(): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();

        $vehicules = $db->prepare('SELECT * FROM vehicules WHERE client_id = ? ORDER BY created_at DESC');
        $vehicules->execute([$client['id']]);
        $listeVehicules = $vehicules->fetchAll();

        // Rappels urgents sur tous les véhicules du client
        $rappelsUrgents = [];
        foreach ($listeVehicules as $v) {
            foreach (VehiculeController::calculerRappels($db, (int)$v['id'], (int)$v['kilometrage']) as $r) {
                if (in_array($r['urgence'], ['depasse', 'urgent', 'bientot'])) {
                    $rappelsUrgents[] = array_merge($r, [
                        'vehicule_id' => (int)$v['id'],
                        'vehicule'    => $v['marque'] . ' ' . $v['modele'],
                    ]);
                }
            }
        }

        $contrats = $db->prepare(
            "SELECT ct.*, f.nom AS formule, v.marque, v.modele,
                    DATEDIFF(ct.date_expiration, CURDATE()) AS jours_avant_expiration,
                    (SELECT COALESCE(SUM(p.montant), 0) FROM paiements p
                     WHERE p.contrat_id = ct.id AND p.statut = 'payé') AS total_paye
             FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             JOIN vehicules v    ON v.id = ct.vehicule_id
             WHERE ct.client_id = ? AND ct.statut = 'actif'"
        );
        $contrats->execute([$client['id']]);
        $listeContrats = $contrats->fetchAll();
        foreach ($listeContrats as &$ct) {
            $ct['echeances'] = ContratController::etatEcheances($ct);
        }

        $devisAttente = $db->prepare(
            "SELECT COUNT(*) FROM devis WHERE client_id = ? AND statut = 'envoyé'"
        );
        $devisAttente->execute([$client['id']]);

        $notifsNonLues = $db->prepare('SELECT COUNT(*) FROM notifications WHERE client_id = ? AND lu = 0');
        $notifsNonLues->execute([$client['id']]);

        $prochainsRdv = $db->prepare(
            "SELECT r.*, v.marque, v.modele FROM rendez_vous r
             JOIN vehicules v ON v.id = r.vehicule_id
             WHERE r.client_id = ? AND r.date_rdv >= NOW() AND r.statut != 'annulé'
             ORDER BY r.date_rdv ASC LIMIT 3"
        );
        $prochainsRdv->execute([$client['id']]);

        self::succes([
            'client'          => ['id' => (int)$client['id'], 'nom' => $client['nom']],
            'vehicules'       => $listeVehicules,
            'contrats'        => $listeContrats,
            'rappels'         => $rappelsUrgents,
            'devis_en_attente'=> (int)$devisAttente->fetchColumn(),
            'notifications_non_lues' => (int)$notifsNonLues->fetchColumn(),
            'prochains_rdv'   => $prochainsRdv->fetchAll(),
        ]);
    }

    // ----------------------------------------------------------
    // GET /moi/vehicules — véhicules + rappels + carnet
    // ----------------------------------------------------------
    public static function vehicules(): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();

        $stmt = $db->prepare('SELECT * FROM vehicules WHERE client_id = ? ORDER BY created_at DESC');
        $stmt->execute([$client['id']]);
        $vehicules = $stmt->fetchAll();

        foreach ($vehicules as &$v) {
            $v['rappels'] = VehiculeController::calculerRappels($db, (int)$v['id'], (int)$v['kilometrage']);

            $carnet = $db->prepare(
                'SELECT type_entretien, date_intervention, kilometrage, prochain_km, prochaine_date, notes
                 FROM carnet_entretien WHERE vehicule_id = ?
                 ORDER BY date_intervention DESC LIMIT 10'
            );
            $carnet->execute([(int)$v['id']]);
            $v['carnet'] = $carnet->fetchAll();
            foreach ($v['carnet'] as &$c) {
                $c['libelle'] = VehiculeController::LIBELLES[$c['type_entretien']] ?? $c['type_entretien'];
            }
        }

        self::succes(['vehicules' => $vehicules]);
    }

    // ----------------------------------------------------------
    // GET /moi/contrats — contrats + paiements + échéances
    // ----------------------------------------------------------
    public static function contrats(): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();

        $stmt = $db->prepare(
            "SELECT ct.*, f.nom AS formule, f.garanties, v.marque, v.modele, v.immatriculation,
                    DATEDIFF(ct.date_expiration, CURDATE()) AS jours_avant_expiration,
                    (SELECT COALESCE(SUM(p.montant), 0) FROM paiements p
                     WHERE p.contrat_id = ct.id AND p.statut = 'payé') AS total_paye
             FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             JOIN vehicules v    ON v.id = ct.vehicule_id
             WHERE ct.client_id = ?
             ORDER BY ct.created_at DESC"
        );
        $stmt->execute([$client['id']]);
        $contrats = $stmt->fetchAll();

        foreach ($contrats as &$ct) {
            $ct['garanties'] = self::decoderJson($ct['garanties']);
            $ct['echeances'] = ContratController::etatEcheances($ct);
            $paiements = $db->prepare(
                'SELECT montant, date_paiement, moyen, statut, reference_paiement
                 FROM paiements WHERE contrat_id = ? ORDER BY date_paiement DESC LIMIT 12'
            );
            $paiements->execute([(int)$ct['id']]);
            $ct['paiements'] = $paiements->fetchAll();
        }

        self::succes(['contrats' => $contrats]);
    }

    // ----------------------------------------------------------
    // GET /moi/devis — devis du client (avec couverture CSA)
    // ----------------------------------------------------------
    public static function devis(): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();

        $stmt = $db->prepare(
            "SELECT d.*, v.marque, v.modele,
                    dg.reference AS diagnostic_reference
             FROM devis d
             JOIN vehicules v ON v.id = d.vehicule_id
             LEFT JOIN diagnostics dg ON dg.id = d.diagnostic_id
             WHERE d.client_id = ? AND d.statut != 'brouillon'
             ORDER BY d.created_at DESC"
        );
        $stmt->execute([$client['id']]);
        $devis = $stmt->fetchAll();

        foreach ($devis as &$d) {
            $d['lignes'] = self::decoderJson($d['lignes']);
            // Couverture CSA active sur le véhicule
            $csa = $db->prepare(
                "SELECT ct.couverture_pct, f.nom AS formule
                 FROM contrats_csa ct JOIN formules_csa f ON f.id = ct.formule_id
                 WHERE ct.vehicule_id = ? AND ct.statut = 'actif'
                   AND ct.date_expiration >= CURDATE() LIMIT 1"
            );
            $csa->execute([(int)$d['vehicule_id']]);
            if ($contrat = $csa->fetch()) {
                $pct  = (int)$contrat['couverture_pct'];
                $pris = round((float)$d['total'] * $pct / 100, 2);
                $d['couverture'] = [
                    'formule' => $contrat['formule'], 'couverture_pct' => $pct,
                    'pris_en_charge' => $pris,
                    'reste_a_charge' => round((float)$d['total'] - $pris, 2),
                ];
            } else {
                $d['couverture'] = null;
            }
        }

        self::succes(['devis' => $devis]);
    }

    // ----------------------------------------------------------
    // POST /moi/devis/{id}/reponse — Body: { reponse: accepter|refuser }
    // ----------------------------------------------------------
    public static function repondreDevis(int $id): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();
        $data   = self::bodyJson();
        self::requis($data, ['reponse']);

        if (!in_array($data['reponse'], ['accepter', 'refuser'])) {
            self::erreur(400, 'reponse doit valoir accepter ou refuser.');
        }

        $stmt = $db->prepare('SELECT * FROM devis WHERE id = ? AND client_id = ?');
        $stmt->execute([$id, $client['id']]);
        $devis = $stmt->fetch();
        if (!$devis) self::erreur(404, 'Devis introuvable.');
        if ($devis['statut'] !== 'envoyé') {
            self::erreur(409, "Ce devis n'attend plus de réponse (statut : {$devis['statut']}).");
        }

        $nouveauStatut = $data['reponse'] === 'accepter' ? 'accepté' : 'refusé';
        $db->prepare('UPDATE devis SET statut = ? WHERE id = ?')->execute([$nouveauStatut, $id]);

        $interventionId = null;
        if ($nouveauStatut === 'accepté') {
            $test = $db->prepare('SELECT id FROM interventions WHERE devis_id = ?');
            $test->execute([$id]);
            if (!$test->fetch()) {
                $refInt = self::genererReference($db, 'interventions', 'INT');
                $db->prepare(
                    'INSERT INTO interventions (reference, devis_id, vehicule_id, statut)
                     VALUES (?, ?, ?, "planifié")'
                )->execute([$refInt, $id, (int)$devis['vehicule_id']]);
                $interventionId = (int)$db->lastInsertId();
            }
        }

        self::succes(
            array_filter(['intervention_id' => $interventionId]),
            $nouveauStatut === 'accepté'
                ? 'Devis accepté — SERENO AUTO planifie votre intervention.'
                : 'Devis refusé. Votre conseiller reste disponible.'
        );
    }

    // ----------------------------------------------------------
    // GET /moi/notifications
    // ----------------------------------------------------------
    public static function notifications(): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();

        $stmt = $db->prepare(
            'SELECT id, type, titre, message, lu, created_at
             FROM notifications WHERE client_id = ?
             ORDER BY created_at DESC LIMIT 30'
        );
        $stmt->execute([$client['id']]);

        self::succes(['notifications' => $stmt->fetchAll()]);
    }

    // ----------------------------------------------------------
    // POST /moi/notifications/{id}/lu
    // ----------------------------------------------------------
    public static function marquerLu(int $id): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();
        $db->prepare('UPDATE notifications SET lu = 1 WHERE id = ? AND client_id = ?')
           ->execute([$id, $client['id']]);
        self::succes([], 'Notification lue.');
    }

    // ----------------------------------------------------------
    // GET /moi/rdv
    // ----------------------------------------------------------
    public static function rdv(): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();

        $stmt = $db->prepare(
            'SELECT r.*, v.marque, v.modele FROM rendez_vous r
             JOIN vehicules v ON v.id = r.vehicule_id
             WHERE r.client_id = ? ORDER BY r.date_rdv DESC LIMIT 20'
        );
        $stmt->execute([$client['id']]);
        self::succes(['rdv' => $stmt->fetchAll()]);
    }

    // ----------------------------------------------------------
    // POST /moi/rdv — demande de rendez-vous (statut en_attente)
    // Body: { vehicule_id, date_rdv, motif? }
    // ----------------------------------------------------------
    public static function demanderRdv(): void {
        $client = self::clientCourant(); // authentifie puis connecte
        $db     = Database::connect();
        $data   = self::bodyJson();
        self::requis($data, ['vehicule_id', 'date_rdv']);

        if (!strtotime($data['date_rdv'])) self::erreur(400, 'date_rdv invalide.');
        if (strtotime($data['date_rdv']) < time()) self::erreur(400, 'La date doit être dans le futur.');

        // Le véhicule doit appartenir au client
        $test = $db->prepare('SELECT id FROM vehicules WHERE id = ? AND client_id = ?');
        $test->execute([(int)$data['vehicule_id'], $client['id']]);
        if (!$test->fetch()) self::erreur(404, 'Véhicule introuvable dans votre garage.');

        $db->prepare(
            'INSERT INTO rendez_vous (client_id, vehicule_id, date_rdv, motif, statut, notes)
             VALUES (?, ?, ?, ?, "en_attente", "Demande via l\'espace client")'
        )->execute([
            $client['id'],
            (int)$data['vehicule_id'],
            date('Y-m-d H:i:s', strtotime($data['date_rdv'])),
            $data['motif'] ?? 'Demande de rendez-vous',
        ]);

        self::succes(['id' => (int)$db->lastInsertId()],
            'Demande envoyée. SERENO AUTO vous confirmera le créneau.', 201);
    }
}

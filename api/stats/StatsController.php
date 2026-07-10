<?php
// ============================================================
//  SERENO SMS — StatsController (Module 7)
//  KPIs dashboard, CA mensuel, performance commerciaux,
//  export PDF du rapport mensuel.
//  Multi-tenant : toutes les requêtes sont cloisonnées par garage
//  (le super-admin voit la plateforme entière).
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class StatsController extends BaseController {

    /**
     * Fragments SQL de cloisonnement ($g = garage_id int, sûr à interpoler).
     * Retourne [jointureClients, condition] selon la table de départ.
     */
    private static function filtres(?int $garage): array {
        if ($garage === null) return ['', '', ''];
        return [
            "AND c.garage_id = $garage",                                  // requêtes avec alias c
            "JOIN clients c ON c.id = v.client_id AND c.garage_id = $garage", // départ vehicules v
            "AND garage_id = $garage",                                    // colonnes directes
        ];
    }

    // ----------------------------------------------------------
    // GET /stats/dashboard — tous les KPIs de la page d'accueil
    // ----------------------------------------------------------
    public static function dashboard(): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();
        $g  = self::garageDe($user);

        $moisCourant = date('Y-m');
        $condClients  = $g !== null ? "AND garage_id = $g" : '';
        $joinClients  = $g !== null ? "JOIN clients c ON c.id = v.client_id AND c.garage_id = $g"
                                    : 'JOIN clients c ON c.id = v.client_id';
        $joinCt       = $g !== null ? "JOIN clients c ON c.id = ct.client_id AND c.garage_id = $g"
                                    : 'JOIN clients c ON c.id = ct.client_id';
        $joinDevis    = $g !== null ? "JOIN clients c ON c.id = d.client_id AND c.garage_id = $g"
                                    : 'JOIN clients c ON c.id = d.client_id';

        $clientsActifs   = (int)$db->query("SELECT COUNT(*) FROM clients WHERE 1 $condClients")->fetchColumn();
        $clientsNouveaux = (int)$db->query(
            "SELECT COUNT(*) FROM clients WHERE DATE_FORMAT(created_at, '%Y-%m') = '$moisCourant' $condClients"
        )->fetchColumn();

        $vehicules = (int)$db->query("SELECT COUNT(*) FROM vehicules v $joinClients")->fetchColumn();
        $vehiculesNouveaux = (int)$db->query(
            "SELECT COUNT(*) FROM vehicules v $joinClients
             WHERE DATE_FORMAT(v.created_at, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        $contratsActifs = (int)$db->query(
            "SELECT COUNT(*) FROM contrats_csa ct $joinCt WHERE ct.statut = 'actif'"
        )->fetchColumn();
        $contratsExpirentBientot = (int)$db->query(
            "SELECT COUNT(*) FROM contrats_csa ct $joinCt WHERE ct.statut = 'actif'
             AND ct.date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $interventionsEnCours = (int)$db->query(
            "SELECT COUNT(*) FROM interventions i JOIN vehicules v ON v.id = i.vehicule_id
             $joinClients WHERE i.statut = 'en_cours'"
        )->fetchColumn();
        $interventionsPlanifiees = (int)$db->query(
            "SELECT COUNT(*) FROM interventions i JOIN vehicules v ON v.id = i.vehicule_id
             $joinClients WHERE i.statut = 'planifié'"
        )->fetchColumn();

        $rdvAujourdhui = (int)$db->query(
            "SELECT COUNT(*) FROM rendez_vous r JOIN clients c ON c.id = r.client_id
             WHERE DATE(r.date_rdv) = CURDATE() AND r.statut != 'annulé'"
            . ($g !== null ? " AND c.garage_id = $g" : '')
        )->fetchColumn();
        $rdvConfirmes = (int)$db->query(
            "SELECT COUNT(*) FROM rendez_vous r JOIN clients c ON c.id = r.client_id
             WHERE DATE(r.date_rdv) = CURDATE() AND r.statut = 'confirmé'"
            . ($g !== null ? " AND c.garage_id = $g" : '')
        )->fetchColumn();

        $diagnosticsMois = (int)$db->query(
            "SELECT COUNT(*) FROM diagnostics dg JOIN vehicules v ON v.id = dg.vehicule_id
             $joinClients WHERE DATE_FORMAT(dg.date_diagnostic, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        $notificationsNonLues = (int)$db->query(
            'SELECT COUNT(*) FROM notifications WHERE lu = 0' . ($g !== null ? " AND garage_id = $g" : '')
        )->fetchColumn();

        $caMois = (float)$db->query(
            "SELECT COALESCE(SUM(p.montant), 0) FROM paiements p
             JOIN contrats_csa ct ON ct.id = p.contrat_id $joinCt
             WHERE p.statut = 'payé' AND DATE_FORMAT(p.date_paiement, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        $caReparations = (float)$db->query(
            "SELECT COALESCE(SUM(d.total), 0) FROM devis d $joinDevis
             WHERE d.statut = 'accepté' AND DATE_FORMAT(d.updated_at, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        $paiementsAttente = $db->query(
            "SELECT COUNT(*) AS nb, COALESCE(SUM(p.montant), 0) AS somme
             FROM paiements p JOIN contrats_csa ct ON ct.id = p.contrat_id $joinCt
             WHERE p.statut = 'en_attente'"
        )->fetch();

        $mensualitesAttendues = (float)$db->query(
            "SELECT COALESCE(SUM(ct.mensualite), 0) FROM contrats_csa ct $joinCt WHERE ct.statut = 'actif'"
        )->fetchColumn();

        $repartition = $db->query(
            "SELECT f.nom, COUNT(*) AS nb, SUM(ct.mensualite) AS mensualites
             FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             $joinCt
             WHERE ct.statut = 'actif'
             GROUP BY f.nom ORDER BY f.mensualite DESC"
        )->fetchAll();

        self::succes([
            'clients'       => ['total' => $clientsActifs, 'nouveaux_mois' => $clientsNouveaux],
            'vehicules'     => ['total' => $vehicules, 'nouveaux_mois' => $vehiculesNouveaux],
            'contrats'      => [
                'actifs'            => $contratsActifs,
                'expirent_bientot'  => $contratsExpirentBientot,
                'repartition'       => $repartition,
                'mensualites_attendues' => $mensualitesAttendues,
            ],
            'interventions' => ['en_cours' => $interventionsEnCours, 'planifiees' => $interventionsPlanifiees],
            'rdv'           => ['aujourdhui' => $rdvAujourdhui, 'confirmes' => $rdvConfirmes],
            'diagnostics'   => ['mois' => $diagnosticsMois],
            'notifications' => ['non_lues' => $notificationsNonLues],
            'finances'      => [
                'ca_mois'            => $caMois + $caReparations,
                'ca_csa'             => $caMois,
                'ca_reparations'     => $caReparations,
                'paiements_attente'  => [
                    'nb'    => (int)$paiementsAttente['nb'],
                    'somme' => (float)$paiementsAttente['somme'],
                ],
            ],
            'garage' => $g !== null
                ? ['id' => $g, 'nom' => $user['garage_nom'], 'plan' => $user['garage_plan']]
                : null,
        ]);
    }

    // ----------------------------------------------------------
    // GET /stats/ca-mensuel?annee=2026
    // ----------------------------------------------------------
    public static function caMensuel(): void {
        $user  = AuthMiddleware::equipeInterne();
        $db    = Database::connect();
        $g     = self::garageDe($user);
        $annee = (int)($_GET['annee'] ?? date('Y'));

        $joinCt    = $g !== null ? "JOIN clients c ON c.id = ct.client_id AND c.garage_id = $g" : '';
        $joinDevis = $g !== null ? "JOIN clients c ON c.id = d.client_id AND c.garage_id = $g" : '';

        $stmt = $db->prepare(
            "SELECT MONTH(p.date_paiement) AS mois,
                    COALESCE(SUM(p.montant), 0) AS total,
                    COUNT(*) AS nb_paiements
             FROM paiements p
             JOIN contrats_csa ct ON ct.id = p.contrat_id
             $joinCt
             WHERE p.statut = 'payé' AND YEAR(p.date_paiement) = ?
             GROUP BY MONTH(p.date_paiement)"
        );
        $stmt->execute([$annee]);
        $parMois = array_column($stmt->fetchAll(), null, 'mois');

        $devisStmt = $db->prepare(
            "SELECT MONTH(d.updated_at) AS mois, COALESCE(SUM(d.total), 0) AS total
             FROM devis d $joinDevis
             WHERE d.statut = 'accepté' AND YEAR(d.updated_at) = ?
             GROUP BY MONTH(d.updated_at)"
        );
        $devisStmt->execute([$annee]);
        $devisParMois = array_column($devisStmt->fetchAll(), 'total', 'mois');

        $resultat = [];
        for ($m = 1; $m <= 12; $m++) {
            $csa = (float)($parMois[$m]['total'] ?? 0);
            $rep = (float)($devisParMois[$m] ?? 0);
            $resultat[] = [
                'mois'           => $m,
                'ca_csa'         => $csa,
                'ca_reparations' => $rep,
                'ca_total'       => $csa + $rep,
                'nb_paiements'   => (int)($parMois[$m]['nb_paiements'] ?? 0),
            ];
        }

        self::succes(['annee' => $annee, 'ca_mensuel' => $resultat]);
    }

    // ----------------------------------------------------------
    // GET /stats/commerciaux?annee=&mois=
    // ----------------------------------------------------------
    public static function commerciaux(): void {
        $user = AuthMiddleware::adminOuCommercial();
        $db = Database::connect();
        $g  = self::garageDe($user);

        $filtre = '';
        $params = [];
        if (!empty($_GET['annee'])) {
            $filtre .= ' AND YEAR(ct.created_at) = ?';
            $params[] = (int)$_GET['annee'];
        }
        if (!empty($_GET['mois'])) {
            $filtre .= ' AND MONTH(ct.created_at) = ?';
            $params[] = (int)$_GET['mois'];
        }

        $condGarage = $g !== null ? "AND u.garage_id = $g" : '';

        $stmt = $db->prepare(
            "SELECT u.id, CONCAT(u.prenom, ' ', u.nom) AS commercial,
                    COUNT(ct.id) AS contrats_vendus,
                    COALESCE(SUM(ct.mensualite), 0) AS mensualites_generees
             FROM utilisateurs u
             LEFT JOIN contrats_csa ct ON ct.commercial_id = u.id $filtre
             WHERE u.role IN ('commercial', 'admin') AND u.actif = 1 $condGarage
             GROUP BY u.id
             ORDER BY contrats_vendus DESC"
        );
        $stmt->execute($params);

        self::succes(['commerciaux' => $stmt->fetchAll()]);
    }

    // ----------------------------------------------------------
    // GET /stats/rapport-pdf?mois=7&annee=2026
    // ----------------------------------------------------------
    public static function rapportPdf(): void {
        $user  = AuthMiddleware::adminOuCommercial();
        $db    = Database::connect();
        $g     = self::garageDe($user);
        $mois  = min(12, max(1, (int)($_GET['mois'] ?? date('n'))));
        $annee = (int)($_GET['annee'] ?? date('Y'));

        $nomsMois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet',
                     'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        $joinCt    = $g !== null ? "JOIN clients c ON c.id = ct.client_id AND c.garage_id = $g" : '';
        $joinDevis = $g !== null ? "JOIN clients c ON c.id = d.client_id AND c.garage_id = $g" : '';
        $joinVeh   = $g !== null ? "JOIN clients c ON c.id = v.client_id AND c.garage_id = $g"
                                 : 'JOIN clients c ON c.id = v.client_id';
        $condClients = $g !== null ? "AND garage_id = $g" : '';

        $caCsa = (float)$db->query(
            "SELECT COALESCE(SUM(p.montant), 0) FROM paiements p
             JOIN contrats_csa ct ON ct.id = p.contrat_id $joinCt
             WHERE p.statut = 'payé' AND YEAR(p.date_paiement) = $annee AND MONTH(p.date_paiement) = $mois"
        )->fetchColumn();

        $caReparations = (float)$db->query(
            "SELECT COALESCE(SUM(d.total), 0) FROM devis d $joinDevis
             WHERE d.statut = 'accepté' AND YEAR(d.updated_at) = $annee AND MONTH(d.updated_at) = $mois"
        )->fetchColumn();

        $contratsVendus = (int)$db->query(
            "SELECT COUNT(*) FROM contrats_csa ct $joinCt
             WHERE YEAR(ct.created_at) = $annee AND MONTH(ct.created_at) = $mois"
        )->fetchColumn();

        $contratsActifs = (int)$db->query(
            "SELECT COUNT(*) FROM contrats_csa ct $joinCt WHERE ct.statut = 'actif'"
        )->fetchColumn();

        $nouveauxClients = (int)$db->query(
            "SELECT COUNT(*) FROM clients
             WHERE YEAR(created_at) = $annee AND MONTH(created_at) = $mois $condClients"
        )->fetchColumn();

        $interventionsTerminees = (int)$db->query(
            "SELECT COUNT(*) FROM interventions i JOIN vehicules v ON v.id = i.vehicule_id $joinVeh
             WHERE i.statut = 'terminé' AND YEAR(i.date_fin) = $annee AND MONTH(i.date_fin) = $mois"
        )->fetchColumn();

        $diagnostics = (int)$db->query(
            "SELECT COUNT(*) FROM diagnostics dg JOIN vehicules v ON v.id = dg.vehicule_id $joinVeh
             WHERE YEAR(dg.date_diagnostic) = $annee AND MONTH(dg.date_diagnostic) = $mois"
        )->fetchColumn();

        $commerciaux = $db->query(
            "SELECT CONCAT(u.prenom, ' ', u.nom) AS nom, COUNT(ct.id) AS nb
             FROM contrats_csa ct
             JOIN utilisateurs u ON u.id = ct.commercial_id
             " . ($g !== null ? "JOIN clients c ON c.id = ct.client_id AND c.garage_id = $g" : '') . "
             WHERE YEAR(ct.created_at) = $annee AND MONTH(ct.created_at) = $mois
             GROUP BY ct.commercial_id ORDER BY nb DESC LIMIT 5"
        )->fetchAll();

        $repartition = $db->query(
            "SELECT f.nom, COUNT(*) AS nb FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id $joinCt
             WHERE ct.statut = 'actif' GROUP BY f.nom"
        )->fetchAll();

        require_once __DIR__ . '/../../lib/PdfMinimal.php';
        $pdf = new PdfMinimal();
        $entete = $g !== null ? ($user['garage_nom'] ?? 'SERENO AUTO') : 'SERENO SMS — Plateforme';
        $pdf->titre($entete . ' — Rapport mensuel ' . $nomsMois[$mois] . ' ' . $annee);
        $pdf->ligne('Généré le ' . date('d/m/Y à H:i'));
        $pdf->separateur();

        $pdf->ligne('CHIFFRE D\'AFFAIRES', 13, true);
        $pdf->deuxColonnes('Mensualités CSA encaissées', number_format($caCsa, 0, ',', ' ') . ' F');
        $pdf->deuxColonnes('Réparations facturées (devis acceptés)', number_format($caReparations, 0, ',', ' ') . ' F');
        $pdf->deuxColonnes('CA TOTAL', number_format($caCsa + $caReparations, 0, ',', ' ') . ' F CFA', true);
        $pdf->separateur();

        $pdf->ligne('ACTIVITÉ COMMERCIALE', 13, true);
        $pdf->deuxColonnes('Contrats CSA vendus ce mois', (string)$contratsVendus);
        $pdf->deuxColonnes('Contrats actifs (total)', (string)$contratsActifs);
        $pdf->deuxColonnes('Nouveaux clients', (string)$nouveauxClients);
        foreach ($repartition as $r) {
            $pdf->deuxColonnes('  Formule ' . ucfirst($r['nom']), $r['nb'] . ' contrat(s)');
        }
        $pdf->separateur();

        $pdf->ligne('ATELIER', 13, true);
        $pdf->deuxColonnes('Interventions terminées', (string)$interventionsTerminees);
        $pdf->deuxColonnes('Diagnostics réalisés', (string)$diagnostics);
        $pdf->separateur();

        if ($commerciaux) {
            $pdf->ligne('TOP COMMERCIAUX DU MOIS', 13, true);
            $rang = 1;
            foreach ($commerciaux as $co) {
                $pdf->deuxColonnes($rang . '. ' . $co['nom'], $co['nb'] . ' contrat(s)');
                $rang++;
            }
            $pdf->separateur();
        }

        $pdf->ligne('SERENO SMS — ' . SERENO_TELEPHONE, 9);

        $pdf->envoyer(sprintf('rapport-sereno-%04d-%02d.pdf', $annee, $mois));
    }
}

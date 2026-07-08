<?php
// ============================================================
//  SERENO SMS — StatsController (Module 7)
//  KPIs dashboard, CA mensuel, performance commerciaux,
//  export PDF du rapport mensuel
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class StatsController extends BaseController {

    // ----------------------------------------------------------
    // GET /stats/dashboard — tous les KPIs de la page d'accueil
    // ----------------------------------------------------------
    public static function dashboard(): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $moisCourant = date('Y-m');

        // Compteurs principaux
        $clientsActifs   = (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        $clientsNouveaux = (int)$db->query(
            "SELECT COUNT(*) FROM clients WHERE DATE_FORMAT(created_at, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        $vehicules         = (int)$db->query('SELECT COUNT(*) FROM vehicules')->fetchColumn();
        $vehiculesNouveaux = (int)$db->query(
            "SELECT COUNT(*) FROM vehicules WHERE DATE_FORMAT(created_at, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        $contratsActifs = (int)$db->query("SELECT COUNT(*) FROM contrats_csa WHERE statut = 'actif'")->fetchColumn();
        $contratsExpirentBientot = (int)$db->query(
            "SELECT COUNT(*) FROM contrats_csa WHERE statut = 'actif'
             AND date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $interventionsEnCours = (int)$db->query(
            "SELECT COUNT(*) FROM interventions WHERE statut = 'en_cours'"
        )->fetchColumn();
        $interventionsPlanifiees = (int)$db->query(
            "SELECT COUNT(*) FROM interventions WHERE statut = 'planifié'"
        )->fetchColumn();

        $rdvAujourdhui = (int)$db->query(
            "SELECT COUNT(*) FROM rendez_vous WHERE DATE(date_rdv) = CURDATE() AND statut != 'annulé'"
        )->fetchColumn();
        $rdvConfirmes = (int)$db->query(
            "SELECT COUNT(*) FROM rendez_vous WHERE DATE(date_rdv) = CURDATE() AND statut = 'confirmé'"
        )->fetchColumn();

        $diagnosticsMois = (int)$db->query(
            "SELECT COUNT(*) FROM diagnostics WHERE DATE_FORMAT(date_diagnostic, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        $notificationsNonLues = (int)$db->query('SELECT COUNT(*) FROM notifications WHERE lu = 0')->fetchColumn();

        // CA du mois : paiements CSA payés
        $caMois = (float)$db->query(
            "SELECT COALESCE(SUM(montant), 0) FROM paiements
             WHERE statut = 'payé' AND DATE_FORMAT(date_paiement, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        // + devis acceptés du mois (facturation réparations)
        $caReparations = (float)$db->query(
            "SELECT COALESCE(SUM(total), 0) FROM devis
             WHERE statut = 'accepté' AND DATE_FORMAT(updated_at, '%Y-%m') = '$moisCourant'"
        )->fetchColumn();

        // Paiements en attente
        $paiementsAttente = $db->query(
            "SELECT COUNT(*) AS nb, COALESCE(SUM(montant), 0) AS somme
             FROM paiements WHERE statut = 'en_attente'"
        )->fetch();

        // Mensualités attendues (contrats actifs)
        $mensualitesAttendues = (float)$db->query(
            "SELECT COALESCE(SUM(mensualite), 0) FROM contrats_csa WHERE statut = 'actif'"
        )->fetchColumn();

        // Répartition par formule
        $repartition = $db->query(
            "SELECT f.nom, COUNT(*) AS nb, SUM(ct.mensualite) AS mensualites
             FROM contrats_csa ct JOIN formules_csa f ON f.id = ct.formule_id
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
        ]);
    }

    // ----------------------------------------------------------
    // GET /stats/ca-mensuel?annee=2026
    // ----------------------------------------------------------
    public static function caMensuel(): void {
        AuthMiddleware::equipeInterne();
        $db    = Database::connect();
        $annee = (int)($_GET['annee'] ?? date('Y'));

        $stmt = $db->prepare(
            "SELECT MONTH(date_paiement) AS mois,
                    COALESCE(SUM(montant), 0) AS total,
                    COUNT(*) AS nb_paiements
             FROM paiements
             WHERE statut = 'payé' AND YEAR(date_paiement) = ?
             GROUP BY MONTH(date_paiement)"
        );
        $stmt->execute([$annee]);
        $parMois = array_column($stmt->fetchAll(), null, 'mois');

        $devisStmt = $db->prepare(
            "SELECT MONTH(updated_at) AS mois, COALESCE(SUM(total), 0) AS total
             FROM devis WHERE statut = 'accepté' AND YEAR(updated_at) = ?
             GROUP BY MONTH(updated_at)"
        );
        $devisStmt->execute([$annee]);
        $devisParMois = array_column($devisStmt->fetchAll(), 'total', 'mois');

        $resultat = [];
        for ($m = 1; $m <= 12; $m++) {
            $csa  = (float)($parMois[$m]['total'] ?? 0);
            $rep  = (float)($devisParMois[$m] ?? 0);
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
        AuthMiddleware::adminOuCommercial();
        $db = Database::connect();

        $where  = "WHERE u.role IN ('commercial', 'admin')";
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

        $stmt = $db->prepare(
            "SELECT u.id, CONCAT(u.prenom, ' ', u.nom) AS commercial,
                    COUNT(ct.id) AS contrats_vendus,
                    COALESCE(SUM(ct.mensualite), 0) AS mensualites_generees
             FROM utilisateurs u
             LEFT JOIN contrats_csa ct ON ct.commercial_id = u.id $filtre
             $where AND u.actif = 1
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
        AuthMiddleware::adminOuCommercial();
        $db    = Database::connect();
        $mois  = min(12, max(1, (int)($_GET['mois'] ?? date('n'))));
        $annee = (int)($_GET['annee'] ?? date('Y'));

        $nomsMois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet',
                     'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        // Données du rapport
        $caCsa = (float)$db->query(
            "SELECT COALESCE(SUM(montant), 0) FROM paiements
             WHERE statut = 'payé' AND YEAR(date_paiement) = $annee AND MONTH(date_paiement) = $mois"
        )->fetchColumn();

        $caReparations = (float)$db->query(
            "SELECT COALESCE(SUM(total), 0) FROM devis
             WHERE statut = 'accepté' AND YEAR(updated_at) = $annee AND MONTH(updated_at) = $mois"
        )->fetchColumn();

        $contratsVendus = (int)$db->query(
            "SELECT COUNT(*) FROM contrats_csa
             WHERE YEAR(created_at) = $annee AND MONTH(created_at) = $mois"
        )->fetchColumn();

        $contratsActifs = (int)$db->query(
            "SELECT COUNT(*) FROM contrats_csa WHERE statut = 'actif'"
        )->fetchColumn();

        $nouveauxClients = (int)$db->query(
            "SELECT COUNT(*) FROM clients WHERE YEAR(created_at) = $annee AND MONTH(created_at) = $mois"
        )->fetchColumn();

        $interventionsTerminees = (int)$db->query(
            "SELECT COUNT(*) FROM interventions
             WHERE statut = 'terminé' AND YEAR(date_fin) = $annee AND MONTH(date_fin) = $mois"
        )->fetchColumn();

        $diagnostics = (int)$db->query(
            "SELECT COUNT(*) FROM diagnostics
             WHERE YEAR(date_diagnostic) = $annee AND MONTH(date_diagnostic) = $mois"
        )->fetchColumn();

        $commerciaux = $db->query(
            "SELECT CONCAT(u.prenom, ' ', u.nom) AS nom, COUNT(ct.id) AS nb
             FROM contrats_csa ct JOIN utilisateurs u ON u.id = ct.commercial_id
             WHERE YEAR(ct.created_at) = $annee AND MONTH(ct.created_at) = $mois
             GROUP BY ct.commercial_id ORDER BY nb DESC LIMIT 5"
        )->fetchAll();

        $repartition = $db->query(
            "SELECT f.nom, COUNT(*) AS nb FROM contrats_csa ct
             JOIN formules_csa f ON f.id = ct.formule_id
             WHERE ct.statut = 'actif' GROUP BY f.nom"
        )->fetchAll();

        // Génération PDF
        require_once __DIR__ . '/../../lib/PdfMinimal.php';
        $pdf = new PdfMinimal();
        $pdf->titre('SERENO AUTO — Rapport mensuel ' . $nomsMois[$mois] . ' ' . $annee);
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
            foreach ($commerciaux as $c) {
                $pdf->deuxColonnes($rang . '. ' . $c['nom'], $c['nb'] . ' contrat(s)');
                $rang++;
            }
            $pdf->separateur();
        }

        $pdf->ligne('SERENO AUTO — Yaoundé, Cameroun — ' . SERENO_TELEPHONE, 9);

        $pdf->envoyer(sprintf('rapport-sereno-%04d-%02d.pdf', $annee, $mois));
    }
}

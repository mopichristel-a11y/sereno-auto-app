<?php
// ============================================================
//  SERENO SMS — CRON de rappels automatiques (Module 6)
//
//  À planifier sur Hostinger (hPanel → Avancé → Tâches Cron) :
//    0 7 * * *  php /home/USER/domains/DOMAINE/public_html/cron/rappels.php
//
//  1. Génère les rappels : entretiens (vidange, pneus...),
//     expirations de contrats CSA (J-30/15/7/1), paiements en retard.
//  2. Envoie la file d'attente de notifications (WhatsApp/SMS/Email).
// ============================================================

if (PHP_SAPI !== 'cli' && empty($_GET['cle_cron'])) {
    // Autoriser l'appel HTTP uniquement avec la clé (cron web Hostinger)
    http_response_code(403);
    die('Accès refusé. Utilisation : CLI ou ?cle_cron=JWT_SECRET.');
}
if (PHP_SAPI !== 'cli' && $_GET['cle_cron'] !== (getenv('JWT_SECRET') ?: null)) {
    require_once __DIR__ . '/../config/database.php';
    if ($_GET['cle_cron'] !== JWT_SECRET) {
        http_response_code(403);
        die('Clé cron invalide.');
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../api/vehicules/VehiculeController.php';

// Stubs pour charger VehiculeController hors contexte HTTP :
// (BaseController exige middleware/auth déjà inclus via VehiculeController)

$db      = Database::connect();
$journal = [];

// ------------------------------------------------------------
// 1. EXPIRATIONS DE CONTRATS CSA — alertes J-30, J-15, J-7, J-1
// ------------------------------------------------------------
$paliers = [30, 15, 7, 1];
$stmt = $db->query(
    "SELECT ct.id, ct.reference, ct.date_expiration, ct.client_id,
            DATEDIFF(ct.date_expiration, CURDATE()) AS jours,
            c.nom AS client_nom, f.nom AS formule,
            v.marque, v.modele
     FROM contrats_csa ct
     JOIN clients c      ON c.id = ct.client_id
     JOIN formules_csa f ON f.id = ct.formule_id
     JOIN vehicules v    ON v.id = ct.vehicule_id
     WHERE ct.statut = 'actif'
       AND ct.date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
);

$nbExpiration = 0;
foreach ($stmt->fetchAll() as $ct) {
    $jours = (int)$ct['jours'];
    if (!in_array($jours, $paliers)) continue;

    $id = NotificationService::enfiler(
        $db,
        'csa_expiration',
        "Contrat CSA — expiration dans $jours jour(s)",
        sprintf(
            "Bonjour %s 👋\nVotre contrat %s (formule %s — %s %s) expire le %s.\n" .
            "Contactez SERENO AUTO au %s pour le renouveler et rester couvert.",
            $ct['client_nom'], $ct['reference'], ucfirst($ct['formule']),
            $ct['marque'], $ct['modele'],
            date('d/m/Y', strtotime($ct['date_expiration'])),
            SERENO_TELEPHONE
        ),
        (int)$ct['client_id'],
        null,
        'whatsapp,app',
        "csa_expiration-{$ct['id']}-J$jours"
    );
    if ($id) $nbExpiration++;
}
$journal[] = "Contrats CSA : $nbExpiration alerte(s) d'expiration créée(s).";

// ------------------------------------------------------------
// 2. CONTRATS ARRIVÉS À ÉCHÉANCE → statut 'expiré'
// ------------------------------------------------------------
$nbExpires = $db->exec(
    "UPDATE contrats_csa SET statut = 'expiré'
     WHERE statut = 'actif' AND date_expiration < CURDATE()"
);
$journal[] = "Contrats passés au statut expiré : $nbExpires.";

// ------------------------------------------------------------
// 3. RAPPELS D'ENTRETIEN (vidange, pneus, batterie, courroie...)
//    Seuils : dépassé ou ≤ 15 jours / ≤ 1000 km
// ------------------------------------------------------------
$typeNotification = [
    'vidange'          => 'vidange',
    'filtre_air'       => 'filtre',
    'filtre_habitacle' => 'filtre',
    'filtre_carburant' => 'filtre',
    'filtre_boite'     => 'filtre',
    'pneus'            => 'pneus',
    'batterie'         => 'batterie',
];

$vehicules = $db->query(
    'SELECT v.id, v.marque, v.modele, v.kilometrage,
            c.id AS client_id, c.nom AS client_nom
     FROM vehicules v JOIN clients c ON c.id = v.client_id'
)->fetchAll();

$nbEntretien = 0;
foreach ($vehicules as $v) {
    $rappels = VehiculeController::calculerRappels($db, (int)$v['id'], (int)$v['kilometrage']);
    foreach ($rappels as $r) {
        if (!in_array($r['urgence'], ['depasse', 'urgent'])) continue;

        $echeance = [];
        if ($r['jours_restants'] !== null) {
            $echeance[] = $r['jours_restants'] < 0
                ? 'échéance dépassée de ' . abs($r['jours_restants']) . ' jour(s)'
                : 'dans ' . $r['jours_restants'] . ' jour(s)';
        }
        if ($r['km_restants'] !== null) {
            $echeance[] = $r['km_restants'] < 0
                ? abs($r['km_restants']) . ' km au-delà du seuil'
                : 'dans ' . $r['km_restants'] . ' km';
        }

        // Une seule notification par véhicule/type/échéance
        $cle = sprintf('entretien-%d-%s-%s', $v['id'], $r['type'], $r['prochaine_date'] ?? $r['prochain_km'] ?? 'nc');

        $id = NotificationService::enfiler(
            $db,
            $typeNotification[$r['type']] ?? 'autre',
            $r['libelle'] . ' — ' . $v['marque'] . ' ' . $v['modele'],
            sprintf(
                "Bonjour %s 👋\n%s à prévoir pour votre %s %s (%s).\n" .
                "Prenez rendez-vous avec SERENO AUTO au %s.",
                $v['client_nom'], $r['libelle'], $v['marque'], $v['modele'],
                implode(' · ', $echeance) ?: 'échéance atteinte',
                SERENO_TELEPHONE
            ),
            (int)$v['client_id'],
            null,
            'whatsapp,app',
            $cle
        );
        if ($id) $nbEntretien++;
    }
}
$journal[] = "Entretiens : $nbEntretien rappel(s) créé(s).";

// ------------------------------------------------------------
// 4. PAIEMENTS EN RETARD (mensualités échues non couvertes)
// ------------------------------------------------------------
require_once __DIR__ . '/../api/contrats/ContratController.php';

$contrats = $db->query(
    "SELECT ct.*, c.nom AS client_nom,
            (SELECT COALESCE(SUM(p.montant), 0) FROM paiements p
             WHERE p.contrat_id = ct.id AND p.statut = 'payé') AS total_paye
     FROM contrats_csa ct
     JOIN clients c ON c.id = ct.client_id
     WHERE ct.statut = 'actif'"
)->fetchAll();

$nbPaiement = 0;
foreach ($contrats as $ct) {
    $echeances = ContratController::etatEcheances($ct);
    if ($echeances['a_jour']) continue;

    // Un rappel par contrat et par mois calendaire
    $cle = sprintf('paiement-%d-%s', $ct['id'], date('Y-m'));
    $id  = NotificationService::enfiler(
        $db,
        'paiement',
        'Mensualité CSA en attente — ' . $ct['reference'],
        sprintf(
            "Bonjour %s 👋\nVotre contrat %s présente un solde de %s F CFA (%d mensualité(s)).\n" .
            "Réglez par Orange Money, MTN MoMo ou en agence. Contact : %s.",
            $ct['client_nom'], $ct['reference'],
            number_format($echeances['retard'], 0, ',', ' '),
            $echeances['mensualites_retard'],
            SERENO_TELEPHONE
        ),
        (int)$ct['client_id'],
        null,
        'whatsapp,sms,app',
        $cle
    );
    if ($id) $nbPaiement++;
}
$journal[] = "Paiements : $nbPaiement relance(s) créée(s).";

// ------------------------------------------------------------
// 5. ENVOI DE LA FILE D'ATTENTE
// ------------------------------------------------------------
$resultat  = NotificationService::traiterFile($db, 100);
$journal[] = sprintf(
    'File traitée : %d notification(s), %d envoyée(s), %d échec(s).',
    $resultat['traitees'], $resultat['envoyees'], $resultat['echecs']
);

// ------------------------------------------------------------
//  Journal
// ------------------------------------------------------------
$sortie = '[' . date('Y-m-d H:i:s') . "] CRON rappels\n  " . implode("\n  ", $journal) . "\n";
echo PHP_SAPI === 'cli' ? $sortie : nl2br(htmlspecialchars($sortie));

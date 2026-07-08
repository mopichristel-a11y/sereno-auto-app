<?php
// ============================================================
//  SERENO SMS — Données de démonstration (optionnel)
//  Usage : php database/seed_demo.php
//  ⚠️  À utiliser uniquement sur un environnement de test.
// ============================================================

require_once __DIR__ . '/../config/database.php';

$db = Database::connect();

if ((int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn() > 0) {
    die("❌ La table clients n'est pas vide. Seed annulé pour éviter les doublons.\n");
}

$db->beginTransaction();

// ---- Équipe ----
$hash = password_hash('Sereno@2026', PASSWORD_BCRYPT, ['cost' => 12]);
$db->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, telephone, role) VALUES
    ('Kamdem', 'Jean-Baptiste', 'jb@sereno-auto.cm', ?, '+237 690 000 001', 'commercial'),
    ('Mendomo', 'Flore', 'flore@sereno-auto.cm', ?, '+237 690 000 002', 'commercial'),
    ('Essomba', 'Jean-Paul', 'jeanpaul@sereno-auto.cm', ?, '+237 690 000 003', 'technicien'),
    ('Nkoulou', 'Rodrigue', 'rodrigue@sereno-auto.cm', ?, '+237 690 000 004', 'technicien')")
   ->execute([$hash, $hash, $hash, $hash]);

$commercial1 = (int)$db->query("SELECT id FROM utilisateurs WHERE email = 'jb@sereno-auto.cm'")->fetchColumn();
$technicien1 = (int)$db->query("SELECT id FROM utilisateurs WHERE email = 'jeanpaul@sereno-auto.cm'")->fetchColumn();
$technicien2 = (int)$db->query("SELECT id FROM utilisateurs WHERE email = 'rodrigue@sereno-auto.cm'")->fetchColumn();

// ---- Clients ----
$clients = [
    ['PHARMACIE LA GLOIRE', '+237 691 111 111', 'gloire@exemple.cm', 'Yaoundé — Mvog-Ada', 'entreprise'],
    ['DODET Virginie',      '+237 692 222 222', 'v.dodet@exemple.cm', 'Yaoundé — Bastos', 'particulier'],
    ['ZELLER Marc',         '+237 693 333 333', 'm.zeller@exemple.cm', 'Yaoundé — Odza', 'particulier'],
    ['FOTSO André',         '+237 694 444 444', 'a.fotso@exemple.cm', 'Yaoundé — Mendong', 'particulier'],
    ['NGO Marie',           '+237 695 555 555', 'm.ngo@exemple.cm', 'Yaoundé — Essos', 'particulier'],
    ['KAMGA Pierre',        '+237 696 666 666', 'p.kamga@exemple.cm', 'Yaoundé — Nsimeyong', 'particulier'],
];
$stmt = $db->prepare('INSERT INTO clients (nom, telephone, email, adresse, type_client, created_by) VALUES (?, ?, ?, ?, ?, ?)');
$idsClients = [];
foreach ($clients as $c) {
    $stmt->execute([...$c, $commercial1]);
    $idsClients[] = (int)$db->lastInsertId();
}

// ---- Véhicules ----
$vehicules = [
    [$idsClients[0], 'Toyota',  'RAV4 EV',     2024, 'CE 234 AB', 'LS4ASE2E1RA282310', 43481, 'électrique', 'automatique'],
    [$idsClients[1], 'Changan', 'Lumin',       2024, 'CE 567 CD', 'LS4ASE2E1RA282311', 3481,  'électrique', 'automatique'],
    [$idsClients[2], 'Nissan',  'X-Trail T31', 2010, 'CE 890 EF', '1J4NF2GB8AD634359', 168000, 'essence',   'manuelle'],
    [$idsClients[3], 'Honda',   'CR-V',        2015, 'CE 123 GH', null, 142000, 'essence', 'automatique'],
    [$idsClients[4], 'Hyundai', 'Tucson',      2018, 'CE 456 IJ', null, 98000,  'diesel',  'automatique'],
    [$idsClients[5], 'Toyota',  'Land Prado',  2019, 'CE 789 KL', null, 87500,  'diesel',  'automatique'],
];
$stmt = $db->prepare(
    'INSERT INTO vehicules (client_id, marque, modele, annee, immatriculation, vin, kilometrage, carburant, boite_vitesses)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$idsVehicules = [];
foreach ($vehicules as $v) {
    $stmt->execute($v);
    $idsVehicules[] = (int)$db->lastInsertId();
}

// ---- Contrats CSA ----
$formules = $db->query('SELECT id, nom, mensualite, couverture_pct FROM formules_csa')->fetchAll();
$parNom = array_column($formules, null, 'nom');

$contrats = [
    // [vehicule, client, formule, debut, expiration]
    [0, 0, 'premium',   date('Y-01-01'), date('Y-12-31')],
    [1, 1, 'confort',   date('Y-03-01'), date('Y-m-d', strtotime(date('Y-03-01') . ' +12 months -1 day'))],
    [2, 2, 'premium',   date('Y-01-01'), date('Y-12-31')],
    [3, 3, 'essentiel', date('Y-m-d', strtotime('-11 months')), date('Y-m-d', strtotime('+8 days'))],
    [4, 4, 'essentiel', date('Y-m-d', strtotime('-2 months')), date('Y-m-d', strtotime('+10 months'))],
    [5, 5, 'premium',   date('Y-m-d', strtotime('-6 months')), date('Y-m-d', strtotime('+6 months'))],
];
$stmt = $db->prepare(
    'INSERT INTO contrats_csa (reference, client_id, vehicule_id, formule_id, date_debut, date_expiration,
                               mensualite, couverture_pct, statut, commercial_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "actif", ?)'
);
$num = 1;
$idsContrats = [];
foreach ($contrats as [$vi, $ci, $formule, $debut, $fin]) {
    $f = $parNom[$formule];
    $stmt->execute([
        sprintf('CSA-%s-%04d', date('Y'), $num++),
        $idsClients[$ci], $idsVehicules[$vi], $f['id'], $debut, $fin,
        $f['mensualite'], $f['couverture_pct'], $commercial1,
    ]);
    $idsContrats[] = (int)$db->lastInsertId();
}

// ---- Paiements (mensualités du mois courant) ----
$stmt = $db->prepare(
    'INSERT INTO paiements (contrat_id, montant, date_paiement, moyen, statut) VALUES (?, ?, ?, ?, ?)'
);
$stmt->execute([$idsContrats[0], 45000, date('Y-m-03'), 'orange_money', 'payé']);
$stmt->execute([$idsContrats[1], 28000, date('Y-m-02'), 'mtn_momo', 'payé']);
$stmt->execute([$idsContrats[2], 45000, date('Y-m-05'), 'espèces', 'payé']);
$stmt->execute([$idsContrats[3], 15000, date('Y-m-d'), 'orange_money', 'en_attente']);

// ---- Carnet d'entretien ----
$stmt = $db->prepare(
    'INSERT INTO carnet_entretien (vehicule_id, type_entretien, date_intervention, kilometrage, prochain_km, prochaine_date)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$idsVehicules[0], 'vidange', date('Y-01-10'), 41000, 46000, date('Y-m-d', strtotime(date('Y-01-10') . ' +6 months'))]);
$stmt->execute([$idsVehicules[0], 'filtre_air', date('Y-01-10'), 41000, 56000, date('Y-m-d', strtotime(date('Y-01-10') . ' +12 months'))]);
$stmt->execute([$idsVehicules[0], 'climatisation', date('Y-03-12'), 42000, null, date('Y-m-d', strtotime(date('Y-03-12') . ' +24 months'))]);
$stmt->execute([$idsVehicules[5], 'vidange', date('Y-m-d', strtotime('-5 months')), 82000, 87000, date('Y-m-d', strtotime('+1 month'))]);
$stmt->execute([$idsVehicules[3], 'pneus', date('Y-m-d', strtotime('-40 months')), 95000, 135000, date('Y-m-d', strtotime('+8 months'))]);

// ---- Diagnostics ----
$stmt = $db->prepare(
    'INSERT INTO diagnostics (reference, vehicule_id, technicien_id, date_diagnostic, outil, kilometrage, codes_dtc, commentaires, statut)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    'DIAG-' . date('Y') . '-0001', $idsVehicules[0], $technicien1,
    date('Y-m-d 09:30:00', strtotime('-5 days')), 'Launch X431', 43481,
    json_encode([
        ['code' => 'B1B9016', 'description' => 'Tension batterie 12V basse', 'statut' => 'actif'],
        ['code' => 'C1AB287', 'description' => 'Capteur amortisseur arrière', 'statut' => 'actif'],
    ], JSON_UNESCAPED_UNICODE),
    'Batterie 12V faible, amortisseurs arrière défaillants, 12 codes au total.', 'terminé',
]);
$stmt->execute([
    'DIAG-' . date('Y') . '-0002', $idsVehicules[1], $technicien2,
    date('Y-m-d 14:00:00', strtotime('-5 days')), 'Launch X431', 3481,
    json_encode([
        ['code' => 'U0184', 'description' => 'Perte communication réseau BDC', 'statut' => 'actif'],
        ['code' => 'C0035', 'description' => 'Essieu arrière — défaut structurel', 'statut' => 'actif'],
    ], JSON_UNESCAPED_UNICODE),
    'Essieu arrière cassé — véhicule immobilisé. 19 codes au total.', 'terminé',
]);

// ---- RDV du jour ----
$stmt = $db->prepare(
    'INSERT INTO rendez_vous (client_id, vehicule_id, date_rdv, motif, statut) VALUES (?, ?, ?, ?, ?)'
);
$stmt->execute([$idsClients[2], $idsVehicules[2], date('Y-m-d 08:30:00'), 'Récupération véhicule après réparation', 'confirmé']);
$stmt->execute([$idsClients[0], $idsVehicules[0], date('Y-m-d 10:00:00'), 'Diagnostic + devis réparations', 'confirmé']);
$stmt->execute([$idsClients[1], $idsVehicules[1], date('Y-m-d 11:30:00'), 'Suivi réparation réseau BDC', 'en_attente']);
$stmt->execute([$idsClients[5], $idsVehicules[5], date('Y-m-d 14:00:00'), 'Vidange + contrôle général', 'en_attente']);
$stmt->execute([$idsClients[4], $idsVehicules[4], date('Y-m-d 16:00:00'), 'Renouvellement contrat CSA', 'en_attente']);

$db->commit();

echo "✅ Données de démonstration insérées.\n";
echo "   Comptes équipe (mot de passe : Sereno@2026) :\n";
echo "   - jb@sereno-auto.cm (commercial)\n";
echo "   - jeanpaul@sereno-auto.cm (technicien)\n";

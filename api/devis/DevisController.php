<?php
// ============================================================
//  SERENO SMS — DevisController (Module 4)
//  Devis : CRUD, cycle de vie, PDF, couverture CSA appliquée
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class DevisController extends BaseController {

    private const STATUTS = ['brouillon', 'envoyé', 'accepté', 'refusé', 'expiré'];

    // ----------------------------------------------------------
    // GET /devis?statut=&client_id=&vehicule_id=&page=
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
        foreach (['statut' => 'd.statut', 'client_id' => 'd.client_id', 'vehicule_id' => 'd.vehicule_id'] as $get => $col) {
            if (!empty($_GET[$get])) {
                $where[]           = "$col = :$get";
                $params[":$get"]   = $get === 'statut' ? $_GET[$get] : (int)$_GET[$get];
            }
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $db->prepare(
            "SELECT COUNT(*) FROM devis d JOIN clients c ON c.id = d.client_id $sqlWhere"
        );
        $total->execute($params);
        $nbTotal = (int)$total->fetchColumn();

        $stmt = $db->prepare(
            "SELECT d.*, c.nom AS client_nom, v.marque, v.modele, v.immatriculation,
                    dg.reference AS diagnostic_reference
             FROM devis d
             JOIN clients c   ON c.id = d.client_id
             JOIN vehicules v ON v.id = d.vehicule_id
             LEFT JOIN diagnostics dg ON dg.id = d.diagnostic_id
             $sqlWhere
             ORDER BY d.created_at DESC
             LIMIT $limite OFFSET $offset"
        );
        $stmt->execute($params);
        $devis = $stmt->fetchAll();
        foreach ($devis as &$d) {
            $d['lignes'] = self::decoderJson($d['lignes']);
        }

        self::succes([
            'devis' => $devis,
            'total' => $nbTotal,
            'page'  => $page,
            'pages' => (int)ceil($nbTotal / $limite),
        ]);
    }

    // ----------------------------------------------------------
    // GET /devis/{id} — avec couverture CSA appliquée
    // ----------------------------------------------------------
    public static function show(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $devis = self::chargerDevisComplet($db, $id);
        self::verifierGarage(self::garageDe($user), self::garageDuClient($db, (int)$devis['client_id']));
        self::succes(['devis' => $devis]);
    }

    // ----------------------------------------------------------
    // POST /devis — devis manuel (sans diagnostic)
    // Body: { client_id, vehicule_id, lignes: [{designation, montant}],
    //         main_oeuvre_pct?=10, notes?, validite_jours?=30, diagnostic_id? }
    // ----------------------------------------------------------
    public static function store(): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['client_id', 'vehicule_id', 'lignes']);

        if (!is_array($data['lignes']) || !count($data['lignes'])) {
            self::erreur(400, 'lignes doit être un tableau non vide.');
        }

        $db = Database::connect();
        $client = self::trouverOu404($db, 'clients', (int)$data['client_id'], 'Client');
        self::verifierGarage(self::garageDe($user), $client['garage_id']);
        $vehicule = self::trouverOu404($db, 'vehicules', (int)$data['vehicule_id'], 'Véhicule');
        if ((int)$vehicule['client_id'] !== (int)$data['client_id']) {
            self::erreur(400, 'Ce véhicule n\'appartient pas à ce client.');
        }

        [$lignes, $sousTotal] = self::validerLignes($data['lignes']);
        $moPct      = isset($data['main_oeuvre_pct']) ? max(0, (int)$data['main_oeuvre_pct']) : 10;
        $mainOeuvre = round($sousTotal * $moPct / 100, 2);
        $total      = round($sousTotal + $mainOeuvre, 2);

        $reference = self::genererReferenceJour($db, 'devis', 'DEVIS-SAUT');

        $db->prepare(
            'INSERT INTO devis (reference, diagnostic_id, client_id, vehicule_id, lignes,
                                sous_total, main_oeuvre_pct, main_oeuvre, total, statut, validite_jours, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "brouillon", ?, ?)'
        )->execute([
            $reference,
            !empty($data['diagnostic_id']) ? (int)$data['diagnostic_id'] : null,
            (int)$data['client_id'],
            (int)$data['vehicule_id'],
            json_encode($lignes, JSON_UNESCAPED_UNICODE),
            $sousTotal,
            $moPct,
            $mainOeuvre,
            $total,
            max(1, (int)($data['validite_jours'] ?? 30)),
            $data['notes'] ?? null,
        ]);

        self::succes(['id' => (int)$db->lastInsertId(), 'reference' => $reference, 'total' => $total],
                     'Devis créé.', 201);
    }

    // ----------------------------------------------------------
    // PUT /devis/{id} — modifier un brouillon
    // ----------------------------------------------------------
    public static function update(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        $db   = Database::connect();

        $devis = self::trouverOu404($db, 'devis', $id, 'Devis');
        self::verifierGarage(self::garageDe($user), self::garageDuClient($db, (int)$devis['client_id']));
        if (!in_array($devis['statut'], ['brouillon', 'envoyé'])) {
            self::erreur(409, "Devis {$devis['statut']} : modification impossible.");
        }

        $set    = [];
        $params = [];

        if (array_key_exists('lignes', $data)) {
            [$lignes, $sousTotal] = self::validerLignes($data['lignes']);
            $moPct      = isset($data['main_oeuvre_pct'])
                        ? max(0, (int)$data['main_oeuvre_pct'])
                        : (int)$devis['main_oeuvre_pct'];
            $mainOeuvre = round($sousTotal * $moPct / 100, 2);
            $total      = round($sousTotal + $mainOeuvre, 2);

            $set = ['lignes = ?', 'sous_total = ?', 'main_oeuvre_pct = ?', 'main_oeuvre = ?', 'total = ?'];
            $params = [json_encode($lignes, JSON_UNESCAPED_UNICODE), $sousTotal, $moPct, $mainOeuvre, $total];
        }

        foreach (['notes', 'validite_jours'] as $c) {
            if (array_key_exists($c, $data)) {
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE devis SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Devis mis à jour.');
    }

    // ----------------------------------------------------------
    // POST /devis/{id}/statut
    // Body: { statut: envoyé | accepté | refusé | expiré }
    // Si accepté → crée automatiquement une intervention planifiée.
    // ----------------------------------------------------------
    public static function changerStatut(int $id): void {
        AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['statut']);

        if (!in_array($data['statut'], self::STATUTS)) {
            self::erreur(400, 'Statut invalide. Valeurs : ' . implode(', ', self::STATUTS));
        }

        $db    = Database::connect();
        $devis = self::trouverOu404($db, 'devis', $id, 'Devis');
        self::verifierGarage(
            self::garageDe(AuthMiddleware::utilisateurCourant()),
            self::garageDuClient($db, (int)$devis['client_id'])
        );

        $db->prepare('UPDATE devis SET statut = ? WHERE id = ?')->execute([$data['statut'], $id]);

        // Marquer le diagnostic lié
        if ($data['statut'] === 'envoyé' && $devis['diagnostic_id']) {
            $db->prepare("UPDATE diagnostics SET statut = 'devis_envoyé' WHERE id = ?")
               ->execute([(int)$devis['diagnostic_id']]);
        }

        $interventionId = null;
        if ($data['statut'] === 'accepté') {
            // Créer l'intervention si aucune n'existe déjà pour ce devis
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
            'Statut du devis : ' . $data['statut'] . '.'
        );
    }

    // ----------------------------------------------------------
    // GET /devis/{id}/pdf — télécharge le devis en PDF
    // ----------------------------------------------------------
    public static function pdf(int $id): void {
        $user  = AuthMiddleware::equipeInterne();
        $db    = Database::connect();
        $devis = self::chargerDevisComplet($db, $id);
        self::verifierGarage(self::garageDe($user), self::garageDuClient($db, (int)$devis['client_id']));

        require_once __DIR__ . '/../../lib/PdfMinimal.php';
        $pdf = new PdfMinimal();
        $pdf->titre('SERENO AUTO — DEVIS ' . $devis['reference']);
        $pdf->ligne('Date : ' . date('d/m/Y', strtotime($devis['created_at'])));
        $pdf->ligne('Client : ' . $devis['client_nom']);
        $pdf->ligne('Véhicule : ' . $devis['marque'] . ' ' . $devis['modele']
                    . ($devis['immatriculation'] ? ' — ' . $devis['immatriculation'] : ''));
        if ($devis['diagnostic_reference']) {
            $pdf->ligne('Diagnostic : ' . $devis['diagnostic_reference']);
        }
        $pdf->separateur();
        $pdf->ligne('DÉTAIL DES TRAVAUX');
        foreach ($devis['lignes'] as $l) {
            $pdf->deuxColonnes($l['designation'], number_format((float)$l['montant'], 0, ',', ' ') . ' F');
        }
        $pdf->separateur();
        $pdf->deuxColonnes('Sous-total pièces', number_format((float)$devis['sous_total'], 0, ',', ' ') . ' F');
        $pdf->deuxColonnes('Main d\'oeuvre (' . $devis['main_oeuvre_pct'] . '%)',
                           number_format((float)$devis['main_oeuvre'], 0, ',', ' ') . ' F');
        $pdf->deuxColonnes('TOTAL', number_format((float)$devis['total'], 0, ',', ' ') . ' F CFA', true);

        if (!empty($devis['couverture'])) {
            $pdf->separateur();
            $pdf->ligne('CONTRAT CSA ' . strtoupper($devis['couverture']['formule'])
                        . ' — couverture ' . $devis['couverture']['couverture_pct'] . '%');
            $pdf->deuxColonnes('Pris en charge SERENO',
                               number_format($devis['couverture']['pris_en_charge'], 0, ',', ' ') . ' F');
            $pdf->deuxColonnes('Reste à charge client',
                               number_format($devis['couverture']['reste_a_charge'], 0, ',', ' ') . ' F', true);
        }

        $pdf->separateur();
        $pdf->ligne('Validité : ' . $devis['validite_jours'] . ' jours — ' . SERENO_TELEPHONE);
        if ($devis['notes']) $pdf->ligne('Notes : ' . $devis['notes']);

        $pdf->envoyer('devis-' . $devis['reference'] . '.pdf');
    }

    // ----------------------------------------------------------
    //  Helpers
    // ----------------------------------------------------------
    private static function validerLignes(mixed $brutes): array {
        if (!is_array($brutes) || !count($brutes)) {
            self::erreur(400, 'lignes doit être un tableau non vide.');
        }
        $lignes    = [];
        $sousTotal = 0;
        foreach ($brutes as $l) {
            if (empty($l['designation']) || !isset($l['montant'])) {
                self::erreur(400, 'Chaque ligne doit contenir designation et montant.');
            }
            $montant = (float)$l['montant'];
            if ($montant < 0) self::erreur(400, 'Montant de ligne négatif interdit.');
            $lignes[]   = ['designation' => trim($l['designation']), 'montant' => $montant];
            $sousTotal += $montant;
        }
        return [$lignes, round($sousTotal, 2)];
    }

    private static function chargerDevisComplet(PDO $db, int $id): array {
        $stmt = $db->prepare(
            "SELECT d.*, c.nom AS client_nom, c.telephone AS client_telephone,
                    v.marque, v.modele, v.immatriculation,
                    dg.reference AS diagnostic_reference
             FROM devis d
             JOIN clients c   ON c.id = d.client_id
             JOIN vehicules v ON v.id = d.vehicule_id
             LEFT JOIN diagnostics dg ON dg.id = d.diagnostic_id
             WHERE d.id = ?"
        );
        $stmt->execute([$id]);
        $devis = $stmt->fetch();
        if (!$devis) self::erreur(404, "Devis introuvable (id $id).");

        $devis['lignes'] = self::decoderJson($devis['lignes']);

        // Couverture CSA du véhicule (contrat actif)
        $contrat = $db->prepare(
            "SELECT ct.couverture_pct, ct.reference, f.nom AS formule
             FROM contrats_csa ct JOIN formules_csa f ON f.id = ct.formule_id
             WHERE ct.vehicule_id = ? AND ct.statut = 'actif'
               AND ct.date_expiration >= CURDATE() LIMIT 1"
        );
        $contrat->execute([(int)$devis['vehicule_id']]);
        if ($csa = $contrat->fetch()) {
            $pct  = (int)$csa['couverture_pct'];
            $pris = round((float)$devis['total'] * $pct / 100, 2);
            $devis['couverture'] = [
                'contrat'        => $csa['reference'],
                'formule'        => $csa['formule'],
                'couverture_pct' => $pct,
                'pris_en_charge' => $pris,
                'reste_a_charge' => round((float)$devis['total'] - $pris, 2),
            ];
        } else {
            $devis['couverture'] = null;
        }

        return $devis;
    }
}

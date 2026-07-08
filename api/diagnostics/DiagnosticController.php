<?php
// ============================================================
//  SERENO SMS — DiagnosticController (Module 4)
//  Diagnostics avec codes DTC (JSON), photos, commentaires
//  + génération de devis depuis un diagnostic
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class DiagnosticController extends BaseController {

    private const STATUTS = ['en_cours', 'terminé', 'devis_envoyé'];

    // ----------------------------------------------------------
    // GET /diagnostics?vehicule_id=&statut=&page=
    // ----------------------------------------------------------
    public static function index(): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();
        [$page, $limite, $offset] = self::pagination();

        $where  = [];
        $params = [];
        if (!empty($_GET['vehicule_id'])) {
            $where[] = 'd.vehicule_id = :vid';
            $params[':vid'] = (int)$_GET['vehicule_id'];
        }
        if (!empty($_GET['statut'])) {
            $where[] = 'd.statut = :statut';
            $params[':statut'] = $_GET['statut'];
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $db->prepare("SELECT COUNT(*) FROM diagnostics d $sqlWhere");
        $total->execute($params);
        $nbTotal = (int)$total->fetchColumn();

        $stmt = $db->prepare(
            "SELECT d.*, v.marque, v.modele, v.immatriculation,
                    c.id AS client_id, c.nom AS client_nom,
                    CONCAT(u.prenom, ' ', u.nom) AS technicien,
                    (SELECT dv.id FROM devis dv WHERE dv.diagnostic_id = d.id ORDER BY dv.id DESC LIMIT 1) AS devis_id,
                    (SELECT dv.total FROM devis dv WHERE dv.diagnostic_id = d.id ORDER BY dv.id DESC LIMIT 1) AS devis_total,
                    (SELECT dv.statut FROM devis dv WHERE dv.diagnostic_id = d.id ORDER BY dv.id DESC LIMIT 1) AS devis_statut
             FROM diagnostics d
             JOIN vehicules v ON v.id = d.vehicule_id
             JOIN clients c   ON c.id = v.client_id
             LEFT JOIN utilisateurs u ON u.id = d.technicien_id
             $sqlWhere
             ORDER BY d.date_diagnostic DESC
             LIMIT $limite OFFSET $offset"
        );
        $stmt->execute($params);
        $diagnostics = $stmt->fetchAll();

        foreach ($diagnostics as &$d) {
            $d['codes_dtc'] = self::decoderJson($d['codes_dtc']);
            $d['photos']    = self::decoderJson($d['photos']);
            $d['nb_codes']  = count($d['codes_dtc']);
        }

        self::succes([
            'diagnostics' => $diagnostics,
            'total'       => $nbTotal,
            'page'        => $page,
            'pages'       => (int)ceil($nbTotal / $limite),
        ]);
    }

    // ----------------------------------------------------------
    // GET /diagnostics/{id}
    // ----------------------------------------------------------
    public static function show(int $id): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $stmt = $db->prepare(
            "SELECT d.*, v.marque, v.modele, v.immatriculation, v.vin,
                    c.id AS client_id, c.nom AS client_nom, c.telephone AS client_telephone,
                    CONCAT(u.prenom, ' ', u.nom) AS technicien
             FROM diagnostics d
             JOIN vehicules v ON v.id = d.vehicule_id
             JOIN clients c   ON c.id = v.client_id
             LEFT JOIN utilisateurs u ON u.id = d.technicien_id
             WHERE d.id = ?"
        );
        $stmt->execute([$id]);
        $diagnostic = $stmt->fetch();
        if (!$diagnostic) self::erreur(404, "Diagnostic introuvable (id $id).");

        $diagnostic['codes_dtc'] = self::decoderJson($diagnostic['codes_dtc']);
        $diagnostic['photos']    = self::decoderJson($diagnostic['photos']);

        $devis = $db->prepare('SELECT * FROM devis WHERE diagnostic_id = ? ORDER BY id DESC');
        $devis->execute([$id]);
        $listeDevis = $devis->fetchAll();
        foreach ($listeDevis as &$dv) {
            $dv['lignes'] = self::decoderJson($dv['lignes']);
        }

        self::succes(['diagnostic' => $diagnostic, 'devis' => $listeDevis]);
    }

    // ----------------------------------------------------------
    // POST /diagnostics
    // Body: { vehicule_id, outil?, kilometrage?, codes_dtc?: [{code, description, statut}],
    //         commentaires?, photos?: [url], date_diagnostic? }
    // ----------------------------------------------------------
    public static function store(): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['vehicule_id']);

        $db = Database::connect();
        $vehicule = self::trouverOu404($db, 'vehicules', (int)$data['vehicule_id'], 'Véhicule');

        $codesDtc = $data['codes_dtc'] ?? [];
        if (!is_array($codesDtc)) self::erreur(400, 'codes_dtc doit être un tableau.');
        $photos = $data['photos'] ?? [];
        if (!is_array($photos)) self::erreur(400, 'photos doit être un tableau d\'URLs.');

        $reference = self::genererReference($db, 'diagnostics', 'DIAG');
        $km        = isset($data['kilometrage']) ? (int)$data['kilometrage'] : (int)$vehicule['kilometrage'];

        $stmt = $db->prepare(
            'INSERT INTO diagnostics (reference, vehicule_id, technicien_id, date_diagnostic,
                                      outil, kilometrage, codes_dtc, commentaires, photos, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "en_cours")'
        );
        $stmt->execute([
            $reference,
            (int)$data['vehicule_id'],
            $user['id'],
            $data['date_diagnostic'] ?? date('Y-m-d H:i:s'),
            $data['outil'] ?? null,
            $km,
            json_encode($codesDtc, JSON_UNESCAPED_UNICODE),
            $data['commentaires'] ?? null,
            json_encode($photos, JSON_UNESCAPED_UNICODE),
        ]);
        $diagnosticId = (int)$db->lastInsertId();

        // Mettre à jour le kilométrage du véhicule s'il a progressé
        if ($km > (int)$vehicule['kilometrage']) {
            $db->prepare('UPDATE vehicules SET kilometrage = ? WHERE id = ?')
               ->execute([$km, (int)$data['vehicule_id']]);
        }

        // Tracer le diagnostic dans le carnet d'entretien
        $db->prepare(
            'INSERT INTO carnet_entretien (vehicule_id, type_entretien, date_intervention, kilometrage, notes)
             VALUES (?, "diagnostic", CURDATE(), ?, ?)'
        )->execute([(int)$data['vehicule_id'], $km, "Diagnostic $reference — " . count($codesDtc) . ' code(s) DTC']);

        self::succes(['id' => $diagnosticId, 'reference' => $reference], 'Diagnostic enregistré.', 201);
    }

    // ----------------------------------------------------------
    // PUT /diagnostics/{id}
    // ----------------------------------------------------------
    public static function update(int $id): void {
        AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        $db   = Database::connect();

        self::trouverOu404($db, 'diagnostics', $id, 'Diagnostic');

        $set    = [];
        $params = [];
        foreach (['outil', 'kilometrage', 'commentaires', 'statut', 'date_diagnostic'] as $c) {
            if (array_key_exists($c, $data)) {
                if ($c === 'statut' && !in_array($data[$c], self::STATUTS)) {
                    self::erreur(400, 'Statut invalide. Valeurs : ' . implode(', ', self::STATUTS));
                }
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        foreach (['codes_dtc', 'photos'] as $c) {
            if (array_key_exists($c, $data)) {
                if (!is_array($data[$c])) self::erreur(400, "$c doit être un tableau.");
                $set[]    = "`$c` = ?";
                $params[] = json_encode($data[$c], JSON_UNESCAPED_UNICODE);
            }
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE diagnostics SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Diagnostic mis à jour.');
    }

    // ----------------------------------------------------------
    // POST /diagnostics/{id}/devis
    // Génère un devis depuis le diagnostic.
    // Body: { lignes: [{designation, montant}], main_oeuvre_pct?=10, notes?, validite_jours?=30 }
    // ----------------------------------------------------------
    public static function genererDevis(int $id): void {
        AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['lignes']);

        if (!is_array($data['lignes']) || !count($data['lignes'])) {
            self::erreur(400, 'lignes doit être un tableau non vide de {designation, montant}.');
        }

        $db = Database::connect();
        $diagnostic = self::trouverOu404($db, 'diagnostics', $id, 'Diagnostic');
        $vehicule   = self::trouverOu404($db, 'vehicules', (int)$diagnostic['vehicule_id'], 'Véhicule');

        $sousTotal = 0;
        $lignes    = [];
        foreach ($data['lignes'] as $l) {
            if (empty($l['designation']) || !isset($l['montant'])) {
                self::erreur(400, 'Chaque ligne doit contenir designation et montant.');
            }
            $montant = (float)$l['montant'];
            if ($montant < 0) self::erreur(400, 'Montant de ligne négatif interdit.');
            $lignes[]   = ['designation' => trim($l['designation']), 'montant' => $montant];
            $sousTotal += $montant;
        }

        $moPct      = isset($data['main_oeuvre_pct']) ? max(0, (int)$data['main_oeuvre_pct']) : 10;
        $mainOeuvre = round($sousTotal * $moPct / 100, 2);
        $total      = round($sousTotal + $mainOeuvre, 2);

        $reference = self::genererReference($db, 'devis', 'DEVIS-SAUT-' . date('Ymd'));

        $stmt = $db->prepare(
            'INSERT INTO devis (reference, diagnostic_id, client_id, vehicule_id, lignes,
                                sous_total, main_oeuvre_pct, main_oeuvre, total, statut, validite_jours, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "brouillon", ?, ?)'
        );
        $stmt->execute([
            $reference,
            $id,
            (int)$vehicule['client_id'],
            (int)$diagnostic['vehicule_id'],
            json_encode($lignes, JSON_UNESCAPED_UNICODE),
            $sousTotal,
            $moPct,
            $mainOeuvre,
            $total,
            max(1, (int)($data['validite_jours'] ?? 30)),
            $data['notes'] ?? null,
        ]);

        // Marquer le diagnostic
        $db->prepare("UPDATE diagnostics SET statut = 'terminé' WHERE id = ? AND statut = 'en_cours'")
           ->execute([$id]);

        self::succes([
            'id'         => (int)$db->lastInsertId(),
            'reference'  => $reference,
            'sous_total' => $sousTotal,
            'main_oeuvre'=> $mainOeuvre,
            'total'      => $total,
        ], 'Devis généré depuis le diagnostic.', 201);
    }
}

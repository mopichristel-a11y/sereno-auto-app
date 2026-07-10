<?php
// ============================================================
//  SERENO SMS — CarnetController (Module 4)
//  Carnet d'entretien numérique par véhicule
//  Calcul automatique de la prochaine intervention (km + date)
// ============================================================

require_once __DIR__ . '/../BaseController.php';
require_once __DIR__ . '/../vehicules/VehiculeController.php';

class CarnetController extends BaseController {

    // ----------------------------------------------------------
    // GET /carnet/types — types d'entretien + intervalles
    // ----------------------------------------------------------
    public static function types(): void {
        AuthMiddleware::authentifier();
        $types = [];
        foreach (VehiculeController::INTERVALLES as $type => [$km, $mois]) {
            $types[] = [
                'type'           => $type,
                'libelle'        => VehiculeController::LIBELLES[$type],
                'intervalle_km'  => $km,
                'intervalle_mois'=> $mois,
            ];
        }
        self::succes(['types' => $types]);
    }

    // ----------------------------------------------------------
    // GET /vehicules/{id}/carnet — historique complet + rappels
    // ----------------------------------------------------------
    public static function parVehicule(int $vehiculeId): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();
        $vehicule = self::trouverOu404($db, 'vehicules', $vehiculeId, 'Véhicule');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, $vehiculeId));

        $stmt = $db->prepare(
            "SELECT ce.*, i.reference AS intervention_reference
             FROM carnet_entretien ce
             LEFT JOIN interventions i ON i.id = ce.intervention_id
             WHERE ce.vehicule_id = ?
             ORDER BY ce.date_intervention DESC, ce.id DESC"
        );
        $stmt->execute([$vehiculeId]);
        $historique = $stmt->fetchAll();

        foreach ($historique as &$h) {
            $h['libelle'] = VehiculeController::LIBELLES[$h['type_entretien']] ?? $h['type_entretien'];
        }

        self::succes([
            'vehicule'   => [
                'id'          => $vehiculeId,
                'marque'      => $vehicule['marque'],
                'modele'      => $vehicule['modele'],
                'kilometrage' => (int)$vehicule['kilometrage'],
            ],
            'historique' => $historique,
            'rappels'    => VehiculeController::calculerRappels($db, $vehiculeId, (int)$vehicule['kilometrage']),
        ]);
    }

    // ----------------------------------------------------------
    // POST /carnet
    // Body: { vehicule_id, type_entretien, date_intervention?, kilometrage?,
    //         prochain_km?, prochaine_date?, notes?, intervention_id? }
    // prochain_km / prochaine_date calculés automatiquement si absents.
    // ----------------------------------------------------------
    public static function store(): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['vehicule_id', 'type_entretien']);

        $type = $data['type_entretien'];
        if (!isset(VehiculeController::INTERVALLES[$type])) {
            self::erreur(400, 'type_entretien invalide. Valeurs : ' .
                implode(', ', array_keys(VehiculeController::INTERVALLES)));
        }

        $db = Database::connect();
        $vehicule = self::trouverOu404($db, 'vehicules', (int)$data['vehicule_id'], 'Véhicule');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, (int)$data['vehicule_id']));

        $dateIntervention = $data['date_intervention'] ?? date('Y-m-d');
        if (!strtotime($dateIntervention)) self::erreur(400, 'date_intervention invalide.');

        $km = isset($data['kilometrage']) ? (int)$data['kilometrage'] : (int)$vehicule['kilometrage'];

        // Calcul automatique de la prochaine échéance
        [$kmIntervalle, $moisIntervalle] = VehiculeController::INTERVALLES[$type];
        $prochainKm = isset($data['prochain_km'])
            ? (int)$data['prochain_km']
            : ($kmIntervalle ? $km + $kmIntervalle : null);
        $prochaineDate = $data['prochaine_date']
            ?? ($moisIntervalle ? date('Y-m-d', strtotime("$dateIntervention +$moisIntervalle months")) : null);

        $stmt = $db->prepare(
            'INSERT INTO carnet_entretien (vehicule_id, intervention_id, type_entretien,
                                           date_intervention, kilometrage, prochain_km, prochaine_date, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$data['vehicule_id'],
            !empty($data['intervention_id']) ? (int)$data['intervention_id'] : null,
            $type,
            $dateIntervention,
            $km,
            $prochainKm,
            $prochaineDate,
            $data['notes'] ?? null,
        ]);

        // Mettre à jour le kilométrage du véhicule s'il a progressé
        if ($km > (int)$vehicule['kilometrage']) {
            $db->prepare('UPDATE vehicules SET kilometrage = ? WHERE id = ?')
               ->execute([$km, (int)$data['vehicule_id']]);
        }

        self::succes([
            'id'             => (int)$db->lastInsertId(),
            'prochain_km'    => $prochainKm,
            'prochaine_date' => $prochaineDate,
        ], 'Entretien ajouté au carnet.', 201);
    }

    // ----------------------------------------------------------
    // PUT /carnet/{id}
    // ----------------------------------------------------------
    public static function update(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        $db   = Database::connect();

        $entree = self::trouverOu404($db, 'carnet_entretien', $id, 'Entrée du carnet');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, (int)$entree['vehicule_id']));

        $set    = [];
        $params = [];
        foreach (['date_intervention', 'kilometrage', 'prochain_km', 'prochaine_date', 'notes'] as $c) {
            if (array_key_exists($c, $data)) {
                $set[]    = "`$c` = ?";
                $params[] = $data[$c];
            }
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE carnet_entretien SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Carnet mis à jour.');
    }

    // ----------------------------------------------------------
    // DELETE /carnet/{id}  (admin)
    // ----------------------------------------------------------
    public static function destroy(int $id): void {
        $user = AuthMiddleware::adminSeulement();
        $db = Database::connect();
        $entree = self::trouverOu404($db, 'carnet_entretien', $id, 'Entrée du carnet');
        self::verifierGarage(self::garageDe($user), self::garageDuVehicule($db, (int)$entree['vehicule_id']));
        $db->prepare('DELETE FROM carnet_entretien WHERE id = ?')->execute([$id]);
        self::succes([], 'Entrée supprimée du carnet.');
    }

    // ----------------------------------------------------------
    // GET /carnet/rappels?urgence=&limite=50
    // Tous les rappels à venir, tous véhicules confondus.
    // ----------------------------------------------------------
    public static function rappels(): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $garage       = self::garageDe($user);
        $filtreGarage = $garage !== null ? 'WHERE c.garage_id = ' . $garage : '';

        $vehicules = $db->query(
            "SELECT v.id, v.marque, v.modele, v.immatriculation, v.kilometrage,
                    c.id AS client_id, c.nom AS client_nom, c.telephone
             FROM vehicules v JOIN clients c ON c.id = v.client_id
             $filtreGarage"
        )->fetchAll();

        $filtreUrgence = $_GET['urgence'] ?? null;
        $limite = min(200, max(1, (int)($_GET['limite'] ?? 50)));

        $tous = [];
        foreach ($vehicules as $v) {
            $rappels = VehiculeController::calculerRappels($db, (int)$v['id'], (int)$v['kilometrage']);
            foreach ($rappels as $r) {
                if ($r['urgence'] === 'inconnu' || $r['urgence'] === 'ok') continue;
                if ($filtreUrgence && $r['urgence'] !== $filtreUrgence) continue;
                $tous[] = array_merge($r, [
                    'vehicule_id' => (int)$v['id'],
                    'vehicule'    => $v['marque'] . ' ' . $v['modele'],
                    'client_id'   => (int)$v['client_id'],
                    'client_nom'  => $v['client_nom'],
                    'telephone'   => $v['telephone'],
                ]);
            }
        }

        // Les plus urgents d'abord
        $ordre = ['depasse' => 0, 'urgent' => 1, 'bientot' => 2];
        usort($tous, function ($a, $b) use ($ordre) {
            $cmp = $ordre[$a['urgence']] <=> $ordre[$b['urgence']];
            if ($cmp !== 0) return $cmp;
            return ($a['jours_restants'] ?? PHP_INT_MAX) <=> ($b['jours_restants'] ?? PHP_INT_MAX);
        });

        self::succes(['rappels' => array_slice($tous, 0, $limite), 'total' => count($tous)]);
    }
}

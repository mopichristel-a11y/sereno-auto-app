<?php
// ============================================================
//  SERENO SMS — RdvController
//  Rendez-vous : liste du jour, planification, statuts
// ============================================================

require_once __DIR__ . '/../BaseController.php';

class RdvController extends BaseController {

    private const STATUTS = ['confirmé', 'en_attente', 'annulé', 'terminé'];

    // ----------------------------------------------------------
    // GET /rdv?date=2026-07-08 | ?date=today | ?a_venir=1
    // ----------------------------------------------------------
    public static function index(): void {
        AuthMiddleware::equipeInterne();
        $db = Database::connect();

        $where  = [];
        $params = [];

        if (!empty($_GET['date'])) {
            $date = $_GET['date'] === 'today' ? date('Y-m-d') : $_GET['date'];
            if (!strtotime($date)) self::erreur(400, 'date invalide.');
            $where[] = 'DATE(r.date_rdv) = :date';
            $params[':date'] = $date;
        } elseif (!empty($_GET['a_venir'])) {
            $where[] = 'r.date_rdv >= NOW()';
        }
        if (!empty($_GET['statut'])) {
            $where[] = 'r.statut = :statut';
            $params[':statut'] = $_GET['statut'];
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare(
            "SELECT r.*, c.nom AS client_nom, c.telephone,
                    v.marque, v.modele, v.immatriculation
             FROM rendez_vous r
             JOIN clients c   ON c.id = r.client_id
             JOIN vehicules v ON v.id = r.vehicule_id
             $sqlWhere
             ORDER BY r.date_rdv ASC
             LIMIT 100"
        );
        $stmt->execute($params);

        self::succes(['rdv' => $stmt->fetchAll()]);
    }

    // ----------------------------------------------------------
    // POST /rdv
    // Body: { client_id, vehicule_id, date_rdv, motif?, statut?, notes? }
    // ----------------------------------------------------------
    public static function store(): void {
        AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['client_id', 'vehicule_id', 'date_rdv']);

        if (!strtotime($data['date_rdv'])) self::erreur(400, 'date_rdv invalide (AAAA-MM-JJ HH:MM).');

        $statut = $data['statut'] ?? 'en_attente';
        if (!in_array($statut, self::STATUTS)) self::erreur(400, 'Statut invalide.');

        $db = Database::connect();
        self::trouverOu404($db, 'clients', (int)$data['client_id'], 'Client');
        $vehicule = self::trouverOu404($db, 'vehicules', (int)$data['vehicule_id'], 'Véhicule');
        if ((int)$vehicule['client_id'] !== (int)$data['client_id']) {
            self::erreur(400, 'Ce véhicule n\'appartient pas à ce client.');
        }

        $db->prepare(
            'INSERT INTO rendez_vous (client_id, vehicule_id, date_rdv, motif, statut, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)$data['client_id'],
            (int)$data['vehicule_id'],
            date('Y-m-d H:i:s', strtotime($data['date_rdv'])),
            $data['motif'] ?? null,
            $statut,
            $data['notes'] ?? null,
        ]);

        self::succes(['id' => (int)$db->lastInsertId()], 'Rendez-vous créé.', 201);
    }

    // ----------------------------------------------------------
    // PUT /rdv/{id}
    // ----------------------------------------------------------
    public static function update(int $id): void {
        AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        $db   = Database::connect();

        self::trouverOu404($db, 'rendez_vous', $id, 'Rendez-vous');

        $set    = [];
        $params = [];
        foreach (['date_rdv', 'motif', 'statut', 'notes'] as $c) {
            if (array_key_exists($c, $data)) {
                if ($c === 'statut' && !in_array($data[$c], self::STATUTS)) {
                    self::erreur(400, 'Statut invalide.');
                }
                $set[]    = "`$c` = ?";
                $params[] = $c === 'date_rdv' ? date('Y-m-d H:i:s', strtotime($data[$c])) : $data[$c];
            }
        }
        if (!$set) self::erreur(400, 'Aucun champ à mettre à jour.');

        $params[] = $id;
        $db->prepare('UPDATE rendez_vous SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        self::succes([], 'Rendez-vous mis à jour.');
    }

    // ----------------------------------------------------------
    // DELETE /rdv/{id}
    // ----------------------------------------------------------
    public static function destroy(int $id): void {
        AuthMiddleware::adminOuCommercial();
        $db = Database::connect();
        self::trouverOu404($db, 'rendez_vous', $id, 'Rendez-vous');
        $db->prepare('DELETE FROM rendez_vous WHERE id = ?')->execute([$id]);
        self::succes([], 'Rendez-vous supprimé.');
    }
}

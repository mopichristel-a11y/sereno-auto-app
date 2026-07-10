<?php
// ============================================================
//  SERENO SMS — NotificationController (Module 6)
//  Liste, création manuelle, marquage lu, envoi immédiat
// ============================================================

require_once __DIR__ . '/../BaseController.php';
require_once __DIR__ . '/../../services/NotificationService.php';

class NotificationController extends BaseController {

    private const TYPES = ['vidange', 'filtre', 'pneus', 'batterie', 'assurance',
                           'visite_technique', 'csa_expiration', 'paiement', 'autre'];

    // ----------------------------------------------------------
    // GET /notifications?lu=0&type=&page=
    // ----------------------------------------------------------
    public static function index(): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();
        [$page, $limite, $offset] = self::pagination();

        $where  = [];
        $params = [];

        $garage = self::garageDe($user);
        if ($garage !== null) {
            $where[] = 'n.garage_id = :garage';
            $params[':garage'] = $garage;
        }
        if (isset($_GET['lu']) && $_GET['lu'] !== '') {
            $where[] = 'n.lu = :lu';
            $params[':lu'] = (int)$_GET['lu'];
        }
        if (!empty($_GET['type'])) {
            $where[] = 'n.type = :type';
            $params[':type'] = $_GET['type'];
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $db->prepare("SELECT COUNT(*) FROM notifications n $sqlWhere");
        $total->execute($params);
        $nbTotal = (int)$total->fetchColumn();

        $nonLues = (int)$db->query(
            'SELECT COUNT(*) FROM notifications WHERE lu = 0'
            . ($garage !== null ? " AND garage_id = $garage" : '')
        )->fetchColumn();

        $stmt = $db->prepare(
            "SELECT n.*, c.nom AS client_nom, CONCAT(u.prenom, ' ', u.nom) AS destinataire_nom
             FROM notifications n
             LEFT JOIN clients c      ON c.id = n.client_id
             LEFT JOIN utilisateurs u ON u.id = n.destinataire_id
             $sqlWhere
             ORDER BY n.created_at DESC
             LIMIT $limite OFFSET $offset"
        );
        $stmt->execute($params);

        self::succes([
            'notifications' => $stmt->fetchAll(),
            'non_lues'      => $nonLues,
            'total'         => $nbTotal,
            'page'          => $page,
            'pages'         => (int)ceil($nbTotal / $limite),
        ]);
    }

    // ----------------------------------------------------------
    // POST /notifications — création manuelle
    // Body: { type, titre, message, client_id? | destinataire_id?,
    //         canal?="app", envoyer_maintenant?=false }
    // ----------------------------------------------------------
    public static function store(): void {
        $user = AuthMiddleware::equipeInterne();
        $data = self::bodyJson();
        self::requis($data, ['type', 'titre', 'message']);

        if (!in_array($data['type'], self::TYPES)) {
            self::erreur(400, 'Type invalide. Valeurs : ' . implode(', ', self::TYPES));
        }
        if (empty($data['client_id']) && empty($data['destinataire_id'])) {
            self::erreur(400, 'Précisez client_id ou destinataire_id.');
        }

        $db = Database::connect();
        if (!empty($data['client_id'])) {
            $client = self::trouverOu404($db, 'clients', (int)$data['client_id'], 'Client');
            self::verifierGarage(self::garageDe($user), $client['garage_id']);
        }
        if (!empty($data['destinataire_id'])) {
            self::trouverOu404($db, 'utilisateurs', (int)$data['destinataire_id'], 'Utilisateur');
        }

        $id = NotificationService::enfiler(
            $db,
            $data['type'],
            trim($data['titre']),
            trim($data['message']),
            !empty($data['client_id']) ? (int)$data['client_id'] : null,
            !empty($data['destinataire_id']) ? (int)$data['destinataire_id'] : null,
            $data['canal'] ?? 'app'
        );

        $resultatEnvoi = null;
        if (!empty($data['envoyer_maintenant'])) {
            $resultatEnvoi = NotificationService::traiterFile($db, 10);
        }

        self::succes(
            array_filter(['id' => $id, 'envoi' => $resultatEnvoi]),
            'Notification créée' . (!empty($data['envoyer_maintenant']) ? ' et file traitée.' : ' (en file d\'attente).'),
            201
        );
    }

    // ----------------------------------------------------------
    // POST /notifications/{id}/lu
    // ----------------------------------------------------------
    public static function marquerLu(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();
        $notification = self::trouverOu404($db, 'notifications', $id, 'Notification');
        self::verifierGarage(self::garageDe($user), $notification['garage_id']);
        $db->prepare('UPDATE notifications SET lu = 1 WHERE id = ?')->execute([$id]);
        self::succes([], 'Notification marquée lue.');
    }

    // ----------------------------------------------------------
    // POST /notifications/tout-lu
    // ----------------------------------------------------------
    public static function toutLu(): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();
        $garage = self::garageDe($user);
        $db->exec('UPDATE notifications SET lu = 1 WHERE lu = 0'
                  . ($garage !== null ? " AND garage_id = $garage" : ''));
        self::succes([], 'Toutes les notifications sont marquées lues.');
    }

    // ----------------------------------------------------------
    // POST /notifications/{id}/envoyer — renvoi immédiat
    // ----------------------------------------------------------
    public static function envoyer(int $id): void {
        $user = AuthMiddleware::equipeInterne();
        $db = Database::connect();
        $notification = self::trouverOu404($db, 'notifications', $id, 'Notification');
        self::verifierGarage(self::garageDe($user), $notification['garage_id']);

        // Repasser en file puis traiter
        $db->prepare('UPDATE notifications SET envoye = 0 WHERE id = ?')->execute([$id]);
        $resultat = NotificationService::traiterFile($db, 100);

        self::succes(['envoi' => $resultat], 'Envoi déclenché.');
    }
}

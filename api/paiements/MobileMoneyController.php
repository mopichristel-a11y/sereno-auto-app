<?php
// ============================================================
//  SERENO SMS — MobileMoneyController
//  Paiement en ligne des mensualités CSA :
//  Orange Money (page de paiement web) & MTN MoMo (push USSD).
// ============================================================

require_once __DIR__ . '/../BaseController.php';
require_once __DIR__ . '/../../services/MobileMoneyService.php';

class MobileMoneyController extends BaseController {

    /**
     * Autorise l'équipe interne, ou le client connecté
     * s'il est titulaire du contrat.
     */
    private static function autoriserSurContrat(PDO $db, array $contrat): array {
        $user = AuthMiddleware::utilisateurCourant() ?? AuthMiddleware::authentifier();
        if (in_array($user['role'], ['admin', 'commercial', 'technicien'])) {
            return $user;
        }
        $stmt = $db->prepare('SELECT id FROM clients WHERE utilisateur_id = ? AND id = ?');
        $stmt->execute([$user['id'], (int)$contrat['client_id']]);
        if (!$stmt->fetch()) {
            self::erreur(403, 'Ce contrat ne vous appartient pas.');
        }
        return $user;
    }

    // ----------------------------------------------------------
    // POST /paiements/mobile/initier
    // Body: { contrat_id, montant, operateur: orange|mtn, telephone? }
    // → crée un paiement en_attente + lance la transaction.
    //   Orange : retourne payment_url (redirection navigateur)
    //   MTN    : push de validation sur le téléphone du payeur
    // ----------------------------------------------------------
    public static function initier(): void {
        AuthMiddleware::authentifier(); // avant toute lecture du corps
        $data = self::bodyJson();
        self::requis($data, ['contrat_id', 'montant', 'operateur']);

        if (!in_array($data['operateur'], ['orange', 'mtn'])) {
            self::erreur(400, 'operateur doit valoir orange ou mtn.');
        }
        $montant = (float)$data['montant'];
        if ($montant <= 0) self::erreur(400, 'Montant invalide.');

        $db      = Database::connect();
        $contrat = self::trouverOu404($db, 'contrats_csa', (int)$data['contrat_id'], 'Contrat');
        if ($contrat['statut'] === 'résilié') {
            self::erreur(409, 'Contrat résilié : paiement impossible.');
        }
        self::autoriserSurContrat($db, $contrat);

        // Téléphone payeur : fourni, sinon celui de la fiche client
        $telephone = trim($data['telephone'] ?? '');
        if (!$telephone) {
            $stmt = $db->prepare('SELECT telephone FROM clients WHERE id = ?');
            $stmt->execute([(int)$contrat['client_id']]);
            $telephone = (string)$stmt->fetchColumn();
        }
        if ($data['operateur'] === 'mtn' && !$telephone) {
            self::erreur(400, 'Numéro de téléphone requis pour MTN MoMo.');
        }

        // Référence interne unique de la transaction
        $orderId = 'SAUT-' . $contrat['reference'] . '-' . date('ymdHis');

        try {
            if ($data['operateur'] === 'orange') {
                $transaction = MobileMoneyService::initierOrange($montant, $orderId);
                $detailsOperateur = json_encode([
                    'operateur' => 'orange',
                    'pay_token' => $transaction['pay_token'],
                ], JSON_UNESCAPED_UNICODE);
                $moyen = 'orange_money';
            } else {
                $referenceMomo = MobileMoneyService::initierMomo($montant, $telephone, $orderId);
                $transaction = ['reference_momo' => $referenceMomo];
                $detailsOperateur = json_encode([
                    'operateur'      => 'mtn',
                    'reference_momo' => $referenceMomo,
                ], JSON_UNESCAPED_UNICODE);
                $moyen = 'mtn_momo';
            }
        } catch (RuntimeException $e) {
            self::erreur(502, $e->getMessage());
        }

        $db->prepare(
            'INSERT INTO paiements (contrat_id, montant, date_paiement, moyen, reference_paiement, statut, notes)
             VALUES (?, ?, CURDATE(), ?, ?, "en_attente", ?)'
        )->execute([
            (int)$data['contrat_id'], $montant, $moyen, $orderId, $detailsOperateur,
        ]);
        $paiementId = (int)$db->lastInsertId();

        self::succes([
            'paiement_id' => $paiementId,
            'order_id'    => $orderId,
            'operateur'   => $data['operateur'],
            'payment_url' => $transaction['payment_url'] ?? null,
            'message'     => $data['operateur'] === 'orange'
                ? 'Redirigez le payeur vers payment_url pour finaliser.'
                : 'Demande envoyée : le client valide sur son téléphone (' . $telephone . ').',
        ], 'Transaction initiée.', 201);
    }

    // ----------------------------------------------------------
    // GET /paiements/mobile/statut/{id}
    // Interroge l'opérateur et met à jour le paiement.
    // ----------------------------------------------------------
    public static function statut(int $paiementId): void {
        AuthMiddleware::authentifier();
        $db       = Database::connect();
        $paiement = self::trouverOu404($db, 'paiements', $paiementId, 'Paiement');
        $contrat  = self::trouverOu404($db, 'contrats_csa', (int)$paiement['contrat_id'], 'Contrat');
        self::autoriserSurContrat($db, $contrat);

        $details = self::decoderJson($paiement['notes']);
        if (empty($details['operateur'])) {
            self::erreur(400, 'Ce paiement n\'est pas une transaction mobile.');
        }

        if ($paiement['statut'] !== 'en_attente') {
            self::succes(['statut' => $paiement['statut'], 'final' => true]);
        }

        try {
            $statutOperateur = $details['operateur'] === 'orange'
                ? MobileMoneyService::statutOrange(
                    $paiement['reference_paiement'],
                    (float)$paiement['montant'],
                    $details['pay_token'] ?? ''
                  )
                : MobileMoneyService::statutMomo($details['reference_momo'] ?? '');
        } catch (RuntimeException $e) {
            self::erreur(502, $e->getMessage());
        }

        $statutInterne = MobileMoneyService::statutInterne($statutOperateur);
        if ($statutInterne !== 'en_attente') {
            $db->prepare('UPDATE paiements SET statut = ?, date_paiement = CURDATE() WHERE id = ?')
               ->execute([$statutInterne, $paiementId]);
        }

        self::succes([
            'statut'           => $statutInterne,
            'statut_operateur' => $statutOperateur,
            'final'            => $statutInterne !== 'en_attente',
        ]);
    }

    // ----------------------------------------------------------
    // POST /paiements/mobile/callback/orange   (webhook, sans auth)
    // Orange envoie { status, notif_token, txnid }.
    // On revérifie TOUJOURS le statut côté API avant validation.
    // ----------------------------------------------------------
    public static function callbackOrange(): void {
        $data = self::bodyJson();
        $db   = Database::connect();

        // Retrouver le paiement par le notif_token stocké... Orange ne renvoyant
        // pas l'order_id dans tous les cas, on accepte aussi ?ref=ORDER_ID.
        $orderId = $_GET['ref'] ?? $data['order_id'] ?? null;
        if (!$orderId) {
            // Réponse 200 pour éviter les retries infinis, mais rien à faire
            self::succes([], 'Callback reçu (aucune référence exploitable).');
        }

        $stmt = $db->prepare("SELECT * FROM paiements WHERE reference_paiement = ? AND moyen = 'orange_money' LIMIT 1");
        $stmt->execute([$orderId]);
        $paiement = $stmt->fetch();
        if (!$paiement || $paiement['statut'] !== 'en_attente') {
            self::succes([], 'Callback ignoré.');
        }

        $details = self::decoderJson($paiement['notes']);
        try {
            // Vérification serveur-à-serveur : ne jamais se fier au corps du webhook
            $statutOperateur = MobileMoneyService::statutOrange(
                $orderId, (float)$paiement['montant'], $details['pay_token'] ?? ''
            );
        } catch (RuntimeException) {
            self::succes([], 'Vérification différée.');
        }

        $statutInterne = MobileMoneyService::statutInterne($statutOperateur);
        if ($statutInterne !== 'en_attente') {
            $db->prepare('UPDATE paiements SET statut = ?, date_paiement = CURDATE() WHERE id = ?')
               ->execute([$statutInterne, (int)$paiement['id']]);
        }

        self::succes(['statut' => $statutInterne], 'Callback traité.');
    }

    // ----------------------------------------------------------
    // POST /paiements/mobile/callback/mtn   (webhook, sans auth)
    // MoMo envoie le referenceId ; on revérifie côté API.
    // ----------------------------------------------------------
    public static function callbackMtn(): void {
        $data = self::bodyJson();
        $db   = Database::connect();

        $externalId = $data['externalId'] ?? null;   // = notre order_id
        if (!$externalId) {
            self::succes([], 'Callback reçu (aucune référence exploitable).');
        }

        $stmt = $db->prepare("SELECT * FROM paiements WHERE reference_paiement = ? AND moyen = 'mtn_momo' LIMIT 1");
        $stmt->execute([$externalId]);
        $paiement = $stmt->fetch();
        if (!$paiement || $paiement['statut'] !== 'en_attente') {
            self::succes([], 'Callback ignoré.');
        }

        $details = self::decoderJson($paiement['notes']);
        try {
            $statutOperateur = MobileMoneyService::statutMomo($details['reference_momo'] ?? '');
        } catch (RuntimeException) {
            self::succes([], 'Vérification différée.');
        }

        $statutInterne = MobileMoneyService::statutInterne($statutOperateur);
        if ($statutInterne !== 'en_attente') {
            $db->prepare('UPDATE paiements SET statut = ?, date_paiement = CURDATE() WHERE id = ?')
               ->execute([$statutInterne, (int)$paiement['id']]);
        }

        self::succes(['statut' => $statutInterne], 'Callback traité.');
    }
}

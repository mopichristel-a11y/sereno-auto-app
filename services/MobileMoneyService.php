<?php
// ============================================================
//  SERENO SMS — MobileMoneyService
//  Orange Money Web Payment (Cameroun) + MTN MoMo Collections
//  Sans dépendance : HTTP via cURL si présent, sinon streams.
// ============================================================

require_once __DIR__ . '/../config/database.php';

class MobileMoneyService {

    // ==========================================================
    //  ORANGE MONEY — Web Payment CM
    //  Docs : developer.orange.com → Orange Money Web Pay
    // ==========================================================

    /** Jeton OAuth Orange (mis en cache le temps de la requête). */
    private static ?string $tokenOrange = null;

    private static function tokenOrange(): string {
        if (self::$tokenOrange) return self::$tokenOrange;
        if (!OM_CONSUMER_KEY) {
            throw new RuntimeException('Orange Money non configuré (OM_CONSUMER_KEY manquant).');
        }

        $reponse = self::http('POST', OM_TOKEN_URL, [
            'Authorization: Basic ' . OM_CONSUMER_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');

        if (empty($reponse['access_token'])) {
            throw new RuntimeException('Orange Money : authentification échouée.');
        }
        return self::$tokenOrange = $reponse['access_token'];
    }

    /**
     * Initie un paiement Orange Money Web Payment.
     * Retourne : [payment_url, pay_token, notif_token, order_id]
     */
    public static function initierOrange(float $montant, string $orderId): array {
        $token = self::tokenOrange();

        $reponse = self::http('POST', OM_WEBPAY_URL, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ], json_encode([
            'merchant_key' => OM_MERCHANT_KEY,
            'currency'     => 'XAF',
            'order_id'     => $orderId,
            'amount'       => (int)round($montant),
            'return_url'   => APP_URL . '/public/paiement-retour.html?statut=ok&ref=' . $orderId,
            'cancel_url'   => APP_URL . '/public/paiement-retour.html?statut=annule&ref=' . $orderId,
            'notif_url'    => APP_URL . '/api/paiements/mobile/callback/orange',
            'lang'         => 'fr',
            'reference'    => 'SERENO AUTO',
        ]));

        if (empty($reponse['payment_url'])) {
            throw new RuntimeException('Orange Money : initiation refusée — ' .
                ($reponse['message'] ?? json_encode($reponse)));
        }

        return [
            'payment_url' => $reponse['payment_url'],
            'pay_token'   => $reponse['pay_token']   ?? null,
            'notif_token' => $reponse['notif_token'] ?? null,
            'order_id'    => $orderId,
        ];
    }

    /**
     * Vérifie le statut d'une transaction Orange.
     * Statuts Orange : INITIATED | PENDING | SUCCESS | FAILED | EXPIRED
     */
    public static function statutOrange(string $orderId, float $montant, string $payToken): string {
        $token = self::tokenOrange();

        $reponse = self::http('POST', OM_STATUS_URL, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ], json_encode([
            'order_id'  => $orderId,
            'amount'    => (int)round($montant),
            'pay_token' => $payToken,
        ]));

        return strtoupper($reponse['status'] ?? 'PENDING');
    }

    // ==========================================================
    //  MTN MoMo — Collections (requesttopay)
    //  Docs : momodeveloper.mtn.com
    // ==========================================================

    private static ?string $tokenMomo = null;

    private static function tokenMomo(): string {
        if (self::$tokenMomo) return self::$tokenMomo;
        if (!MOMO_SUBSCRIPTION_KEY) {
            throw new RuntimeException('MTN MoMo non configuré (MOMO_SUBSCRIPTION_KEY manquant).');
        }

        $reponse = self::http('POST', MOMO_BASE_URL . '/collection/token/', [
            'Authorization: Basic ' . base64_encode(MOMO_API_USER . ':' . MOMO_API_KEY),
            'Ocp-Apim-Subscription-Key: ' . MOMO_SUBSCRIPTION_KEY,
        ], '');

        if (empty($reponse['access_token'])) {
            throw new RuntimeException('MTN MoMo : authentification échouée.');
        }
        return self::$tokenMomo = $reponse['access_token'];
    }

    /**
     * Initie un requesttopay MoMo (le client valide sur son téléphone).
     * Retourne l'UUID de référence MoMo.
     */
    public static function initierMomo(float $montant, string $telephone, string $orderId): string {
        $token       = self::tokenMomo();
        $referenceId = self::uuid4();
        $msisdn      = preg_replace('/[^0-9]/', '', $telephone);

        // La sandbox n'accepte que la devise EUR
        $devise = MOMO_ENVIRONMENT === 'sandbox' ? 'EUR' : 'XAF';

        self::http('POST', MOMO_BASE_URL . '/collection/v1_0/requesttopay', [
            'Authorization: Bearer ' . $token,
            'X-Reference-Id: ' . $referenceId,
            'X-Target-Environment: ' . MOMO_ENVIRONMENT,
            'Ocp-Apim-Subscription-Key: ' . MOMO_SUBSCRIPTION_KEY,
            'Content-Type: application/json',
        ], json_encode([
            'amount'       => (string)(int)round($montant),
            'currency'     => $devise,
            'externalId'   => $orderId,
            'payer'        => ['partyIdType' => 'MSISDN', 'partyId' => $msisdn],
            'payerMessage' => 'Mensualite contrat SERENO AUTO',
            'payeeNote'    => $orderId,
        ]), 202); // MoMo répond 202 Accepted sans corps

        return $referenceId;
    }

    /**
     * Statut d'un requesttopay MoMo.
     * Statuts MoMo : PENDING | SUCCESSFUL | FAILED
     */
    public static function statutMomo(string $referenceId): string {
        $token = self::tokenMomo();

        $reponse = self::http('GET', MOMO_BASE_URL . '/collection/v1_0/requesttopay/' . $referenceId, [
            'Authorization: Bearer ' . $token,
            'X-Target-Environment: ' . MOMO_ENVIRONMENT,
            'Ocp-Apim-Subscription-Key: ' . MOMO_SUBSCRIPTION_KEY,
        ]);

        return strtoupper($reponse['status'] ?? 'PENDING');
    }

    // ==========================================================
    //  Helpers
    // ==========================================================

    /** Statut opérateur → statut interne de la table paiements. */
    public static function statutInterne(string $statutOperateur): string {
        return match ($statutOperateur) {
            'SUCCESS', 'SUCCESSFUL'                  => 'payé',
            'FAILED', 'EXPIRED', 'REJECTED'          => 'échoué',
            default                                  => 'en_attente',
        };
    }

    public static function uuid4(): string {
        $octets = random_bytes(16);
        $octets[6] = chr((ord($octets[6]) & 0x0f) | 0x40);
        $octets[8] = chr((ord($octets[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($octets), 4));
    }

    /**
     * Requête HTTP JSON. Lève RuntimeException si le code HTTP
     * ne correspond pas à un succès (2xx ou $codeAttendu).
     */
    private static function http(string $methode, string $url, array $entetes, ?string $corps = null, ?int $codeAttendu = null): array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $methode,
                CURLOPT_HTTPHEADER     => $entetes,
                CURLOPT_TIMEOUT        => 30,
            ]);
            if ($corps !== null && $methode !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
            }
            $brut = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($brut === false) {
                throw new RuntimeException("Connexion opérateur impossible : $err");
            }
        } else {
            $contexte = stream_context_create(['http' => [
                'method'        => $methode,
                'header'        => implode("\r\n", $entetes),
                'content'       => $corps ?? '',
                'timeout'       => 30,
                'ignore_errors' => true,
            ]]);
            $brut = @file_get_contents($url, false, $contexte);
            if ($brut === false) {
                throw new RuntimeException('Connexion opérateur impossible.');
            }
            preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
            $code = (int)($m[1] ?? 0);
        }

        $ok = ($code >= 200 && $code < 300) || ($codeAttendu !== null && $code === $codeAttendu);
        $json = json_decode($brut, true) ?? [];
        if (!$ok) {
            throw new RuntimeException(
                "Opérateur HTTP $code : " . ($json['message'] ?? $json['error'] ?? mb_substr($brut, 0, 200))
            );
        }
        return $json;
    }
}

<?php
// ============================================================
//  SERENO SMS — Configuration base de données & application
// ============================================================

define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_NAME',     getenv('DB_NAME')     ?: 'sereno_sms');
define('DB_USER',     getenv('DB_USER')     ?: 'root');
define('DB_PASS',     getenv('DB_PASS')     ?: '');
define('DB_CHARSET',  'utf8mb4');

// JWT
define('JWT_SECRET',  getenv('JWT_SECRET')  ?: 'CHANGEZ_CE_SECRET_EN_PRODUCTION_32chars+');
define('JWT_EXPIRE',  60 * 60);           // Access token : 1 heure
define('JWT_REFRESH', 60 * 60 * 24 * 30); // Refresh token : 30 jours

// App
define('APP_NAME',    'Sereno SMS');
define('APP_URL',     getenv('APP_URL') ?: 'https://votre-domaine.com');
define('APP_ENV',     getenv('APP_ENV') ?: 'production');

// ------------------------------------------------------------
// Notifications — canaux d'envoi
// ------------------------------------------------------------
// WhatsApp via CallMeBot (gratuit, 1 numéro) ou passerelle compatible.
// Laisser vide pour désactiver le canal.
define('WHATSAPP_API_URL', getenv('WHATSAPP_API_URL') ?: 'https://api.callmebot.com/whatsapp.php');
define('WHATSAPP_API_KEY', getenv('WHATSAPP_API_KEY') ?: '');

// SMS via passerelle HTTP générique (ex: Nexah, SMSVas, Twilio...)
define('SMS_API_URL',   getenv('SMS_API_URL')   ?: '');
define('SMS_API_USER',  getenv('SMS_API_USER')  ?: '');
define('SMS_API_PASS',  getenv('SMS_API_PASS')  ?: '');
define('SMS_SENDER_ID', getenv('SMS_SENDER_ID') ?: 'SERENO');

// Email (fonction mail() PHP — fonctionne nativement sur Hostinger)
define('MAIL_FROM',      getenv('MAIL_FROM')      ?: 'contact@sereno-auto.cm');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'SERENO AUTO');

// Téléphone du garage (affiché dans les messages)
define('SERENO_TELEPHONE', getenv('SERENO_TELEPHONE') ?: '+237 6XX XXX XXX');

// ------------------------------------------------------------
// Paiements mobiles — Orange Money Web Payment (Cameroun)
// developer.orange.com → créer une app "Orange Money Web Pay"
// OM_CONSUMER_KEY = base64(client_id:client_secret)
// ------------------------------------------------------------
define('OM_CONSUMER_KEY', getenv('OM_CONSUMER_KEY') ?: '');
define('OM_MERCHANT_KEY', getenv('OM_MERCHANT_KEY') ?: '');
define('OM_TOKEN_URL',    getenv('OM_TOKEN_URL')    ?: 'https://api.orange.com/oauth/v3/token');
define('OM_WEBPAY_URL',   getenv('OM_WEBPAY_URL')   ?: 'https://api.orange.com/orange-money-webpay/cm/v1/webpayment');
define('OM_STATUS_URL',   getenv('OM_STATUS_URL')   ?: 'https://api.orange.com/orange-money-webpay/cm/v1/transactionstatus');

// ------------------------------------------------------------
// Paiements mobiles — MTN MoMo Collections
// momodeveloper.mtn.com → souscrire au produit "Collections"
// ------------------------------------------------------------
define('MOMO_SUBSCRIPTION_KEY', getenv('MOMO_SUBSCRIPTION_KEY') ?: '');
define('MOMO_API_USER',         getenv('MOMO_API_USER')         ?: '');
define('MOMO_API_KEY',          getenv('MOMO_API_KEY')          ?: '');
define('MOMO_ENVIRONMENT',      getenv('MOMO_ENVIRONMENT')      ?: 'sandbox'); // sandbox | mtncameroon
define('MOMO_BASE_URL',         getenv('MOMO_BASE_URL')         ?: 'https://sandbox.momodeveloper.mtn.com');

class Database {
    private static ?PDO $instance = null;

    public static function connect(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                die(json_encode(['erreur' => 'Connexion base de données impossible']));
            }
        }
        return self::$instance;
    }
}

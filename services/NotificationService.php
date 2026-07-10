<?php
// ============================================================
//  SERENO SMS — NotificationService (Module 6)
//  Création en file d'attente + envoi WhatsApp / SMS / Email
//  L'envoi réel est déclenché par cron/rappels.php ou à la demande.
// ============================================================

require_once __DIR__ . '/../config/database.php';

class NotificationService {

    /**
     * Met une notification en file d'attente (envoye = 0).
     * $cleUnique évite les doublons de rappels automatiques
     * (ex: "csa_expiration-42-J30"). Retourne l'id ou null si doublon.
     */
    public static function enfiler(
        PDO $db,
        string $type,
        string $titre,
        string $message,
        ?int $clientId = null,
        ?int $destinataireId = null,
        string $canal = 'app',
        ?string $cleUnique = null
    ): ?int {
        if ($cleUnique) {
            $test = $db->prepare('SELECT id FROM notifications WHERE cle_unique = ?');
            $test->execute([$cleUnique]);
            if ($test->fetch()) return null; // déjà créée
        }

        // Tenant : garage du client, sinon du destinataire, sinon garage fondateur
        $garageId = 1;
        if ($clientId) {
            $g = $db->prepare('SELECT garage_id FROM clients WHERE id = ?');
            $g->execute([$clientId]);
            $garageId = (int)($g->fetchColumn() ?: 1);
        } elseif ($destinataireId) {
            $g = $db->prepare('SELECT garage_id FROM utilisateurs WHERE id = ?');
            $g->execute([$destinataireId]);
            $garageId = (int)($g->fetchColumn() ?: 1);
        }

        $stmt = $db->prepare(
            'INSERT INTO notifications (garage_id, destinataire_id, client_id, type, titre, message, canal, cle_unique)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$garageId, $destinataireId, $clientId, $type, $titre, $message, $canal, $cleUnique]);
        return (int)$db->lastInsertId();
    }

    /**
     * Envoie les notifications en attente (envoye = 0) sur leurs canaux.
     * Retourne un résumé [envoyees, echecs].
     */
    public static function traiterFile(PDO $db, int $limite = 50): array {
        $stmt = $db->prepare(
            "SELECT n.*, c.telephone AS client_telephone, c.email AS client_email, c.nom AS client_nom,
                    u.telephone AS user_telephone, u.email AS user_email
             FROM notifications n
             LEFT JOIN clients c      ON c.id = n.client_id
             LEFT JOIN utilisateurs u ON u.id = n.destinataire_id
             WHERE n.envoye = 0
             ORDER BY n.created_at ASC
             LIMIT ?"
        );
        $stmt->execute([$limite]);
        $notifications = $stmt->fetchAll();

        $envoyees = 0;
        $echecs   = 0;

        foreach ($notifications as $n) {
            $canaux    = explode(',', $n['canal'] ?: 'app');
            $telephone = $n['client_telephone'] ?: $n['user_telephone'];
            $email     = $n['client_email'] ?: $n['user_email'];
            $erreurs   = [];

            foreach ($canaux as $canal) {
                $ok = match (trim($canal)) {
                    'whatsapp' => $telephone ? self::envoyerWhatsApp($telephone, $n['titre'] . "\n" . $n['message']) : false,
                    'sms'      => $telephone ? self::envoyerSms($telephone, $n['titre'] . ' — ' . $n['message']) : false,
                    'email'    => $email ? self::envoyerEmail($email, $n['titre'], $n['message']) : false,
                    'app'      => true, // notification interne : déjà en base
                    default    => false,
                };
                if (!$ok) $erreurs[] = trim($canal);
            }

            // Marquée envoyée si au moins le canal app a fonctionné ;
            // les canaux externes en échec sont tracés dans erreur_envoi.
            $succesGlobal = count($erreurs) < count($canaux);
            $db->prepare(
                'UPDATE notifications SET envoye = ?, date_envoi = NOW(), erreur_envoi = ? WHERE id = ?'
            )->execute([
                $succesGlobal ? 1 : 0,
                $erreurs ? ('Échec canaux : ' . implode(', ', $erreurs)) : null,
                $n['id'],
            ]);

            $succesGlobal ? $envoyees++ : $echecs++;
        }

        return ['envoyees' => $envoyees, 'echecs' => $echecs, 'traitees' => count($notifications)];
    }

    // ----------------------------------------------------------
    //  Canaux d'envoi
    // ----------------------------------------------------------

    /** WhatsApp via CallMeBot (ou passerelle compatible). */
    public static function envoyerWhatsApp(string $telephone, string $message): bool {
        if (!WHATSAPP_API_KEY || !WHATSAPP_API_URL) return false;

        $url = WHATSAPP_API_URL . '?' . http_build_query([
            'phone'  => preg_replace('/[^0-9+]/', '', $telephone),
            'text'   => $message,
            'apikey' => WHATSAPP_API_KEY,
        ]);
        return self::requeteHttp($url);
    }

    /** SMS via passerelle HTTP générique. */
    public static function envoyerSms(string $telephone, string $message): bool {
        if (!SMS_API_URL) return false;

        $url = SMS_API_URL . '?' . http_build_query([
            'user'     => SMS_API_USER,
            'password' => SMS_API_PASS,
            'senderid' => SMS_SENDER_ID,
            'sms'      => mb_substr($message, 0, 160),
            'mobiles'  => preg_replace('/[^0-9+]/', '', $telephone),
        ]);
        return self::requeteHttp($url);
    }

    /** Email via mail() PHP (natif sur Hostinger). */
    public static function envoyerEmail(string $destinataire, string $sujet, string $message): bool {
        $entetes = implode("\r\n", [
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'Reply-To: ' . MAIL_FROM,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ]);

        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;">'
              . '<div style="background:#0D1B2A;padding:18px;border-radius:8px 8px 0 0;">'
              . '<span style="color:#fff;font-size:18px;font-weight:bold;">🛡️ SERENO AUTO</span></div>'
              . '<div style="border:1px solid #E2E8F0;border-top:none;padding:20px;border-radius:0 0 8px 8px;">'
              . '<h2 style="color:#0D1B2A;margin-top:0;">' . htmlspecialchars($sujet) . '</h2>'
              . '<p style="color:#333;line-height:1.6;">' . nl2br(htmlspecialchars($message)) . '</p>'
              . '<p style="color:#64748B;font-size:13px;">📞 ' . SERENO_TELEPHONE . ' — SERENO AUTO, Yaoundé</p>'
              . '</div></div>';

        return @mail($destinataire, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $html, $entetes);
    }

    /** Requête HTTP GET simple avec timeout court. */
    private static function requeteHttp(string $url): bool {
        $contexte = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $reponse  = @file_get_contents($url, false, $contexte);
        if ($reponse === false) return false;
        // Considérer 2xx comme succès
        $statut = $http_response_header[0] ?? '';
        return (bool)preg_match('/\s2\d\d\s/', $statut);
    }
}

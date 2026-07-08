<?php
// ============================================================
//  SERENO SMS — Gestion JWT (JSON Web Tokens)
// ============================================================

class JWT {

    // ---- Générer un access token ----
    public static function genererToken(array $payload): string {
        $header  = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRE;
        $corps   = self::base64url(json_encode($payload));
        $signature = self::base64url(
            hash_hmac('sha256', "$header.$corps", JWT_SECRET, true)
        );
        return "$header.$corps.$signature";
    }

    // ---- Générer un refresh token sécurisé ----
    public static function genererRefreshToken(): string {
        return bin2hex(random_bytes(64));
    }

    // ---- Vérifier et décoder un token ----
    public static function verifier(string $token): array {
        $parties = explode('.', $token);
        if (count($parties) !== 3) {
            throw new Exception('Token invalide', 401);
        }

        [$header, $corps, $signatureRecue] = $parties;
        $signatureAttendue = self::base64url(
            hash_hmac('sha256', "$header.$corps", JWT_SECRET, true)
        );

        if (!hash_equals($signatureAttendue, $signatureRecue)) {
            throw new Exception('Signature invalide', 401);
        }

        $payload = json_decode(self::base64urlDecode($corps), true);

        if ($payload['exp'] < time()) {
            throw new Exception('Token expiré', 401);
        }

        return $payload;
    }

    // ---- Extraire le token du header Authorization ----
    public static function extraireDeHeader(): ?string {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

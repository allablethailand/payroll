<?php
declare(strict_types=1);

/**
 * Minimal JWT (HS256) encode/decode, ported line-for-line from Origami's own
 * lib/jwt_helper.php so the wire format matches exactly what api/oauth/v2/auth.php
 * expects/produces. Not a general-purpose JWT library -- only used by auth/index.php
 * to talk to Origami's SSO endpoint. Named distinctly (not `JWT`) to avoid colliding
 * with a real JWT library if one is ever added via composer.
 */
class OrigamiSsoJwt {
    public static function encode($payload, string $key, string $algo = 'HS256'): string {
        $header = ['typ' => 'JWT', 'alg' => $algo];

        $segments = [];
        $segments[] = self::urlsafeB64Encode((string)json_encode($header));
        $segments[] = self::urlsafeB64Encode((string)json_encode($payload));
        $signingInput = implode('.', $segments);

        $segments[] = self::urlsafeB64Encode(self::sign($signingInput, $key, $algo));

        return implode('.', $segments);
    }

    /**
     * @param bool $verify Origami's own client apps (vonconnect, academy, robusta, ...) all
     *   decode the OAuth response with verify=false -- the payload isn't encrypted (only
     *   base64), so it's still readable even when the signature can't be checked (e.g. on the
     *   error-status response, whose signing key may differ). Kept identical here for parity.
     */
    public static function decode(string $jwt, string $key, bool $verify = true): string {
        $tks = explode('.', $jwt);
        if (count($tks) !== 3) {
            throw new UnexpectedValueException('Wrong number of segments');
        }
        [$headb64, $bodyb64, $cryptob64] = $tks;

        $header = json_decode(self::urlsafeB64Decode($headb64));
        if ($header === null) {
            throw new UnexpectedValueException('Invalid header encoding');
        }
        $payload = self::urlsafeB64Decode($bodyb64);
        if (json_decode($payload) === null && $payload !== 'null') {
            throw new UnexpectedValueException('Invalid payload encoding');
        }

        if ($verify) {
            $sig = self::urlsafeB64Decode($cryptob64);
            if (empty($header->alg)) {
                throw new DomainException('Empty algorithm');
            }
            if (!hash_equals(self::sign("$headb64.$bodyb64", $key, $header->alg), $sig)) {
                throw new UnexpectedValueException('Signature verification failed');
            }
        }

        return $payload;
    }

    public static function sign(string $msg, string $key, string $method = 'HS256'): string {
        $methods = [
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
        ];
        if (empty($methods[$method])) {
            throw new DomainException('Algorithm not supported');
        }
        return hash_hmac($methods[$method], $msg, $key, true);
    }

    public static function urlsafeB64Decode(string $input): string {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return (string)base64_decode(strtr($input, '-_', '+/'));
    }

    public static function urlsafeB64Encode(string $input): string {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }
}

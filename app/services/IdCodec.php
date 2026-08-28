<?php
declare(strict_types=1);
require_once __DIR__ . '/EncryptionService.php';

/**
 * Obfuscates an internal auto-increment id for use in a public-facing URL, e.g.
 * `/payroll-process/{encoded}` instead of `/payroll-process/137` -- reuses EncryptionService
 * (AES-256-GCM) rather than inventing a new obfuscation scheme, so decode() gets the same
 * tamper-evidence (GCM auth tag) and key-rotation support the rest of the app already relies on
 * for PII. This is presentation-layer obfuscation only (don't want to hand out sequential ids
 * that invite enumeration) -- it is NOT a substitute for the real authorization check every
 * route/model already does via comp_id scoping. A forged, stale-key, or garbage token just fails
 * to decode (returns null), which the caller should treat the same as "not found" (404).
 *
 * The key version is prefixed as one raw byte ahead of EncryptionService's own iv+tag+ciphertext
 * bundle, so decode() knows which key to try without a separate lookup or column -- there's no
 * database row backing this token the way payroll_sync_items.key_version has one.
 */
class IdCodec {
    public static function encode(int $id): string {
        $enc = EncryptionService::encrypt((string)$id);
        $bundle = chr($enc['key_version']) . base64_decode($enc['value'], true);
        return rtrim(strtr(base64_encode($bundle), '+/', '-_'), '=');
    }

    public static function decode(string $token): ?int {
        try {
            $padded = $token . str_repeat('=', (4 - strlen($token) % 4) % 4);
            $bundle = base64_decode(strtr($padded, '-_', '+/'), true);
            if ($bundle === false || strlen($bundle) < 2) {
                return null;
            }
            $keyVersion = ord($bundle[0]);
            $cipherValue = base64_encode(substr($bundle, 1));
            $plain = EncryptionService::decrypt($cipherValue, $keyVersion);
            if ($plain === null || !ctype_digit($plain)) {
                return null;
            }
            return (int)$plain;
        } catch (Throwable $e) {
            return null;
        }
    }
}

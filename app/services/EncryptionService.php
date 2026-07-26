<?php
declare(strict_types=1);

/**
 * Field-level encryption for sensitive Employee data (AES-256-GCM, application-layer).
 * Keys come from .env (ENCRYPTION_KEY_V{n}) — never hardcoded, never stored in the DB.
 */
class EncryptionService {
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private static array $keys = [];
    private static ?int $currentVersion = null;
    private static ?string $hmacKey = null;

    private static function loadKeys(): void {
        if (!empty(self::$keys)) {
            return;
        }
        $version = 1;
        while (($encoded = $_ENV["ENCRYPTION_KEY_V{$version}"] ?? null) !== null) {
            $key = base64_decode($encoded, true);
            if ($key === false || strlen($key) !== 32) {
                throw new RuntimeException("ENCRYPTION_KEY_V{$version} in .env is not a valid base64-encoded 32-byte key.");
            }
            self::$keys[$version] = $key;
            $version++;
        }
        if (empty(self::$keys)) {
            throw new RuntimeException('No encryption key configured. Set ENCRYPTION_KEY_V1 in .env.');
        }
        self::$currentVersion = max(array_keys(self::$keys));
    }

    private static function loadHmacKey(): string {
        if (self::$hmacKey !== null) {
            return self::$hmacKey;
        }
        $encoded = $_ENV['ENCRYPTION_HMAC_KEY'] ?? null;
        if (!$encoded) {
            throw new RuntimeException('No HMAC key configured. Set ENCRYPTION_HMAC_KEY in .env.');
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('ENCRYPTION_HMAC_KEY in .env is not a valid base64-encoded 32-byte key.');
        }
        self::$hmacKey = $key;
        return self::$hmacKey;
    }

    public static function currentKeyVersion(): int {
        self::loadKeys();
        return self::$currentVersion;
    }

    /**
     * Encrypts a plaintext value. Returns null if the input is null/empty (so optional
     * fields stay null rather than becoming an encrypted empty string).
     * @return array{value: string, key_version: int}|null
     */
    public static function encrypt(?string $plaintext): ?array {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        self::loadKeys();
        $version = self::$currentVersion;
        $key = self::$keys[$version];
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return [
            'value' => base64_encode($iv . $tag . $ciphertext),
            'key_version' => $version,
        ];
    }

    /**
     * Decrypts a value using the key version it was encrypted with.
     * Returns null (not an exception) on any malformed/undecryptable payload so a single
     * corrupt row cannot take down a whole list/detail request.
     */
    public static function decrypt(?string $payload, ?int $keyVersion): ?string {
        if ($payload === null || $payload === '') {
            return null;
        }
        self::loadKeys();
        $keyVersion = $keyVersion ?? 1;
        if (!isset(self::$keys[$keyVersion])) {
            throw new RuntimeException("Encryption key version {$keyVersion} is not configured in .env.");
        }
        $key = self::$keys[$keyVersion];
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            return null;
        }
        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? null : $plaintext;
    }

    /**
     * HMAC-SHA256 of a normalized plaintext, for exact-match lookup columns (e.g. id_card_no_hash).
     * Deterministic (same input -> same output) unlike encrypt(), which uses a random IV each time.
     */
    public static function hash(?string $plaintext): ?string {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        return hash_hmac('sha256', $plaintext, self::loadHmacKey());
    }
}

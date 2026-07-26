<?php
declare(strict_types=1);

/**
 * Shared helpers for building fixed-width government export rows. Padding is done on the
 * BYTE length of the string in its target encoding (Thai fixed-width specs are historically
 * byte-width, not character-width — a Thai name in TIS-620/CP874 is 1 byte/char, but the
 * same string in UTF-8 is 3 bytes/char, so padding must happen after encoding conversion).
 */
trait FixedWidthHelperTrait {
    /** Left-align text, pad/truncate to exactly $length bytes with spaces. */
    protected function padText(string $value, int $length): string {
        $bytes = strlen($value);
        if ($bytes > $length) {
            return substr($value, 0, $length);
        }
        return $value . str_repeat(' ', $length - $bytes);
    }

    /** Right-align a numeric value, zero-padded to exactly $length digits (no decimal point). */
    protected function padNumber(float $value, int $length, int $decimals = 0): string {
        $scaled = (string)(int)round($value * (10 ** $decimals));
        $scaled = ltrim($scaled, '-');
        if (strlen($scaled) > $length) {
            $scaled = substr($scaled, -$length);
        }
        return str_pad($scaled, $length, '0', STR_PAD_LEFT);
    }

    /** Convert an internal UTF-8 string to the byte encoding the target file expects. */
    protected function toFileEncoding(string $utf8Value, string $encoding = 'TIS-620'): string {
        $converted = @iconv('UTF-8', $encoding . '//TRANSLIT', $utf8Value);
        return $converted !== false ? $converted : $utf8Value;
    }
}

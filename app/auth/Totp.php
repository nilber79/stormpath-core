<?php
/**
 * Pure-PHP TOTP (RFC 6238) implementation.
 * No external dependencies required.
 */
class Totp
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS       = 6;
    private const PERIOD       = 30;
    private const ALGORITHM    = 'sha1';
    private const WINDOW       = 1; // allow ±1 time step for clock skew

    /**
     * Generate a random base32-encoded secret (20 bytes = 160 bits).
     */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        return self::base32Encode($bytes);
    }

    /**
     * Build the otpauth:// URI for QR code generation.
     */
    public static function getUri(string $secret, string $label, string $issuer): string
    {
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
        return 'otpauth://totp/' . rawurlencode($label) . '?' . $params;
    }

    /**
     * Verify a 6-digit TOTP code against the secret.
     * Checks current step and ±WINDOW steps for clock skew tolerance.
     */
    public static function verify(string $secret, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key  = self::base32Decode($secret);
        $step = (int) floor(time() / self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::hotp($key, $step + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    private static function hotp(string $key, int $counter): string
    {
        // Pack counter as 64-bit big-endian
        $msg  = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac(self::ALGORITHM, $msg, $key, true);
        // Dynamic truncation (RFC 4226 §5.3)
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code   = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $out   = '';
        $chars = self::BASE32_CHARS;
        $len   = strlen($data);
        for ($i = 0; $i < $len; $i += 5) {
            $chunk = substr($data, $i, 5);
            $pad   = 5 - strlen($chunk);
            $chunk = str_pad($chunk, 5, "\0");
            $b = array_map('ord', str_split($chunk));
            $out .= $chars[($b[0] >> 3) & 0x1F];
            $out .= $chars[(($b[0] & 0x07) << 2) | (($b[1] >> 6) & 0x03)];
            $out .= $chars[($b[1] >> 1) & 0x1F];
            $out .= $chars[(($b[1] & 0x01) << 4) | (($b[2] >> 4) & 0x0F)];
            $out .= $chars[(($b[2] & 0x0F) << 1) | (($b[3] >> 7) & 0x01)];
            $out .= $chars[($b[3] >> 2) & 0x1F];
            $out .= $chars[(($b[3] & 0x03) << 3) | (($b[4] >> 5) & 0x07)];
            $out .= $chars[$b[4] & 0x1F];
        }
        // Trim padding characters that correspond to zero-padded bytes
        $padMap = [0 => 0, 1 => 6, 2 => 4, 3 => 3, 4 => 1];
        $trim   = $padMap[$len % 5] ?? 0;
        return $trim ? substr($out, 0, -$trim) : $out;
    }

    private static function base32Decode(string $data): string
    {
        $data   = strtoupper(trim($data));
        $chars  = self::BASE32_CHARS;
        $lookup = array_flip(str_split($chars));
        $bits   = '';
        foreach (str_split($data) as $ch) {
            if (!isset($lookup[$ch])) {
                continue;
            }
            $bits .= str_pad(decbin($lookup[$ch]), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}

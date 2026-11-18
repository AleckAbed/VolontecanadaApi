<?php

namespace App\Support;

use App\Models\Admin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Double authentification du personnel (2.7).
 *
 * - Application d'authentification : codes TOTP (RFC 6238, SHA-1, 6 chiffres, 30 s),
 *   tolérance d'un pas avant ou après, et un code déjà utilisé est refusé (rejeu).
 * - Courriel : code à 6 chiffres valable 10 minutes, 5 essais au plus.
 * - Codes de secours : 8 codes à usage unique ; seules leurs empreintes sont gardées.
 */
final class TwoFactor
{
    public const ISSUER_FALLBACK = 'Plateforme cabinets';

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const EMAIL_TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    // ─── TOTP ───────────────────────────────────────────────────────────────

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function otpauthUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function totp(string $secret, int $timestep): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('N', 0) . pack('N', $timestep), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $num = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Vérifie un code TOTP ; $replayKey empêche de réutiliser un code déjà accepté. */
    public static function verifyTotp(string $secret, string $code, ?string $replayKey = null): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $now = intdiv(now()->getTimestamp(), 30);
        $last = $replayKey ? (int) Cache::get($replayKey, 0) : 0;
        foreach ([0, -1, 1] as $w) {
            $step = $now + $w;
            if ($step > $last && hash_equals(self::totp($secret, $step), $code)) {
                if ($replayKey) {
                    Cache::put($replayKey, $step, now()->addMinutes(5));
                }

                return true;
            }
        }

        return false;
    }

    // ─── Code par courriel ──────────────────────────────────────────────────

    /** Crée un code à 6 chiffres pour $key et le renvoie (à envoyer par courriel). */
    public static function issueEmailCode(string $key): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("2fa-email:{$key}", ['hash' => hash('sha256', $code), 'attempts' => 0], now()->addMinutes(self::EMAIL_TTL_MINUTES));

        return $code;
    }

    public static function verifyEmailCode(string $key, string $code): bool
    {
        $entry = Cache::get("2fa-email:{$key}");
        if (! $entry) {
            return false;
        }
        $code = preg_replace('/\D/', '', $code);
        if (hash_equals($entry['hash'], hash('sha256', $code))) {
            Cache::forget("2fa-email:{$key}");

            return true;
        }
        $entry['attempts']++;
        $entry['attempts'] >= self::MAX_ATTEMPTS
            ? Cache::forget("2fa-email:{$key}")
            : Cache::put("2fa-email:{$key}", $entry, now()->addMinutes(self::EMAIL_TTL_MINUTES));

        return false;
    }

    // ─── Codes de secours ───────────────────────────────────────────────────

    /** @return array{0: string[], 1: string[]} [codes en clair à montrer une fois, empreintes à garder] */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        for ($i = 0; $i < $count; $i++) {
            $plain[] = strtoupper(Str::random(4) . '-' . Str::random(4));
        }

        return [$plain, array_map(fn ($c) => hash('sha256', $c), $plain)];
    }

    /** Consomme un code de secours de l'admin s'il est valide. */
    public static function useRecoveryCode(Admin $admin, string $code): bool
    {
        $hash = hash('sha256', strtoupper(trim($code)));
        $codes = $admin->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $admin->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** Vérifie un code (application ou courriel selon la méthode de l'admin). */
    public static function verifyFor(Admin $admin, string $code, string $emailKey): bool
    {
        return $admin->two_factor_method === 'totp'
            ? self::verifyTotp((string) $admin->two_factor_secret, $code, "2fa-totp-last:{$admin->id}")
            : self::verifyEmailCode($emailKey, $code);
    }

    /** Adresse masquée : « m•••@exemple.ca ». */
    public static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 1) . '•••@' . $domain;
    }

    private static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $c)), 5, '0', STR_PAD_LEFT);
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

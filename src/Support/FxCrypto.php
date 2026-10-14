<?php

namespace Botble\EmironFx\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Çelësi i të dhënave personale të Emiron FX — i ndarë nga APP_KEY i Botble-s.
 *
 *   FX_DATA_KEY            çelësi aktual (base64:...). Nëse mungon, përdoret APP_KEY
 *                          (përputhshmëri me 0.5.0, ku të dhënat u enkriptuan me APP_KEY).
 *   FX_DATA_PREVIOUS_KEYS  çelësa të vjetër (presje mes tyre) — vetëm për LEXIM,
 *                          p.sh. kur rikthehet një backup i para rrotullimit.
 *
 * Ndërrimi i APP_KEY nuk prek më të dhënat e klientëve pasi FX_DATA_KEY vendoset.
 * Ndërrimi i FX_DATA_KEY bëhet VETËM me `php artisan fx:rotate-key`.
 */
class FxCrypto
{
    private static ?array $override = null;

    /** Për fx:rotate-key: përdor përkohësisht një çelës tjetër (null = rikthe konfigurimin). */
    public static function useKeys(?string $current, array $previous = []): void
    {
        self::$override = $current === null ? null : ['current' => $current, 'previous' => $previous];
    }

    public static function currentKey(): string
    {
        if (self::$override) {
            return self::$override['current'];
        }

        $key = trim((string) config('plugins.emiron-fx.general.data_key', ''));

        return $key !== '' ? $key : (string) config('app.key');
    }

    /** @return array<int,string> */
    public static function previousKeys(): array
    {
        if (self::$override) {
            return self::$override['previous'];
        }

        return (array) config('plugins.emiron-fx.general.data_previous_keys', []);
    }

    public static function usesDedicatedKey(): bool
    {
        return trim((string) config('plugins.emiron-fx.general.data_key', '')) !== '';
    }

    public static function encrypt(?string $value): ?string
    {
        return $value === null ? null : self::encrypter(self::currentKey())->encryptString($value);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }

        foreach (array_merge([self::currentKey()], self::previousKeys()) as $key) {
            try {
                return self::encrypter($key)->decryptString($payload);
            } catch (DecryptException) {
                continue;
            }
        }

        throw new DecryptException('Emiron FX: të dhënat s\'dekriptohen me FX_DATA_KEY (ose çelësat e mëparshëm).');
    }

    /** HMAC për kërkimin me dokument ("blind index"), me çelësin aktual. */
    public static function blindIndex(string $normalized, ?string $key = null): string
    {
        return hash_hmac('sha256', $normalized, $key ?? self::currentKey());
    }

    public static function encrypter(string $key): Encrypter
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($raw === false || $raw === '') {
            throw new RuntimeException('Emiron FX: çelës i pavlefshëm.');
        }

        return new Encrypter($raw, (string) config('app.cipher', 'AES-256-CBC'));
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));
    }
}

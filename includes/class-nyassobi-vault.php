<?php
/**
 * The bureau's vault: identity data of membership requests is encrypted on
 * arrival, and only the bureau's passphrase can decrypt it.
 *
 * Public-key encryption (libsodium sealed boxes): WordPress keeps the public
 * key, so it can encrypt every new request on its own; the secret key is
 * stored locked by a key derived from the bureau's passphrase (Argon2id),
 * which WordPress never stores. Other administrators, the host or a database
 * leak only ever see ciphertext.
 *
 * Decryption happens on the server, for the request that asked for it, when
 * a bureau member types the passphrase; nothing decrypted is written back.
 *
 * @package NyassobiWPPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Nyassobi_Vault
{
    private const OPTION = 'nyassobi_vault';
    private const PREFIX = 'nyv1:';
    public const MIN_PASSPHRASE = 10;

    public static function is_ready(): bool
    {
        $vault = get_option(self::OPTION);

        return is_array($vault) && ! empty($vault['public']) && ! empty($vault['locked']) && function_exists('sodium_crypto_box_seal');
    }

    /**
     * Creates the key pair, locked by the passphrase. Replacing an existing
     * vault makes the requests encrypted with it unreadable.
     */
    public static function setup(string $passphrase): void
    {
        $pair = sodium_crypto_box_keypair();
        update_option(self::OPTION, ['public' => base64_encode(sodium_crypto_box_publickey($pair))] + self::lock($pair, $passphrase), false);
        sodium_memzero($pair);
    }

    /** Re-locks the same key pair with a new passphrase: nothing is lost. */
    public static function change(string $old, string $new): bool
    {
        $pair = self::unlock($old);
        if (null === $pair) {
            return false;
        }
        $vault = (array) get_option(self::OPTION);
        update_option(self::OPTION, ['public' => $vault['public']] + self::lock($pair, $new), false);
        sodium_memzero($pair);

        return true;
    }

    /**
     * @return array{salt:string,nonce:string,locked:string}
     */
    private static function lock(string $pair, string $passphrase): array
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = self::derive($passphrase, $salt);
        $locked = sodium_crypto_secretbox($pair, $nonce, $key);
        sodium_memzero($key);

        return ['salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'locked' => base64_encode($locked)];
    }

    private static function derive(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    /** The key pair, or null if the passphrase is wrong. */
    public static function unlock(string $passphrase): ?string
    {
        if (! self::is_ready() || '' === $passphrase) {
            return null;
        }
        $vault = (array) get_option(self::OPTION);
        $key = self::derive($passphrase, (string) base64_decode((string) $vault['salt'], true));
        $pair = sodium_crypto_secretbox_open((string) base64_decode((string) $vault['locked'], true), (string) base64_decode((string) $vault['nonce'], true), $key);
        sodium_memzero($key);

        return false === $pair ? null : $pair;
    }

    /** Encrypts for the bureau. Needs no passphrase: only the public key. */
    public static function seal(string $plain): string
    {
        $vault = (array) get_option(self::OPTION);

        return self::PREFIX . base64_encode(sodium_crypto_box_seal($plain, (string) base64_decode((string) $vault['public'], true)));
    }

    public static function is_sealed(string $value): bool
    {
        return 0 === strpos($value, self::PREFIX);
    }

    /** Decrypts with an unlocked key pair; values that were never sealed come back as they are. */
    public static function open(string $value, string $pair): ?string
    {
        if (! self::is_sealed($value)) {
            return $value;
        }
        $plain = sodium_crypto_box_seal_open((string) base64_decode(substr($value, strlen(self::PREFIX)), true), $pair);

        return false === $plain ? null : $plain;
    }
}

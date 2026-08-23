<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

final class Ecdsa
{
    private const string CURVE = 'prime256v1';

    private function __construct()
    {
    }

    /**
     * Generate a fresh key pair.
     *
     * @return array{private: string, public: string}
     */
    public static function generateKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => self::CURVE,
        ]);

        if ($resource === false) {
            throw new RuntimeException(
                'Could not generate a key pair: '.(openssl_error_string() ?: 'unknown OpenSSL error').'. '
                .'This usually means OpenSSL cannot find its configuration file (openssl.cnf) — '
                .'check the "openssl.cnf" setting in php.ini or set the OPENSSL_CONF environment variable.'
            );
        }

        openssl_pkey_export($resource, $private);

        return [
            'private' => base64_encode($private),
            'public' => base64_encode(openssl_pkey_get_details($resource)['key']),
        ];
    }

    /** Decode a base64-wrapped PEM private key, validating it is EC. */
    public static function loadPrivateKey(string $encoded): OpenSSLAsymmetricKey
    {
        $pem = base64_decode($encoded, true);

        if ($pem === false) {
            throw new RuntimeException('Invalid ECDSA private key: not valid base64.');
        }

        $key = @openssl_pkey_get_private($pem);

        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new RuntimeException('Invalid ECDSA private key.');
        }

        return $key;
    }

    /** Derive the base64-wrapped PEM public key matching an already-loaded private key. */
    public static function publicKeyFor(OpenSSLAsymmetricKey $privateKey): string
    {
        return base64_encode(openssl_pkey_get_details($privateKey)['key']);
    }

    /** Decode a base64-wrapped PEM public key, validating it is EC. */
    public static function loadPublicKey(string $encoded): OpenSSLAsymmetricKey
    {
        $pem = base64_decode($encoded, true);

        if ($pem === false) {
            throw new RuntimeException('Invalid ECDSA public key: not valid base64.');
        }

        $key = @openssl_pkey_get_public($pem);

        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new RuntimeException('Invalid ECDSA public key.');
        }

        return $key;
    }
}

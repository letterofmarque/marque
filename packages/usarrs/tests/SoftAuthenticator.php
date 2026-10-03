<?php

declare(strict_types=1);

namespace Marque\Usarrs\Tests;

use RuntimeException;

/**
 * A software passkey: a P-256 key that answers WebAuthn registration and
 * sign-in challenges the way a browser authenticator would, with "none"
 * attestation (#10883).
 *
 * It exists so the suite can drive laravel/passkeys' real endpoints end to end.
 * The passkey tests used to exercise only the Livewire component and the model,
 * which is why nobody noticed the endpoints didn't exist on current Fortify.
 */
final class SoftAuthenticator
{
    private \OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private ?string $userHandle = null;

    private int $signCount = 0;

    public function __construct(private readonly string $origin = 'http://localhost')
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false) {
            throw new RuntimeException('Could not generate a P-256 key.');
        }

        $this->key = $key;
        $this->credentialId = random_bytes(16);
    }

    /**
     * Answer the options from GET /user/passkeys/options.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function register(array $options): array
    {
        $this->userHandle = self::decode($options['user']['id']);

        $clientData = $this->clientData('webauthn.create', $options['challenge']);

        $authData = hash('sha256', $options['rp']['id'], true)
            ."\x45" // user present, user verified, attested credential data
            .pack('N', $this->signCount)
            .str_repeat("\0", 16) // AAGUID
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        $attestationObject = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);

        return $this->credential([
            'clientDataJSON' => self::encode($clientData),
            'attestationObject' => self::encode($attestationObject),
            'transports' => ['internal'],
        ]);
    }

    /**
     * Answer the options from GET /passkeys/login/options.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function signIn(array $options, string $rpId = 'localhost'): array
    {
        $clientData = $this->clientData('webauthn.get', $options['challenge']);

        $authData = hash('sha256', $rpId, true)
            ."\x05" // user present, user verified
            .pack('N', ++$this->signCount);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->credential([
            'clientDataJSON' => self::encode($clientData),
            'authenticatorData' => self::encode($authData),
            'signature' => self::encode($signature),
            'userHandle' => self::encode((string) $this->userHandle),
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function credential(array $response): array
    {
        return [
            'id' => self::encode($this->credentialId),
            'rawId' => self::encode($this->credentialId),
            'type' => 'public-key',
            'response' => $response,
            'clientExtensionResults' => [],
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The public key as COSE: EC2, ES256, P-256.
     */
    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return self::cbor([
            1 => 2,
            3 => -7,
            -1 => 1,
            -2 => new CborBytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            -3 => new CborBytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        ]);
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }

    /**
     * Just enough CBOR for an attestation object and a COSE key: maps,
     * integers, text and byte strings.
     */
    private static function cbor(mixed $value): string
    {
        return match (true) {
            $value instanceof CborBytes => self::head(2, strlen($value->bytes)).$value->bytes,
            is_int($value) && $value >= 0 => self::head(0, $value),
            is_int($value) => self::head(1, -1 - $value),
            is_string($value) => self::head(3, strlen($value)).$value,
            is_array($value) => array_reduce(
                array_keys($value),
                fn (string $carry, int|string $k) => $carry.self::cbor($k).self::cbor($value[$k]),
                self::head(5, count($value)),
            ),
            default => throw new RuntimeException('Unsupported CBOR value.'),
        };
    }

    private static function head(int $major, int $length): string
    {
        $major <<= 5;

        return match (true) {
            $length < 24 => chr($major | $length),
            $length < 0x100 => chr($major | 24).chr($length),
            $length < 0x10000 => chr($major | 25).pack('n', $length),
            default => chr($major | 26).pack('N', $length),
        };
    }
}

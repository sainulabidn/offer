<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;

/**
 * A software passkey for tests: a P-256 key that answers WebAuthn ceremonies the way a phone or laptop
 * does, returning the JSON the browser sends (PublicKeyCredential.toJSON()). No attestation ("none").
 */
final class VirtualAuthenticator
{
    public readonly string $credentialId;

    private OpenSSLAsymmetricKey $key;

    private ?string $userHandle = null;

    private int $counter = 0;

    public function __construct(public string $origin = 'http://localhost:4321')
    {
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credentialId = random_bytes(32);
    }

    /** navigator.credentials.create({ publicKey: options }) */
    public function create(array $options): array
    {
        $this->userHandle = $options['user']['id'];
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $publicKey = self::cbor([
            1 => 2,   // kty: EC2
            3 => -7,  // alg: ES256
            -1 => 1,  // crv: P-256
            -2 => self::bytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            -3 => self::bytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        ]);
        // Flags: user present, user verified, attested credential data included.
        $authData = $this->authData($options['rp']['id'], 0x45)
            .str_repeat("\0", 16) // AAGUID: unknown authenticator
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$publicKey;

        return $this->credential([
            'clientDataJSON' => self::b64($this->clientData('webauthn.create', $options['challenge'])),
            'attestationObject' => self::b64(self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => self::bytes($authData)])),
            'transports' => ['internal'],
        ]);
    }

    /** navigator.credentials.get({ publicKey: options }) */
    public function get(array $options): array
    {
        $this->counter++;
        $authData = $this->authData($options['rpId'], 0x05); // user present + verified
        $clientData = $this->clientData('webauthn.get', $options['challenge']);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->credential([
            'clientDataJSON' => self::b64($clientData),
            'authenticatorData' => self::b64($authData),
            'signature' => self::b64($signature),
            'userHandle' => $this->userHandle,
        ]);
    }

    private function authData(string $rpId, int $flags): string
    {
        return hash('sha256', $rpId, true).chr($flags).pack('N', $this->counter);
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false]);
    }

    private function credential(array $response): array
    {
        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => $response,
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Marks a string as CBOR bytes rather than text. */
    private static function bytes(string $value): object
    {
        return (object) ['bytes' => $value];
    }

    /** Just enough CBOR for WebAuthn: integers, byte and text strings, lists and maps. */
    private static function cbor(mixed $value): string
    {
        $head = fn (int $major, int $length) => match (true) {
            $length < 24 => chr($major << 5 | $length),
            $length < 0x100 => chr($major << 5 | 24).chr($length),
            $length < 0x10000 => chr($major << 5 | 25).pack('n', $length),
            default => chr($major << 5 | 26).pack('N', $length),
        };

        return match (true) {
            is_int($value) => $value >= 0 ? $head(0, $value) : $head(1, -1 - $value),
            is_object($value) => $head(2, strlen($value->bytes)).$value->bytes,
            is_string($value) => $head(3, strlen($value)).$value,
            is_array($value) && $value !== [] && array_is_list($value) => $head(4, count($value)).implode('', array_map(self::cbor(...), $value)),
            is_array($value) => $head(5, count($value)).implode('', array_map(fn ($k, $v) => self::cbor($k).self::cbor($v), array_keys($value), $value)),
        };
    }
}

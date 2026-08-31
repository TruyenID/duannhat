<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\DomainMutation\EphemeralMutationIdentity;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use WeakMap;

final class CustomerCredentialPayload implements EphemeralMutationIdentity
{
    /** @var WeakMap<self, array{value: string, proof: string}>|null */
    private static ?WeakMap $values = null;

    private static ?string $proofKey = null;

    public function __construct(#[SensitiveParameter] string $credential)
    {
        if ($credential === '' || strlen($credential) > 4096) {
            throw new InvalidArgumentException('Customer credential length is invalid.');
        }

        self::$values ??= new WeakMap;
        self::$proofKey ??= random_bytes(32);
        self::$values[$this] = [
            'value' => $credential,
            'proof' => hash_hmac('sha256', $credential, self::$proofKey),
        ];
    }

    public function reveal(): string
    {
        $stored = self::$values[$this] ?? throw new LogicException('Customer credential is no longer available.');
        $proof = hash_hmac('sha256', $stored['value'], self::$proofKey ?? '');

        if (! hash_equals($stored['proof'], $proof)) {
            throw new LogicException('Customer credential integrity check failed.');
        }

        return $stored['value'];
    }

    public function ephemeralMutationIdentity(): string
    {
        return self::$values[$this]['proof'] ?? throw new LogicException('Customer credential is no longer available.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Customer credentials cannot be serialized.');
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }
}

<?php

namespace App\Services\DomainMutation;

use LogicException;
use WeakMap;

/**
 * Process-local capability registry for values emitted by a configured verification port.
 *
 * A public DTO can be forged with `new`. A value registered here cannot: its proof is
 * bound to both object identities and never appears in serialization or debug output.
 */
final class VerifiedObjectRegistry
{
    /** @var WeakMap<object, array{scope: string, verifier_class: string, verifier_id: int, proof: string}>|null */
    private static ?WeakMap $proofs = null;

    private static ?string $processKey = null;

    private function __construct() {}

    public static function seal(object $subject, object $verifier, VerificationAuthority $authority, string $scope, string $requiredPort): void
    {
        if (! is_a($verifier, $requiredPort)) {
            throw new LogicException("{$scope} may only be issued by {$requiredPort}.");
        }

        $scope = MutationCommand::safeToken($scope, 'verificationScope', 160);
        $authority->assertAuthorizes($verifier, $requiredPort, $scope);
        self::$proofs ??= new WeakMap;
        self::$processKey ??= random_bytes(32);
        self::$proofs[$subject] = [
            'scope' => $scope,
            'verifier_class' => $verifier::class,
            'verifier_id' => spl_object_id($verifier),
            'proof' => hash_hmac('sha256', implode('|', [
                $scope,
                $subject::class,
                (string) spl_object_id($subject),
                $verifier::class,
                (string) spl_object_id($verifier),
            ]), self::$processKey),
        ];
    }

    public static function assertSealed(object $subject, string $scope): void
    {
        $entry = self::$proofs[$subject] ?? null;
        if ($entry === null || ! hash_equals($entry['scope'], $scope) || ! hash_equals(
            $entry['proof'],
            hash_hmac('sha256', implode('|', [
                $scope,
                $subject::class,
                (string) spl_object_id($subject),
                $entry['verifier_class'],
                (string) $entry['verifier_id'],
            ]), self::$processKey ?? '')
        )) {
            throw new LogicException("Unverified object supplied for {$scope}.");
        }
    }

    public static function derive(object $subject, object $verifiedSource, string $sourceScope, string $targetScope): void
    {
        self::assertSealed($verifiedSource, $sourceScope);
        $targetScope = MutationCommand::safeToken($targetScope, 'verificationScope', 160);
        self::$proofs ??= new WeakMap;
        self::$processKey ??= random_bytes(32);
        self::$proofs[$subject] = [
            'scope' => $targetScope,
            'verifier_class' => $verifiedSource::class,
            'verifier_id' => spl_object_id($verifiedSource),
            'proof' => hash_hmac('sha256', implode('|', [
                $targetScope,
                $subject::class,
                (string) spl_object_id($subject),
                $verifiedSource::class,
                (string) spl_object_id($verifiedSource),
            ]), self::$processKey),
        ];
    }
}

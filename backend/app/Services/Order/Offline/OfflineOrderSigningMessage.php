<?php

namespace App\Services\Order\Offline;

use App\Services\Order\ValueObjects\OrderSelectionPayload;

/**
 * The exact bytes an offline device signs (#1092/#1094/#1096).
 *
 * DESIGN NOTE — why this is NOT `ComputesCanonicalPayload::canonicalJson()`.
 * The signature has to be produced in Go (workstation) and verified in PHP
 * (Cloud). Reproducing one language's `json_encode` byte-for-byte in another is
 * the classic silent-drift trap: key order, unicode escaping, slash escaping
 * and float formatting all have to agree forever, and the first divergence
 * rejects HONEST orders in production — the worst possible failure for money.
 *
 * So the signed form is a fixed-order, newline-delimited field list instead.
 * Every embedded field is a UUID, a decimal integer, an enum token, or a
 * lowercase sha256 hex string — alphabets that cannot contain the delimiter —
 * and free text (line notes) is hashed rather than embedded. There is exactly
 * one way to encode a given selection, in any language, with no escaping rules
 * to get wrong.
 *
 * `ComputesCanonicalPayload` keeps its job (the typed-command fingerprint that
 * binds a command to its payload inside Cloud). This class is only about the
 * cross-language signature.
 *
 * Both sides are pinned to the same committed fixture:
 *   backend/tests/Fixtures/offline_signing_golden.json
 *   workstation/internal/service/testdata/offline_signing_golden.json
 * A divergence fails a test in BOTH repos before it can reach a device.
 */
final class OfflineOrderSigningMessage
{
    /** Bump ONLY with a coordinated fleet rollout — it changes every signature. */
    public const VERSION = 'tempo-offline-order-v1';

    /** Placeholder for an absent optional id (uuids never contain '-' alone). */
    private const ABSENT = '~';

    /**
     * Digest of the customer's SELECTION — what was ordered, never money.
     *
     * Line order is significant (the payload is an ordered list); topping order
     * is already canonicalised by OrderLineSelectionPayload's canonical set, so
     * the digest inherits that determinism instead of re-sorting.
     */
    public static function selectionDigest(OrderSelectionPayload $selection): string
    {
        $parts = [
            'sel-v1',
            $selection->orderType->value,
            $selection->pickupType->value,
            self::optional($selection->scheduledPickupAt),
            self::optional($selection->customerId),
            $selection->guestCount === null ? self::ABSENT : (string) $selection->guestCount,
            // Table ids are a canonical SET on the payload; join in that order.
            $selection->tableIds === [] ? self::ABSENT : implode(',', $selection->tableIds),
            $selection->locale->value,
            $selection->channel->value,
            self::optional($selection->deviceId),
            self::optional($selection->couponCode),
            // #2860 — chuỗi NGUYÊN VĂN thiết bị đã ký, không phải giá trị đã
            // chuẩn hoá. Xem `OrderSelectionPayload::$splitModeWire`: dùng bản
            // chuẩn hoá ở đây sẽ từ chối đơn offline của mọi máy chưa cập nhật.
            self::optional($selection->splitModeWire),
            $selection->splitPeopleCount === null ? self::ABSENT : (string) $selection->splitPeopleCount,
            // Free text: hashed, never embedded — a note may contain newlines.
            self::textDigest($selection->note),
            (string) count($selection->lines),
        ];

        foreach ($selection->lines as $line) {
            $parts[] = 'L';
            $parts[] = $line->lineId;
            $parts[] = self::optional($line->menuProductSkuId);
            $parts[] = self::optional($line->productSkuId);
            $parts[] = (string) $line->quantity;
            $parts[] = self::textDigest($line->note);
            $parts[] = (string) count($line->toppings);

            foreach ($line->toppings as $topping) {
                $parts[] = 'T';
                $parts[] = $topping->toppingGroupItemId;
                $parts[] = $topping->productSkuId;
                $parts[] = (string) $topping->quantity;
                $parts[] = self::textDigest($topping->note);
            }
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * The full message the device's Ed25519 key signs: the evidence envelope
     * bound to the selection digest. The signature therefore covers WHO signed
     * (device/issuer/key), WHEN it is valid, WHICH catalog priced it, and WHAT
     * was ordered — changing any one of those invalidates it.
     *
     * @param  string  $issuedAt  RFC3339 UTC, second precision
     * @param  string  $expiresAt  RFC3339 UTC, second precision
     */
    public static function message(
        string $deviceId,
        string $issuerId,
        int $catalogRevision,
        string $issuedAt,
        string $expiresAt,
        string $keyId,
        string $selectionDigest,
    ): string {
        return implode("\n", [
            self::VERSION,
            $deviceId,
            $issuerId,
            (string) $catalogRevision,
            $issuedAt,
            $expiresAt,
            $keyId,
            $selectionDigest,
        ]);
    }

    /** Convenience: digest the selection and build the message in one step. */
    public static function forSelection(
        OrderSelectionPayload $selection,
        string $deviceId,
        string $issuerId,
        int $catalogRevision,
        string $issuedAt,
        string $expiresAt,
        string $keyId,
    ): string {
        return self::message(
            $deviceId,
            $issuerId,
            $catalogRevision,
            $issuedAt,
            $expiresAt,
            $keyId,
            self::selectionDigest($selection),
        );
    }

    /**
     * Verify a base64 Ed25519 signature over a message with a base64 public key.
     * Returns false on ANY malformed input — never throws, so a garbage
     * signature is a clean rejection rather than a 500 (fail-closed).
     */
    public static function verifySignature(string $message, string $signatureBase64, string $publicKeyBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);
        $publicKey = base64_decode($publicKeyBase64, true);

        if ($signature === false || $publicKey === false
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /** Hash free text so newlines/delimiters inside it can never shift a field. */
    private static function textDigest(?string $text): string
    {
        return $text === null || $text === ''
            ? self::ABSENT
            : hash('sha256', $text);
    }

    private static function optional(?string $value): string
    {
        return $value === null || $value === '' ? self::ABSENT : $value;
    }
}

<?php

namespace App\Services\DomainMutation;

use DateTimeImmutable;
use InvalidArgumentException;

abstract readonly class MutationCommand
{
    public function __construct(public MutationContext $context) {}

    /** Canonical identity for every command, including safe context proof and all operation fields. */
    final public function mutationFingerprint(): string
    {
        $properties = get_object_vars($this);
        unset($properties['mutationFingerprint']);

        return hash('sha256', json_encode([
            'command' => static::class,
            'identity' => self::normalizeIdentity($properties),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    final public function assertMutationFingerprint(string $fingerprint): void
    {
        if (! hash_equals($this->mutationFingerprint(), self::fingerprint($fingerprint, 'mutationFingerprint'))) {
            throw new InvalidArgumentException('mutationFingerprint does not match the canonical mutation identity.');
        }
    }

    protected static function requireExpectedVersion(MutationContext $context): void
    {
        if ($context->expectedVersion === null) {
            throw new InvalidArgumentException('expectedVersion is required for revision-sensitive mutations.');
        }
    }

    public static function uuid(string $value, string $name): string
    {
        $value = strtolower(trim($value));

        $isRfcUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
        // Vùng UUID `00000000-0000-0000-0000-xxxxxxxxxxxx` là **sentinel do hệ
        // thống tự phát**, KHÔNG phải tàn dư seed cũ — đo được, và tên cũ
        // (`isLegacyTenantUuid`) nói ngược lại điều đó suốt một thời gian.
        //
        // Hai chỗ MÃ SỐNG ghi ra dạng này hôm nay:
        //
        //   OrderPaymentOrchestrationCompat  `STRIPE_SYSTEM_ACTOR_ID` — actor của
        //       MutationContext cho prepare/finalize `payment_attempts`
        //   ProductSkuService                sentinel "không khớp gì"
        //
        // cộng `tests/Pest.php` dựng org bootstrap bằng chính dạng này.
        //
        // #2863 đã GỠ chỗ thứ ba: `OrderPaymentService` từng ghi sentinel này vào
        // `order_payments.received_by_id` cho khoản customer-web tự xác nhận. Đó
        // là một cột DỮ LIỆU chứ không phải actor của mutation — production đo
        // 145/414 hàng mang id mà **không hàng `users` nào** có, nên nó nói dối
        // một cách trông đáng tin. Nay là NULL, đúng như schema đã khai.
        // `STRIPE_SYSTEM_ACTOR_ID` ở lại vì nó KHÔNG bao giờ chạm cột đó: đường
        // duy nhất `context->actorId` vào `received_by_id` là
        // `EloquentPaymentPersistence::recordTender`, mà nó chỉ được gọi từ
        // `recordAutoConfirmTender` — nơi actor luôn là user/device có thật.
        //
        // Vì vậy đây KHÔNG phải ứng viên xoá theo ruling #2188: gỡ nhánh này làm
        // đỏ 53 test `tests/Feature/Payment` **dù mọi DB đã sạch**, vì
        // `MutationContext` gác mọi `organizationId`/`actorId` qua đây (#2439 đo
        // 2026-08-11). Nới lỏng cho đúng vùng đó, không nới cho version/variant
        // tuỳ ý.
        $isReservedSentinelUuid = preg_match('/^00000000-0000-0000-0000-[0-9a-f]{12}$/', $value) === 1;

        if (! $isRfcUuid && ! $isReservedSentinelUuid) {
            throw new InvalidArgumentException("{$name} must be a valid UUID.");
        }

        return $value;
    }

    public static function nullableUuid(?string $value, string $name): ?string
    {
        return $value === null ? null : self::uuid($value, $name);
    }

    public static function safeToken(string $value, string $name, int $maxLength): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("{$name} must be a non-empty printable value of at most {$maxLength} characters.");
        }

        return $value;
    }

    /** Validate printable prose while preserving legitimate tabs and line breaks. */
    public static function safeText(string $value, string $name, int $maxLength): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("{$name} must be non-empty printable text of at most {$maxLength} characters.");
        }

        return $value;
    }

    public static function isoDateTime(string $value, string $name): string
    {
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException("{$name} must be a strict ISO-8601 date-time with timezone.");
        }

        return $value;
    }

    public static function fingerprint(string $value, string $name): string
    {
        $value = strtolower(trim($value));

        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$name} must be a lowercase SHA-256 fingerprint.");
        }

        return $value;
    }

    protected static function verifiedFingerprint(string $value, string $name, CanonicalMutationPayload $payload): string
    {
        $value = self::fingerprint($value, $name);

        if (! hash_equals($value, $payload->fingerprint())) {
            throw new InvalidArgumentException("{$name} does not match the canonical payload.");
        }

        return $value;
    }

    /** @param array<string, bool|int|string|null> $identity */
    public static function identityFingerprint(array $identity): string
    {
        ksort($identity, SORT_STRING);

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, bool|int|string|null> $identity */
    protected static function verifiedIdentityFingerprint(string $value, array $identity, string $name = 'mutationFingerprint'): string
    {
        $value = self::fingerprint($value, $name);
        if (! hash_equals($value, self::identityFingerprint($identity))) {
            throw new InvalidArgumentException("{$name} does not match the canonical mutation identity.");
        }

        return $value;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @param  callable(T): string  $identity
     * @return list<T>
     */
    public static function canonicalSet(array $values, callable $identity, string $name): array
    {
        $indexed = [];

        foreach (array_values($values) as $value) {
            $key = $identity($value);

            if (array_key_exists($key, $indexed)) {
                throw new InvalidArgumentException("{$name} cannot contain duplicate semantic identities.");
            }

            $indexed[$key] = $value;
        }

        ksort($indexed, SORT_STRING);

        return array_values($indexed);
    }

    /** @template T @param list<T> $values @param callable(T): string $identity @return list<T> */
    public static function uniqueOrdered(array $values, callable $identity, string $name): array
    {
        $seen = [];
        foreach ($values as $value) {
            $key = $identity($value);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("{$name} cannot contain duplicate semantic identities.");
            }
            $seen[$key] = true;
        }

        return array_values($values);
    }

    private static function normalizeIdentity(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \JsonSerializable) {
            return self::normalizeIdentity($value->jsonSerialize());
        }
        if ($value instanceof EphemeralMutationIdentity) {
            return ['ephemeral_identity' => $value->ephemeralMutationIdentity()];
        }
        if (is_object($value)) {
            throw new \LogicException('Unsupported object without explicit canonical mutation identity: '.$value::class);
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::normalizeIdentity(...), $value);
        }

        return $value;
    }
}

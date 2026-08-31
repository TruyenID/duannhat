<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Services\Payment\Gateway\Exceptions\IdempotencyPayloadMismatch;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use Illuminate\Support\Facades\Cache;

final class PayPayMutationIdempotency
{
    private const TTL_SECONDS = 86400;

    /**
     * @param  callable(): GatewayPaymentResult  $execute
     * @param  callable(string): GatewayPaymentResult  $retrieve
     */
    public function payment(
        string $connectionId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
        string $correlationId,
        callable $execute,
        callable $retrieve,
    ): GatewayPaymentResult {
        $cacheKey = self::cacheKey($connectionId, $operation, $idempotencyKey);
        /** @var array{fingerprint: string, merchant_id: string}|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            if (! hash_equals($cached['fingerprint'], $fingerprint)) {
                throw new IdempotencyPayloadMismatch($correlationId);
            }

            return $retrieve($cached['merchant_id']);
        }

        $result = $execute();
        if ($result->payment === null) {
            return $result;
        }

        Cache::put($cacheKey, [
            'fingerprint' => $fingerprint,
            'merchant_id' => $result->payment->value,
        ], self::TTL_SECONDS);

        return $result;
    }

    /**
     * @param  callable(): GatewayRefundResult  $execute
     * @param  callable(string): GatewayRefundResult  $retrieve
     */
    public function refund(
        string $connectionId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
        string $correlationId,
        callable $execute,
        callable $retrieve,
    ): GatewayRefundResult {
        $cacheKey = self::cacheKey($connectionId, $operation, $idempotencyKey);
        /** @var array{fingerprint: string, merchant_id: string}|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            if (! hash_equals($cached['fingerprint'], $fingerprint)) {
                throw new IdempotencyPayloadMismatch($correlationId);
            }

            return $retrieve($cached['merchant_id']);
        }

        $result = $execute();
        if ($result->refund === null) {
            return $result;
        }

        Cache::put($cacheKey, [
            'fingerprint' => $fingerprint,
            'merchant_id' => $result->refund->value,
        ], self::TTL_SECONDS);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fingerprint(array $values): string
    {
        return hash('sha256', json_encode(self::canonicalize($values), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function cacheKey(string $connectionId, string $operation, string $idempotencyKey): string
    {
        return 'paypay_mutation:'.hash('sha256', "{$connectionId}|{$operation}|{$idempotencyKey}");
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => self::canonicalize($item), $value);
    }
}

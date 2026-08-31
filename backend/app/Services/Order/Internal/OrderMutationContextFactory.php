<?php

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\DomainMutation\MutationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Builds mutation contexts for legacy array transports until every caller supplies explicit context. */
final class OrderMutationContextFactory
{
    public static function fromOrder(
        CustomerOrder $order,
        ?string $actorId = null,
        ?string $idempotencyKey = null,
        ?int $expectedVersion = 1,
    ): MutationContext {
        return new MutationContext(
            organizationId: $order->organization_id,
            actorId: $actorId ?? $order->created_by_id,
            correlationId: (string) Str::uuid(),
            idempotencyKey: $idempotencyKey ?? (string) Str::uuid(),
            expectedVersion: $expectedVersion,
        );
    }

    /** Staff-initiated creation — there is no order yet, so no expected version. */
    public static function forStaffCreate(string $organizationId, ?string $actorId = null): MutationContext
    {
        return new MutationContext(
            organizationId: $organizationId,
            actorId: $actorId,
            correlationId: (string) Str::uuid(),
            idempotencyKey: (string) Str::uuid(),
        );
    }

    public static function system(?string $organizationId = null): MutationContext
    {
        return new MutationContext(
            organizationId: $organizationId,
            actorId: null,
            correlationId: (string) Str::uuid(),
            idempotencyKey: (string) Str::uuid(),
        );
    }

    public static function fromWorkstationRequest(
        Request $request,
        CustomerOrder $order,
        string $purpose,
        ?string $idempotencyKey = null,
    ): MutationContext {
        $device = $request->attributes->get('device');

        return new MutationContext(
            organizationId: (string) $order->organization_id,
            actorId: $device ? (string) $device->id : null,
            correlationId: "workstation:{$purpose}:{$order->id}",
            idempotencyKey: $idempotencyKey ?? $request->header('Idempotency-Key') ?? (string) Str::uuid(),
            expectedVersion: 1,
        );
    }

    public static function fromKdsRequest(
        Request $request,
        CustomerOrder $order,
        string $purpose,
        ?string $idempotencyKey = null,
    ): MutationContext {
        $device = $request->attributes->get('device');

        return new MutationContext(
            organizationId: (string) $order->organization_id,
            actorId: $device ? (string) $device->id : null,
            correlationId: "kds:{$purpose}:{$order->id}",
            idempotencyKey: $idempotencyKey ?? $request->header('Idempotency-Key') ?? (string) Str::uuid(),
            expectedVersion: 1,
        );
    }
}

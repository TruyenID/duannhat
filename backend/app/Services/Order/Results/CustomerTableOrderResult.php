<?php

namespace App\Services\Order\Results;

/**
 * #1688 — outcome of the dine-in `POST /tables/{qrToken}/orders` funnel.
 *
 * The endpoint has THREE outcomes that differ only in payload + status (fresh
 * create 201, shared-session append 200, idempotent replay = whichever of the
 * two the first request produced), so the service returns the pair rather than
 * a `JsonResponse`: building the response inside the transaction is what this
 * type exists to stop.
 *
 * `$body` is the already-rendered payload — it is what gets cached against the
 * `Idempotency-Key`, so a replay returns bytes identical to the first response
 * instead of re-rendering an order that may have moved on since.
 */
final readonly class CustomerTableOrderResult
{
    /**
     * @param  array<string, mixed>  $body  the response payload, ready to encode
     * @param  int  $status  HTTP status the payload was produced with
     */
    public function __construct(
        public array $body,
        public int $status,
    ) {}
}

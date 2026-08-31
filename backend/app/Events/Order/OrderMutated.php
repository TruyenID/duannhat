<?php

declare(strict_types=1);

namespace App\Events\Order;

use App\Services\DomainMutation\MutationContext;
use App\Services\Order\OrderService;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The order domain's extension point: one internal event per successful
 * mutation, fired by {@see OrderService} — the single
 * facade every one of the ~48 order commands already passes through.
 *
 * WHY ONE EVENT AND NOT FORTY-EIGHT CLASSES. A plugin wants to observe the
 * order lifecycle without editing the service, and it wants that to keep
 * working when a 49th command is added. A single event carrying the command
 * NAME gives one subscription that covers everything, present and future; a
 * class per command gives a contract that silently fails to mention whatever
 * ships next. Filter with a match on `$event->command`.
 *
 * WHY NOT REUSE THE EXISTING Order* EVENTS. `OrderPaid`, `OrderVoided`,
 * `OrderItemAdded` and friends are WEBSOCKET TRANSPORT: they exist to nudge a
 * browser into refetching, their payloads are deliberately thin, they carry no
 * MutationContext, and several lifecycle transitions have none at all — nothing
 * fires on create, on refund, or on an offline replay landing. Handing those to
 * plugins would be handing over a UI detail as if it were a domain contract.
 * They are untouched; this is a separate, internal family.
 *
 * TRANSACTION TIMING. `ShouldDispatchAfterCommit`, and that is not optional:
 * the dominant dispatch sites (WritesCustomerOrders, OrderClosingService::close,
 * OrderPaymentService::create) all run inside long transactions, so a listener
 * fired inline could act on a write that then rolls back — issue a refund for a
 * sale that never happened. Any listener added here inherits that guarantee.
 *
 * WHAT A LISTENER MUST NOT ASSUME. That the work AROUND the mutation succeeded.
 * `OrderClosingService::close` deducts stock in a nested transaction whose
 * failure is deliberately swallowed ("order stays closed, payment preserved"),
 * so `command === 'close'` means the order closed, not that stock moved.
 *
 * Registering a listener:
 *
 *   Event::listen(OrderMutated::class, function (OrderMutated $e): void {
 *       if ($e->command !== 'close') { return; }
 *       // $e->orderId, $e->context->actorId, $e->context->correlationId
 *   });
 */
final class OrderMutated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  string  $command  the facade method that ran, e.g. 'close', 'replayOffline'
     * @param  string|null  $orderId  the order affected, when the result names one
     * @param  MutationContext  $context  actor, organization, correlation id, idempotency hash
     * @param  mixed  $result  the facade's return value, passed through untouched
     */
    public function __construct(
        public string $command,
        public ?string $orderId,
        public MutationContext $context,
        public mixed $result = null,
    ) {}
}

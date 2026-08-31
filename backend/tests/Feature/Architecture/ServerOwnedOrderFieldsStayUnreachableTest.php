<?php

/**
 * plan-055 (#1863) — fields that WAIVE a money check must be unreachable from a
 * request body, and that must be pinned rather than inferred.
 *
 * `offline_replayed_at` is the stamp that makes `OrderPaymentService` skip the
 * Gate 6 policy check entirely (it returns early on both forks). A client-
 * settable version of it is a permanent waiver on every payment for that order —
 * the exact hole plan-055 exists to close, wearing a different name.
 *
 * The Omnify-generated `CustomerOrderStoreRequestBase` DOES declare it a valid
 * input (`['nullable','date']`, and it is in the base `validated()` key list).
 * Today it never reaches `validated()` because both editable request classes
 * override `rules()` with narrow allowlists — so there are two layers, not one:
 *
 *   1. the `rules()` override keeps it out of `validated()`
 *   2. `$fillable` keeps it out of mass assignment (pinned by #1862)
 *
 * Layer 2 is pinned. Layer 1 was NOT: widening an override, or deleting it so
 * the generated `schemaRules()` applies again, would silently restore the field
 * with nothing going red. That is what this file fixes.
 */

use App\Http\Requests\CustomerOrderStoreRequest;
use App\Http\Requests\CustomerOrderUpdateRequest;
use App\Models\CustomerOrder;
use Illuminate\Http\Request;

/** @return list<string> */
function validatedKeysFor(string $requestClass, array $payload): array
{
    $request = $requestClass::createFrom(Request::create('/', 'POST', $payload));
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return array_keys($request->validated());
}

it('#1863 never lets a request body reach the offline-replay stamp', function (string $requestClass) {
    $keys = validatedKeysFor($requestClass, [
        'order_type' => 'dine_in',
        // The waiver, offered by a client.
        'offline_replayed_at' => '2020-01-01 00:00:00',
    ]);

    expect($keys)->not->toContain('offline_replayed_at');
})->with([
    [CustomerOrderStoreRequest::class],
    [CustomerOrderUpdateRequest::class],
]);

it('#1863 keeps the stamp out of mass assignment too', function () {
    // Layer 2, restated here so this file describes the whole defence rather
    // than half of it. `FillableOverridesStayInSyncTest` owns the general rule;
    // this asserts the specific consequence for the specific field.
    expect((new CustomerOrder)->getFillable())->not->toContain('offline_replayed_at');

    $order = new CustomerOrder;
    $order->fill(['offline_replayed_at' => '2020-01-01 00:00:00']);

    expect($order->offline_replayed_at)->toBeNull();
});

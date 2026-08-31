<?php

/**
 * #1242 — an editable model that re-lists `$fillable` is a manual copy of a
 * GENERATED list, and manual copies drift.
 *
 * `BrandOrderPolicy` says so in its own docblock: "Re-list the omnify fields so
 * mass-assign survives regen." That works right up until Omnify adds a column
 * to the base and nobody copies it across — after which
 * `Model::create([... 'new_column' => $v])` silently drops the value. No error,
 * no warning; the row is just written without it.
 *
 * That is the same shape as #1233 / #1234 / #1235 (a column added later, carried
 * by some writers and not others), which is why this gate exists.
 *
 * Today NOTHING is broken by it — every drifted column below is written through
 * a path that bypasses `$fillable` on purpose, and each was checked
 * individually. The gate is here so the NEXT one is not silent.
 */
use Illuminate\Database\Eloquent\Model;

/**
 * Columns the editable model deliberately does NOT re-list.
 *
 * Each entry must name the writer that reaches the column anyway. An entry with
 * no such writer is not an exclusion, it is a bug.
 */
const FILLABLE_EXCLUSIONS = [
    'BrandOrderPolicy' => [
        // BrandSettingsService:73 assigns the property directly.
        'default_print_label_locale',
    ],
    'CustomerOrder' => [
        // Not mass-assignable ON PURPOSE. A controller that forwards request
        // data into create() must not be able to set an order's QR token.
        'qr_token',
        // plan-055 T5.1 (#1826) — NOT mass-assignable ON PURPOSE, same reason as
        // `qr_token` above. A controller forwarding request data into create()
        // must not be able to declare that an order "came from an offline
        // replay": that stamp is what waives the Gate 6 policy check, so a
        // client-settable version of it would be a permanent waiver on every
        // payment — the exact hole plan-055 exists to close, wearing a
        // different name.
        //
        // Written by `EloquentOrderPersistence::persistTrustedSnapshot()` inside
        // `CustomerOrder::unguarded(...)`, after `assertTrusted()` — which is why
        // the value still lands despite being absent from $fillable.
        'offline_replayed_at',
        // EffectiveOrderPolicyService resolves this per branch; it is not a
        // per-order input.
        'payment_timeout_minutes',
        // The real relation is table_ids (many-to-many). table_id is a
        // denormalised "first table" written via forceFill in
        // EloquentOrderPersistence:791 and WritesCustomerOrders:564.
        'table_id',
    ],
];

it('an editable $fillable override does not silently lose a column its base declares', function () {
    $undocumented = [];
    $staleExclusions = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $name = basename($file, '.php');

        // Only models that re-list the property are at risk; one that inherits
        // the base list cannot drift from it.
        if (! str_contains((string) file_get_contents($file), 'protected $fillable')) {
            continue;
        }

        $class = 'App\\Models\\'.$name;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $parent = get_parent_class($class);

        if (! $parent || ! str_contains($parent, 'Omnify')) {
            continue;
        }

        // newInstanceWithoutConstructor: the base is abstract-ish plumbing and
        // booting it is neither needed nor always safe.
        $baseFillable = (new ReflectionClass($parent))->newInstanceWithoutConstructor()->getFillable();
        $lost = array_values(array_diff($baseFillable, (new $class)->getFillable()));
        $allowed = FILLABLE_EXCLUSIONS[$name] ?? [];

        foreach (array_diff($lost, $allowed) as $column) {
            $undocumented[] = "{$name}.{$column}";
        }

        foreach (array_diff($allowed, $lost) as $column) {
            $staleExclusions[] = "{$name}.{$column}";
        }
    }

    expect($undocumented)->toBe([], sprintf(
        "These editable models drop a column their Omnify base declares fillable:\n  %s\n".
        "Either re-list the column, or add it to FILLABLE_EXCLUSIONS naming the writer\n".
        'that reaches it another way. Silence here means mass-assign drops the value.',
        implode("\n  ", $undocumented),
    ));

    // A stale exclusion is not harmless: it is a claim about the code that has
    // stopped being true, and the next reader will trust it.
    expect($staleExclusions)->toBe([], sprintf(
        "These exclusions no longer describe anything — the column is re-listed now:\n  %s",
        implode("\n  ", $staleExclusions),
    ));
});

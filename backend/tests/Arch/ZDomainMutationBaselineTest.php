<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps product transports on the public mutation and query boundaries', function () {
    $transportFiles = array_merge(
        glob(app_path('Http/Controllers/Api/V1/HQ/*.php')) ?: [],
        glob(app_path('Services/Import/*.php')) ?: [],
    );
    $forbidden = [
        'ProductSkuService', 'ProductOptionService', 'ProductOptionValueService',
        'CategoryService', 'ProductTypeService', 'EloquentProductPersistence',
    ];

    foreach ($transportFiles as $file) {
        $contents = file_get_contents($file);
        foreach ($forbidden as $writer) {
            expect($contents)->not->toContain("App\\Services\\Product\\{$writer}");
        }
    }

    $facade = file_get_contents(app_path('Services/Product/ProductService.php'));
    expect($facade)->not->toContain('function __call', 'transportCreate', 'array $data');
});

it('keeps the Plan 047 writer inventory empty in strict gate 4 mode', function () {
    $aggregates = config('domain-mutation-guard.aggregates');

    expect(config('domain-mutation-guard.current_gate'))->toBe(4)
        ->and($aggregates['menu']['boundaries'])->toContain('app/Services/Promotion/MenuPromotionService.php')
        ->and($aggregates['payment']['boundaries'])->toContain('app/Services/Omnify/PaymentMethodService.php');

    $process = new Process([PHP_BINARY, 'artisan', 'architecture:domain-writers', '--json'], base_path());
    // 300s: the full-tree scan measures 70–80s on a clean checkout of this
    // machine — 30s was an environmental flake (same bump as the Plan047
    // acceptance tests).
    $process->setTimeout(300);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
            'known' => [],
            'new' => [],
            'stale' => [],
            'errors' => [],
        ]);
});

/**
 * RATCHET — the untyped doors still open on the ORDER facade.
 *
 * The Product facade is already clean: the test above asserts it contains no
 * `transportCreate` and no `array $data`, because every product mutation goes
 * through a typed Command carrying a MutationContext. The order facade is not
 * there yet — `CustomerOrderService::create(array $data)` and
 * `update(CustomerOrder, array $data)` are mass-assignment doors INSIDE the
 * boundary, so the domain-writer gate (which only checks WHERE a write happens,
 * not how it was described) sees nothing wrong with them.
 *
 * Closing them is a migration across ~50 call sites, not a cleanup. Until then
 * this counts them so the debt is visible and CANNOT GROW: anyone adding a new
 * untyped door has to lower a number in a test, which forces the conversation.
 * When the migration lands, this whole test is deleted and the order facade
 * joins the Product assertion above.
 */
it('does not open new untyped doors on the order facade', function () {
    $facade = (string) file_get_contents(app_path('Services/Customer/CustomerOrderService.php'));

    // Public methods taking a raw array instead of a typed Command.
    $untyped = preg_match_all('/public function \w+\([^)]*array \$data/', $facade);

    // The exact count today (2026-07-27). Not a round number with headroom:
    // headroom is permission to add one more.
    $ceiling = 10;

    expect($untyped)->toBeLessThanOrEqual(
        $ceiling,
        "The order facade's untyped `array \$data` doors grew to {$untyped} (ceiling {$ceiling}). ".
        'Add a typed Command in app/Services/Order/Commands and route the new path through '.
        'OrderMutationFacade instead of widening the legacy surface.',
    );

    // The untyped create door is a single named exception, not a pattern to copy.
    expect(substr_count($facade, 'transportCreate'))->toBeLessThanOrEqual(
        1,
        'transportCreate is the ONE grandfathered untyped create. A second caller means the untyped path is spreading.',
    );
});

/*
 * #1131 findings 2+3 — pinned so they cannot silently regress.
 */
it('order events all dispatch after commit — a rolled-back mutation must never broadcast', function () {
    $offenders = [];
    foreach (glob(app_path('Events/Order*.php')) as $file) {
        $source = (string) file_get_contents($file);
        if (! str_contains($source, 'ShouldDispatchAfterCommit')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe(
        [],
        'These Order* events broadcast without ShouldDispatchAfterCommit: '.implode(', ', $offenders).
        ' — a listener/browser would observe a mutation the transaction then rolls back.',
    );
});

it('does not re-introduce the decoy OrderPersistencePort binding without a consumer', function () {
    $provider = (string) file_get_contents(app_path('Providers/OrderServiceProvider.php'));
    $bindsPort = str_contains($provider, 'bind(OrderPersistencePort::class');

    $facade = (string) file_get_contents(app_path('Services/Order/OrderService.php'));
    $facadeUsesPort = str_contains($facade, 'OrderPersistencePort');

    // The binding is only honest when the facade actually resolves the port
    // (#1131 finding 2: a binding nothing injects makes decorators silently
    // no-op). Either both true (migration landed) or both false (today).
    expect($bindsPort)->toBe(
        $facadeUsesPort,
        $bindsPort
            ? 'OrderPersistencePort is bound but OrderService still injects the concrete class — the seam is a decoy again.'
            : 'OrderService now uses OrderPersistencePort — restore the container binding in OrderServiceProvider.',
    );
});

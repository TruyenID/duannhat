<?php

/**
 * Plan 047 acceptance — canonical domain mutation boundaries I1, I13, I18.
 */

use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Orchestration\PaymentOrchestrator;
use App\Services\Product\Contracts\ProductMutationFacade;
use Symfony\Component\Process\Process;

describe('I1 I13 I18 architecture guard strict gate 4', function () {
    it('I1 I18 reports zero known new stale or error writers at gate 4', function () {
        expect(config('domain-mutation-guard.current_gate'))->toBe(4)
            ->and(require base_path('architecture/domain-mutation-writers.php'))->toBe([]);

        $process = new Process([PHP_BINARY, 'artisan', 'architecture:domain-writers', '--json'], base_path());
        $process->setTimeout(300); // plan-051: repo grew — full-tree scan exceeds 60s on slower dev machines; assertion unchanged
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($report)->toMatchArray([
            'known' => [],
            'new' => [],
            'stale' => [],
            'errors' => [],
        ]);
    });

    it('I13 rejects reintroducing allowlist entries for direct menu writers', function () {
        $allowlistPath = base_path('architecture/domain-mutation-writers.php');
        $contents = file_get_contents($allowlistPath);

        expect($contents)->toContain('return []')
            ->not->toContain('ServiceBase')
            ->not->toContain('PaymentMethodController');
    });
});

describe('I2 I3 I5 facade routing smoke', function () {
    it('I2 product mutation facade is bound and reachable', function () {
        expect(app()->bound(ProductMutationFacade::class))->toBeTrue();
    });

    it('I3 menu aggregate boundaries include canonical menu services', function () {
        $boundaries = config('domain-mutation-guard.aggregates.menu.boundaries');

        expect($boundaries)->toContain('app/Services/Product/MenuService.php')
            ->and($boundaries)->toContain('app/Services/Promotion/MenuPromotionService.php');
    });

    it('I5 order mutation facade is bound and reachable', function () {
        expect(app()->bound(OrderMutationFacade::class))->toBeTrue();
    });

    it('I7 payment mutation facade is bound to orchestrator', function () {
        $facade = app(PaymentMutationFacade::class);
        expect($facade)->toBeInstanceOf(PaymentOrchestrator::class);
    });
});

describe('I14 dead omnify service modules stay absent', function () {
    it('I14 does not restore deleted Omnify payment menu product service bases', function () {
        $paths = [
            app_path('Omnify/Modules/Product/Services/ProductServiceBase.php'),
            app_path('Omnify/Modules/Menu/Services/MenuServiceBase.php'),
            app_path('Omnify/Modules/Menu/Services/MenuPromotionServiceBase.php'),
        ];

        foreach ($paths as $path) {
            expect(is_file($path))->toBeFalse("Unexpected resurrected writer: {$path}");
        }
    });
});

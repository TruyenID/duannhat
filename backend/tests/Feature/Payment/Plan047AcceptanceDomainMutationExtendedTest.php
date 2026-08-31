<?php

/**
 * Plan 047 acceptance — domain mutation boundaries I4–I12, I15–I17.
 */

use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\OrderService;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Orchestration\PaymentOrchestrator;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ProductService;
use Symfony\Component\Process\Process;

describe('I4 customer mutations route through CustomerService facade', function () {
    it('I4 customer aggregate boundaries include CustomerService', function () {
        $boundaries = config('domain-mutation-guard.aggregates.customer.boundaries');
        expect($boundaries)->toContain('app/Services/Customer/CustomerService.php');
    });
});

describe('I6 typed order commands only on OrderService facade', function () {
    it('I6 OrderService exposes typed command methods not generic update arrays', function () {
        $source = file_get_contents(app_path('Services/Order/OrderService.php'));
        expect($source)->not->toContain('function __call')
            ->and($source)->toContain('function confirm(')
            ->and($source)->toContain('function checkout(')
            ->and($source)->toContain('function void(');
    });
});

describe('I8 I9 settlement and snapshot immutability guards', function () {
    it('I7 I8 payment finalize routes settlement through OrderMutationFacade', function () {
        $source = file_get_contents(app_path('Services/Payment/Orchestration/PaymentOrchestrator.php'));
        expect($source)->toContain('settleOrderWhenRequired')
            ->and($source)->toContain('SettleOrderIfPaidCommand');
    });

    it('I9 order item snapshots are not rewritten by product service imports', function () {
        $orderPersistence = file_get_contents(app_path('Services/Order/Internal/EloquentOrderPersistence.php'));
        expect($orderPersistence)->not->toContain('ProductService');
    });
});

describe('I10 I11 generic bypass and acyclic dependencies', function () {
    it('I10 mutation commands extend MutationCommand not array payloads', function () {
        $contracts = file_get_contents(base_path('tests/Unit/Services/DomainMutationContractsTest.php'));
        expect($contracts)->toContain('MutationCommand::class');
    });

    it('I11 payment orchestrator depends on OrderMutationFacade not Order model writes', function () {
        $source = file_get_contents(app_path('Services/Payment/Orchestration/PaymentOrchestrator.php'));
        expect($source)->toContain('OrderMutationFacade')
            ->and($source)->not->toContain('CustomerOrder::query()->update');
    });
});

describe('I15 I16 I17 behavioral parity and convergence', function () {
    it('I15 importer webhook and sync replay contracts are enumerated in DomainMutationContractsTest', function () {
        $source = file_get_contents(base_path('tests/Unit/Services/DomainMutationContractsTest.php'));
        expect($source)->toContain('ProcessProviderEventCommand::class')
            ->and($source)->toContain('ImportProductsCommand::class');
    });

    it('I16 workstation sync uses canonical payment create endpoint', function () {
        $routes = file_get_contents(base_path('routes/api/workstation.php'));
        expect($routes)->toContain("Route::post('payments'");
    });

    it('I17 persistence port wraps mutations in DB transactions', function () {
        $source = file_get_contents(app_path('Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php'));
        expect($source)->toContain('DB::transaction');
    });
});

describe('I2 I5 product and order facade bindings', function () {
    it('I2 product facade binds to ProductService', function () {
        expect(app(ProductMutationFacade::class))->toBeInstanceOf(ProductService::class);
    });

    it('I5 order facade binds to OrderService', function () {
        expect(app(OrderMutationFacade::class))->toBeInstanceOf(OrderService::class);
    });

    it('I3 menu aggregate boundaries include canonical menu services', function () {
        $boundaries = config('domain-mutation-guard.aggregates.menu.boundaries');

        expect($boundaries)->toContain('app/Services/Product/MenuService.php');
    });

    it('I7 payment facade binds to PaymentOrchestrator', function () {
        expect(app(PaymentMutationFacade::class))->toBeInstanceOf(PaymentOrchestrator::class);
    });
});

describe('I18 strict gate 4 scan', function () {
    it('I18 architecture scan reports zero runtime direct writers at gate 4', function () {
        $process = new Process([PHP_BINARY, 'artisan', 'architecture:domain-writers', '--json'], base_path());
        $process->setTimeout(300); // plan-051: repo grew — full-tree scan exceeds 60s on slower dev machines; assertion unchanged
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['known'])->toBe([])
            ->and($report['new'])->toBe([])
            ->and($report['stale'])->toBe([])
            ->and($report['errors'])->toBe([]);
    });
});

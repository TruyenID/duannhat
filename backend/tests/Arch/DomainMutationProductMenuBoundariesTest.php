<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Shop\MenuPromotionController;
use App\Services\Promotion\Contracts\MenuPromotionMutationFacade;
use Symfony\Component\Process\Process;

it('routes shop menu promotion mutations through the mutation facade contract', function () {
    $parameters = (new ReflectionClass(MenuPromotionController::class))
        ->getConstructor()
        ?->getParameters() ?? [];

    $types = array_map(
        static fn (ReflectionParameter $parameter): ?string => $parameter->getType() instanceof ReflectionNamedType
            ? $parameter->getType()->getName()
            : null,
        $parameters,
    );

    expect($types)->toContain(MenuPromotionMutationFacade::class);
});

it('does not flag menu promotion controller as a generated service consumer', function () {
    $process = new Process(
        [PHP_BINARY, 'artisan', 'architecture:domain-writers', '--json'],
        base_path(),
    );
    // The full-tree scan measures 70-80s, so Symfony's 60s DEFAULT killed this
    // test outright — the same bump the two sibling tests already carry, missed
    // here. It read as a mutation-guard failure rather than a stopwatch.
    $process->setTimeout(300);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    $controllerFindings = array_values(array_filter(
        $result['new'] ?? [],
        static fn (array $finding): bool => $finding['path'] === 'app/Http/Controllers/Api/V1/Shop/MenuPromotionController.php',
    ));

    expect($controllerFindings)->toBe([]);
});

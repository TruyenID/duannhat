<?php

/**
 * Plan-023 M8 T8.15 arch test 3 — custom.* listener registration.
 *
 * The wildcard `custom.*` listener MUST be registered in
 * AppServiceProvider::boot() so that custom events are always routed
 * through the rule engine. This test guards against accidental removal
 * of that registration.
 */

use App\Jobs\Notification\EvaluateRuleJob;
use App\Listeners\Notification\ModelEventToRuleBridge;

it('M8-arch-3a: AppServiceProvider registers ModelEventToRuleBridge for custom.* events', function () {
    $source = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

    expect($source)
        ->toContain("'custom.*'")
        ->toContain('onCustomEvent');
});

it('M8-arch-3b: ModelEventToRuleBridge has an onCustomEvent method', function () {
    expect(method_exists(ModelEventToRuleBridge::class, 'onCustomEvent'))->toBeTrue();

    $refl = new ReflectionMethod(ModelEventToRuleBridge::class, 'onCustomEvent');
    expect($refl->isPublic())->toBeTrue('onCustomEvent must be public so the event dispatcher can invoke it');
});

it('M8-arch-3c: EvaluateRuleJob constructor accepts a context array (required for custom events)', function () {
    $refl = new ReflectionMethod(EvaluateRuleJob::class, '__construct');

    $paramNames = array_map(fn ($p) => $p->getName(), $refl->getParameters());
    expect($paramNames)->toContain('context');

    $contextParam = collect($refl->getParameters())->firstWhere(fn ($p) => $p->getName() === 'context');
    expect($contextParam->isOptional())->toBeTrue('context must have a default value')
        ->and($contextParam->getDefaultValue())->toBe([]);
});

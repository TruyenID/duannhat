<?php

/**
 * TemplateRenderer unit tests (plan-012 T2.2).
 *
 * Covers substitution, missing-param warning, locale fallback, and
 * renderAll for composer preview.
 */

use App\Models\NotificationTemplate;
use App\Services\Notification\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeTemplate(array $content, string $key = 'test.key'): NotificationTemplate
{
    return NotificationTemplate::query()->create([
        'key' => $key,
        'content' => $content,
        'is_system' => true,
    ]);
}

it('substitutes {{param}} tokens in ja title and body', function () {
    $tpl = makeTemplate([
        'ja' => ['title' => 'レシピ承認：{{recipe_name}}', 'body' => '{{approver}}が承認しました'],
    ]);

    $out = app(TemplateRenderer::class)->render($tpl, [
        'recipe_name' => 'カレー',
        'approver' => '田中',
    ], 'ja');

    expect($out)->toBe([
        'title' => 'レシピ承認：カレー',
        'body' => '田中が承認しました',
    ]);
});

it('missing param substitutes empty + logs warning, does not throw', function () {
    Log::spy();
    $tpl = makeTemplate([
        'ja' => ['title' => '{{missing_key}}あり', 'body' => ''],
    ]);

    $out = app(TemplateRenderer::class)->render($tpl, [], 'ja');

    expect($out['title'])->toBe('あり');
    Log::shouldHaveReceived('warning')->with('notification.template.missing_param', Mockery::on(
        fn ($ctx) => $ctx['template_key'] === 'test.key' && $ctx['param'] === 'missing_key',
    ));
});

it('unknown locale falls back to ja', function () {
    $tpl = makeTemplate([
        'ja' => ['title' => 'ja title', 'body' => ''],
        'en' => ['title' => 'en title', 'body' => ''],
    ]);

    $out = app(TemplateRenderer::class)->render($tpl, [], 'zz');

    expect($out['title'])->toBe('ja title');
});

it('falls back to en when ja is missing and locale is unknown', function () {
    $tpl = makeTemplate([
        'en' => ['title' => 'en title', 'body' => 'body'],
        'vi' => ['title' => 'vi title', 'body' => 'body'],
    ]);

    $out = app(TemplateRenderer::class)->render($tpl, [], 'zz');

    expect($out['title'])->toBe('en title');
});

it('renderAll returns every locale on the template', function () {
    $tpl = makeTemplate([
        'ja' => ['title' => 'ja {{name}}', 'body' => ''],
        'en' => ['title' => 'en {{name}}', 'body' => ''],
        'vi' => ['title' => 'vi {{name}}', 'body' => ''],
    ]);

    $out = app(TemplateRenderer::class)->renderAll($tpl, ['name' => 'A']);

    expect($out)->toHaveKeys(['ja', 'en', 'vi']);
    expect($out['ja']['title'])->toBe('ja A');
    expect($out['en']['title'])->toBe('en A');
    expect($out['vi']['title'])->toBe('vi A');
});

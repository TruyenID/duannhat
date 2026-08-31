<?php

use App\Models\Branch;
use Illuminate\Support\Str;

// Astrotomic Translatable's attributesToArray() always overwrites translated
// keys via getAttributeOrFallback(), which resolves ONLY through the
// `translations` relation and returns null when that relation has zero rows
// — even though PreservesTranslatableColumns::fill() guarantees the base
// `name` column holds a real value. This used to serialize name=null to every
// API response (workstation's PullBranch `if br.Name != ""` guard then never
// wrote workstation_branch_name, so printed slips fell back to "Store").
it('serializes the base name when the branch has zero translation rows', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
        'name' => 'Quán Phở Hàng Bún',
    ]);

    $branch->translations()->delete();
    $branch->refresh();

    expect($branch->translations()->count())->toBe(0)
        ->and($branch->toArray()['name'])->toBe('Quán Phở Hàng Bún');
});

it('still lets a real translation win over the base column', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
        'name' => 'Quán Phở Hàng Bún',
    ]);

    $branch->translateOrNew('en')->name = 'Pho Restaurant';
    $branch->save();
    $branch->refresh();

    app()->setLocale('en');

    expect($branch->toArray()['name'])->toBe('Pho Restaurant');
});

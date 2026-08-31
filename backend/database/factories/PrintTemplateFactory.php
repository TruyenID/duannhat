<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\PrintTemplate;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use App\Services\Print\SystemTemplateDefaults;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PrintTemplate factory — plan-053 M1 (#1171).
 *
 * The default definition is the SYSTEM DEFAULT of the kind, so a factory row
 * is publishable out of the box: a test that wants to prove a validation rule
 * has to break the definition on purpose, and a test that does not care about
 * validation never trips over it.
 *
 * @extends Factory<PrintTemplate>
 */
class PrintTemplateFactory extends Factory
{
    protected $model = PrintTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kind = PrintTemplateKind::Receipt;

        return [
            'organization_id' => null,
            'brand_id' => Brand::factory(),
            'branch_id' => null,
            'kind' => $kind->value,
            'scope' => PrintTemplateScope::Brand->value,
            'version' => 1,
            'status' => PrintTemplateStatus::Draft->value,
            'definition' => app(SystemTemplateDefaults::class)->forKind($kind),
            'shop_editable' => [],
            'effective_from' => null,
            'parent_version_id' => null,
            'notes' => null,
            'created_by_id' => null,
            'published_by_id' => null,
            'published_at' => null,
        ];
    }

    public function kind(PrintTemplateKind $kind): static
    {
        return $this->state(fn (): array => [
            'kind' => $kind->value,
            'definition' => app(SystemTemplateDefaults::class)->forKind($kind),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PrintTemplateStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => PrintTemplateStatus::Retired->value,
            'published_at' => now(),
        ]);
    }

    /** A shop-layer override for one branch. */
    public function shopScope(string $branchId): static
    {
        return $this->state(fn (): array => [
            'scope' => PrintTemplateScope::Shop->value,
            'branch_id' => $branchId,
            'shop_editable' => null,
        ]);
    }

    /**
     * `effective_from` is a BRANCH-LOCAL wall clock (#1091) — pass a plain
     * 'Y-m-d H:i:s' string, never a UTC instant.
     */
    public function effectiveFrom(?string $wallClock): static
    {
        return $this->state(fn (): array => ['effective_from' => $wallClock]);
    }
}

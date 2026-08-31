<?php

namespace Database\Factories;

use App\Models\Denomination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Denomination>
 */
class DenominationFactory extends Factory
{
    protected $model = Denomination::class;

    /**
     * The real JPY denominations, each paired with the kind it actually is —
     * the JPY half of DenominationSeeder, which is what production ships.
     *
     * This is the ENTIRE legal pool — ten rows — and that is what makes this
     * factory a different problem from the other #1132 cases.
     *
     * @var list<array{int, string}>
     */
    private const JPY = [
        [10000, 'note'],
        [5000, 'note'],
        [2000, 'note'],
        [1000, 'note'],
        [500, 'coin'],
        [100, 'coin'],
        [50, 'coin'],
        [10, 'coin'],
        [5, 'coin'],
        [1, 'coin'],
    ];

    /** Rotation cursor. Advanced exactly once per model, in definition(). */
    private static int $cursor = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // #1132 — denominations carries a UNIQUE
        // (organization_id, currency_code, value, kind) index, and `value` used
        // to be randomElement() over nine JPY denominations. Two rows in one
        // org/currency/kind collided 1 time in 9 — by far the worst odds of the
        // whole #1132 sweep, and better than even money by the fourth row
        // (birthday problem).
        //
        // fake()->unique() — the fix used for menus, payment_policy_revisions
        // and expiry_alerts — is the WRONG tool here. Those columns draw from a
        // range wide enough that the pool never runs dry; this one has ten legal
        // values, so Faker's unique generator would drain it and then throw
        // OverflowException. The generator is per PROCESS, not per test, so the
        // call that finally overflows is some unrelated test later in the suite:
        // unique() would trade a rare flake for a certain failure.
        //
        // Widening the pool is not an option either. A denomination is real
        // money; there is no ¥3 note, and a fixture that invented one would make
        // every cash-count test assert against a currency that does not exist.
        //
        // So: rotate deterministically through the real pool instead. Any single
        // test creating up to ten denominations gets ten DIFFERENT ones, which
        // is the most a correct fixture can offer — an eleventh row in the same
        // org/currency/kind has no legal value left to take, and a test wanting
        // one is describing something JPY cannot represent.
        //
        // `kind` rides along with the value instead of being pinned to 'note',
        // because ¥1 is a coin and the old default said otherwise.
        [$value, $kind] = self::JPY[self::$cursor++ % count(self::JPY)];

        return [
            'currency_code' => 'JPY',
            'value' => $value,
            'kind' => $kind,
            'label' => null,
            'sort_order' => 0,
            'is_active' => true,
            'organization_id' => null,
        ];
    }

    /**
     * Re-project the value the rotation already handed out onto the subset of
     * denominations of one kind, keeping its position in the rotation.
     *
     * Deliberately does NOT advance the cursor: a state runs on top of
     * definition(), so ticking again would give a stride of 2 and shrink the
     * effective pool (a stride of 2 over the six coins visits only three of
     * them, which is how the first cut of this fix still handed back duplicates).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{value: int, kind: string}
     */
    private static function ofKind(array $attributes, string $kind): array
    {
        $pool = array_values(array_filter(self::JPY, fn (array $d): bool => $d[1] === $kind));
        $tick = (int) array_search((int) $attributes['value'], array_column(self::JPY, 0), true);
        [$value, $realKind] = $pool[$tick % count($pool)];

        return ['value' => $value, 'kind' => $realKind];
    }

    public function note(): static
    {
        // Rotate within the notes only — pinning `kind` alone would have let the
        // rotation hand back a "¥50 note".
        return $this->state(fn (array $attributes) => self::ofKind($attributes, 'note'));
    }

    public function coin(): static
    {
        return $this->state(fn (array $attributes) => self::ofKind($attributes, 'coin'));
    }

    public function jpy10000(): static
    {
        return $this->state(fn () => ['currency_code' => 'JPY', 'value' => 10000, 'kind' => 'note']);
    }

    public function jpy1000(): static
    {
        return $this->state(fn () => ['currency_code' => 'JPY', 'value' => 1000, 'kind' => 'note']);
    }

    public function jpy100(): static
    {
        return $this->state(fn () => ['currency_code' => 'JPY', 'value' => 100, 'kind' => 'coin']);
    }
}

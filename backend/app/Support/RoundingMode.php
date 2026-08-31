<?php

namespace App\Support;

/**
 * Plan-033 — shared rounding helper for split-bill calculations.
 *
 * Mirrors the customer-web helpers added by plan-029 (`getRoundingStep`,
 * `roundUpToStep`) so PHP and TypeScript stay numerically aligned. The four
 * named modes match `ShopOrderSetting.split_bill_rounding_mode` exactly:
 *
 *   - `auto`         — derive step from the currency_code:
 *                       integer-only currencies (VND/JPY/KRW/IDR/…) → 1
 *                       3-decimal currencies (KWD/BHD/OMR/JOD/…)    → 0.001
 *                       everything else (USD/EUR/THB/…)             → 0.01
 *   - `integer`      — always step = 1, regardless of currency.
 *   - `two_decimals` — always step = 0.01.
 *   - `none`         — step = 0; caller uses exact division and absorbs the
 *                     remainder on one bill (last non-empty by convention).
 *                     For currencies with sub-unit precision, `none` collapses
 *                     to `two_decimals` because Stripe minor units are the
 *                     finest representable charge.
 *
 * The canonical zero-decimal currency set now lives in one place —
 * {@see ZeroDecimalCurrency::CODES} — and is shared with StripePaymentService.
 * `WHOLE_UNIT_EXTRA` below adds the currencies that carry a Stripe minor unit
 * but circulate only in whole units (IDR/LAK/MMK/COP). This mirrors
 * customer-web `lib/split-rounding.ts::ZERO_FRACTION` (and `THREE_DECIMAL`).
 */
final class RoundingMode
{
    /**
     * Currencies with a Stripe minor unit (2 decimals) whose smallest
     * *circulating* cash unit is nonetheless the integer, so split-bill / tax
     * rounding treats them as whole-unit even though Stripe charges them ×100.
     *
     * The true zero-decimal currencies (JPY/VND/KRW/CLP/GNF/VUV/XOF/XAF/XPF/DJF/
     * MGA/…) come from {@see ZeroDecimalCurrency::CODES} — the single source of
     * truth — so DJF and MGA can no longer be silently dropped here as they were
     * when this list was hand-maintained.
     */
    private const WHOLE_UNIT_EXTRA = [
        'IDR', 'LAK', 'MMK', 'COP',
    ];

    /**
     * Resolve the rounding step for a (mode, currency) pair.
     *
     * @param  string|null  $mode  one of auto/integer/two_decimals/none; null falls back to auto.
     * @param  string|null  $currencyCode  ISO 4217 code; null falls back to VND (project default).
     */
    public static function step(?string $mode, ?string $currencyCode): float
    {
        $mode = $mode ?: 'auto';
        $currency = strtoupper($currencyCode ?: 'VND');

        return match ($mode) {
            'integer' => 1.0,
            'two_decimals' => 0.01,
            'none' => 0.0,
            default => self::autoStep($currency),
        };
    }

    /**
     * Round a value UP to the nearest step.
     *
     * When `$step` is 0 the value passes through unchanged — the caller is
     * expected to absorb the leftover on one designated bill.
     */
    public static function roundUpToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }

        return ceil($value / $step) * $step;
    }

    /**
     * Round a value HALF-UP to the nearest step (issue #524 canonical rule).
     *
     * This is the single money/tax rounding convention for the backend:
     * tax + service + order total are all rounded half-up to the currency's
     * minor unit. Half-up matches {@see OrderPricingCalculator} (declared
     * source of truth), TypeScript's `Math.round`, and the workstation's
     * `math.Round`, so the printed receipt equals the persisted record.
     *
     * The step (currency minor unit) preserves the anti-lockup property the
     * old ceil path had: for integer currencies (JPY/VND/…) step = 1 so the
     * result is always a whole unit — a kiosk paying an integer amount can
     * never fall a fraction short of `total_amount` and wedge the order in
     * `paying`. Unlike ceil, half-up does not systematically over-collect tax.
     *
     * When `$step` is 0 (mode `none`) the value passes through unchanged.
     */
    public static function roundHalfUpToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }

        // `floor($value / $step + 0.5)` loses the exact half to binary error:
        // 0.145 / 0.01 evaluates to 14.499999999999998, so a genuine .xx5 tax
        // boundary rounds DOWN and under-collects (0.145 → 0.14 instead of 0.15).
        // Half-up rounding is the retail/consumption-tax standard (端数処理 切り上げ),
        // so normalise the quotient's representation error before the .5 decision.
        $quotient = self::toSignificantDigits($value / $step, 15);

        return floor($quotient + 0.5) * $step;
    }

    /**
     * Snap a double to the nearest value carrying `$digits` significant decimal
     * digits — the noise filter that makes the `.5` decision above meaningful.
     *
     * ## Why this is not `round($x, 9)` (#2082)
     *
     * It used to be, and that made the ROUNDING RULE ITSELF unportable. PHP's
     * `round()` is not IEEE rounding: it silently re-rounds to roughly 15
     * significant digits, which is why it moves values that are already exact
     * integers — `round(9294522499999998.0)` returns `9294522500000000.0`. Go's
     * `math.Round` does the mathematically correct thing and returns the input.
     *
     * The workstation ran `math.Round(v*1e9)/1e9` and therefore disagreed with
     * Cloud from `|value/step| ≳ 4.5×10⁶` upward — ₫4.5M is an ordinary dinner
     * bill, and the register always rounded TOWARD ZERO relative to Cloud, i.e.
     * under-collected tax and under-paid refunds.
     *
     * Note which side was right on INTENT: `9294.5225 / 0.001` is a true `.5`
     * boundary in decimal, and only PHP's quirk recovered it. So the fix is not
     * "adopt IEEE" — it is to state the intent explicitly and portably.
     *
     * `%.14e` is a correctly-rounded decimal conversion in both languages, so
     * this is exact on both sides rather than an approximation of a PHP
     * internal. Measured over 54,000 random quotients across the realistic
     * magnitude range: `sprintf('%.14e')` here and
     * `strconv.FormatFloat(v, 'e', 14, 64)` in Go round-trip to the SAME 64-bit
     * double in every case. The pair is pinned by `rounding_golden.json`.
     *
     * Adopting it changes Cloud's own output in one place, and there it fixes a
     * self-contradiction rather than moving money by choice: on an exact
     * negative half at `|q| ≳ 5×10⁶` (e.g. `-6642241.5`) PHP's `round()` noise
     * made `floor($q + 0.5)` return `-6642242`, while the same rule at small
     * magnitudes returns `-0.14` for `-0.145`. One rule, two directions. Now it
     * is one direction everywhere.
     *
     * @see docs/guide/tax-types.md
     */
    private static function toSignificantDigits(float $value, int $digits): float
    {
        if ($value === 0.0 || ! is_finite($value)) {
            return $value;
        }

        return (float) sprintf('%.'.($digits - 1).'e', $value);
    }

    /**
     * Round a value DOWN to the nearest step (切り捨て — plan-045 tax mode).
     *
     * The floor counterpart of {@see roundUpToStep}. When `$step` is 0 the value
     * passes through unchanged.
     */
    public static function roundDownToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }

        return floor($value / $step) * $step;
    }

    /**
     * plan-045 — round a consumption-tax figure with the order's snapshot
     * rounding rule. `$decimals` sets the step (0 → 1, 1 → 0.1, 2 → 0.01, 3 →
     * 0.001); when null, the step falls back to the currency's minor unit
     * ({@see step} 'auto') — the pre-plan-045 behaviour. `$mode` dispatches:
     *   - `round` → {@see roundHalfUpToStep} (四捨五入 — round half up, default)
     *   - `ceil`  → {@see roundUpToStep}     (切り上げ — round up)
     *   - `floor` → {@see roundDownToStep}   (切り捨て — round down)
     * Legacy snapshots (half_up/round_up/round_down) alias to the above.
     *
     * Applied ONCE per rate group by the engine (端数処理は税率ごとに1回, NTA
     * No.6371). Snapshot the (mode, decimals) on the order so a settings change
     * never re-rounds historical orders.
     */
    public static function roundTax(
        float $value,
        ?int $decimals,
        ?string $currencyCode,
        string $mode
    ): float {
        return self::roundToStep($value, self::taxStep($decimals, $currencyCode), $mode);
    }

    /**
     * plan-045 — dispatch a value to the requested rounding mode at a
     * precomputed step. Used by the engine, which resolves the tax step once
     * from the order's snapshot (`tax_rounding_decimals` → 10^-d, else currency
     * step) and then rounds every tax figure through this with the order's
     * `tax_rounding_mode`.
     *
     * Accepts the canonical plan-045 rev-B names (round/ceil/floor) AND their
     * legacy aliases (half_up/round_up/round_down) so orders snapshotted before
     * the rename still price identically. Any unknown/blank mode → round.
     */
    public static function roundToStep(float $value, float $step, string $mode): float
    {
        return match ($mode) {
            'ceil', 'round_up' => self::roundUpToStep($value, $step),
            'floor', 'round_down' => self::roundDownToStep($value, $step),
            default => self::roundHalfUpToStep($value, $step),
        };
    }

    /**
     * plan-045 — resolve the tax rounding step from an order's snapshot. Null
     * decimals falls back to the currency's minor unit (pre-plan-045 behaviour);
     * otherwise the step is 10^(-decimals).
     *
     * rev-B option-B — `decimals` sets the tax step to 10^(-decimals) even when
     * that is FINER than the currency unit (e.g. 0.01 on JPY). The resulting
     * tax carries the sub-unit precision for DISPLAY (消費税 93.50); the grand
     * TOTAL is still rounded to the currency unit by priceGroups (roundHalfUpToStep
     * to $step), so the payable amount stays whole and the difference surfaces as
     * a 端数調整 line. Null decimals → the currency minor unit (pre-plan-045).
     */
    public static function taxStep(?int $decimals, ?string $currencyCode): float
    {
        $currencyStep = self::step('auto', $currencyCode);

        if ($decimals === null) {
            return $currencyStep;
        }

        // rev-B option-B allows a step FINER than the currency unit (0.01 on JPY
        // for display precision) — keep that. But it must never be COARSER: the
        // DB default of `tax_rounding_decimals = 0` would otherwise round a USD
        // order's tax to whole DOLLARS (step 1.0), so a 1.45 @ 10% line collected
        // 0 tax. Consumption tax is never rounded coarser than the currency's
        // minor unit; clamp to the finer of the two.
        return min(10 ** (-$decimals), $currencyStep);
    }

    /**
     * #2133/#2180 — thuế của MỘT lần hoàn từng phần: hiệu giữa hai mốc LUỸ KẾ
     * đã làm tròn, không làm tròn từng lần (nếu không, ba lần hoàn dòng thuế 302
     * trả ra 303). Bản sinh đôi Go: `refundTaxDelta()` trong
     * `workstation/internal/service/order_service_refund.go`; hai bên gate
     * trên cùng `tests/Fixtures/refund_tax_golden.json`.
     *
     * `abs()` ở cả hai mốc vì primitive half-up bất đối xứng qua 0
     * (`-100,5 → -100`); bên gọi tự đảo dấu. Trả về giá trị KHÔNG dấu của phần
     * thuế lần hoàn này.
     */
    public static function refundTaxDelta(
        float $taxTotal,
        float $alreadyRefunded,
        float $quantity,
        float $originalQty,
        float $taxStep,
        string $taxMode
    ): float {
        if ($originalQty <= 0) {
            return 0.0;
        }

        $at = fn (float $cum): float => self::roundToStep(
            abs($taxTotal * $cum / $originalQty), $taxStep, $taxMode,
        );

        return $at($alreadyRefunded + $quantity) - $at($alreadyRefunded);
    }

    private static function autoStep(string $currency): float
    {
        if (ZeroDecimalCurrency::contains($currency) || in_array($currency, self::WHOLE_UNIT_EXTRA, true)) {
            return 1.0;
        }

        if (CurrencyMinorUnit::exponent($currency) === 3) {
            return 0.001;
        }

        return 0.01;
    }
}

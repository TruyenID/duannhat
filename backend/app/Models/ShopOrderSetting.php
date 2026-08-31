<?php

/**
 * ShopOrderSetting Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ShopOrderSetting\Models\ShopOrderSettingBaseModel;
use App\Services\Shop\EffectiveOrderPolicyService;
use App\Traits\AuditsActivity;
use Database\Factories\ShopOrderSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ShopOrderSetting — add project-specific model logic here.
 */
class ShopOrderSetting extends ShopOrderSettingBaseModel
{
    // plan-043 T1.15 — audit settings changes (tax mode / rate / default type
    // are compliance-relevant). The 'updated' event records the changed keys.
    use AuditsActivity;
    use HasFactory;

    /**
     * plan-037 — extend the omnify-generated fillable with the new
     * confirmation timeout override (nullable; null = inherit brand).
     * Re-list every field so mass-assign keeps working after omnify regen
     * (the parent's fillable is shadowed, not merged).
     */
    protected $fillable = [
        'default_order_item_status',
        'enable_quick_order',
        'service_charge_rate',
        'currency_code',
        'prep_before_payment',
        'confirmation_timeout_minutes',
        // #1160 — prep minutes per item (ETA = this x total quantity).
        // NULL = inherit the brand default. Same shadowed-fillable rule as
        // the plan-045 note below.
        'prep_minutes_per_item',
        // #491 — shop override; NULL = inherit brand default (free|cleaning).
        'table_status_after_payment',
        'customer_email_required',
        'split_bill_rounding_mode',
        'print_shift_open_report',
        // #1306 — MUST be listed here for the same reason as the plan-045 note
        // below: this editable model SHADOWS the omnify base $fillable, so a
        // field absent from this array is silently dropped on mass-assign even
        // though the column and the base $fillable both have it.
        'print_table_paid',
        'close_report_payment_methods',
        'close_report_service_charge',
        'close_report_denominations',
        'close_report_drawer_check',
        // plan-043 — consumption-tax settings.
        'default_tax_type_id',
        'prices_include_tax',
        'service_charge_tax_rate',
        'close_report_tax_breakdown',
        // plan-045 — tax rounding rule. MUST be listed here: this editable model
        // shadows (does not merge) the omnify base $fillable, so a field absent
        // from this array is silently dropped on mass-assign even though the
        // base lists it — which is exactly what broke the settings round-trip.
        'tax_rounding_mode',
        'tax_rounding_decimals',
        // Item-edit policy — when true, order items can be edited/removed/voided
        // regardless of status (OFF = pending-only). Shadowed fillable (see the
        // plan-045 note above) so it MUST be listed here to survive mass-assign.
        'allow_item_edit_any_status',
        // #876 — Handy direct-payment toggle (default OFF). Same shadowed-
        // fillable rule as above.
        'handy_allow_direct_payment',
        // #2806 — pay-at-counter channel + its kiosk QR (both default ON).
        // Same shadowed-fillable rule as above: absent here means the PATCH
        // silently drops the value while every other layer looks correct.
        'counter_pay_enabled',
        'counter_pay_show_qr',
        // #1124 — cashier ceiling for manual checkout discounts (% of
        // subtotal). Same shadowed-fillable rule as above.
        'manual_discount_max_percent',
        // Ngôn ngữ phiếu in tại quán (null = theo mặc định chi nhánh). Same
        // shadowed-fillable rule as above — was missing here, so PATCH
        // returned 200 "saved" but the value was silently dropped and a
        // reload always showed "Theo mặc định chi nhánh" again.
        'print_label_locale',
        // #1152 — display policy for the resolved 登録番号 on receipts. Same
        // shadowed-fillable rule as above.
        'show_seller_registration_on_receipt',
        // plan-051 (#1149/#1150) — per-status void matrix (null = resolve from
        // the legacy allow_item_edit_any_status flag) + stock-deduction timing.
        // Same shadowed-fillable rule as above.
        'item_voidable_statuses',
        'stock_deduction_timing',
        'branch_id',
        'organization_id',
    ];

    protected static function newFactory(): ShopOrderSettingFactory
    {
        return ShopOrderSettingFactory::new();
    }

    /**
     * plan-035 — bust the per-branch policy cache whenever an admin saves
     * a shop setting. The cache is keyed by branch id, so a single forget
     * is enough — no brand-fanout needed.
     */
    protected static function booted(): void
    {
        static::saved(function (ShopOrderSetting $setting) {
            if ($setting->branch_id) {
                EffectiveOrderPolicyService::forgetForBranch($setting->branch_id);
            }
        });

        static::deleted(function (ShopOrderSetting $setting) {
            if ($setting->branch_id) {
                EffectiveOrderPolicyService::forgetForBranch($setting->branch_id);
            }
        });
    }
}

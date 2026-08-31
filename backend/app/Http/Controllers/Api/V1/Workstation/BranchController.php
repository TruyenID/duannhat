<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\VoidReason;
use App\Services\Compliance\ComplianceProfileResolver;
use App\Services\Shop\SellerRegistrationResolver;
use App\Services\Shop\VoidableStatusResolver;
use App\Services\Workstation\BroadcastPokeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

class BranchController extends Controller
{
    #[OA\Get(
        path: '/api/v1/workstation/branch',
        summary: 'Pull branch info + shop order settings for the workstation device',
        description: 'Sync DOWN endpoint. Workstation refreshes its local cache of branch metadata (name, currency, timezone, cart timeout) and per-branch order settings (service_charge_rate, currency_code, operating_country). Branch resolved from the device token.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Branch + settings payload (settings=null when no shop_order_setting row exists).',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object'),
                    new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 403, description: 'Device type not allowed'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        // #1301 — `invoice_registration_number` belongs in this list because
        // SellerRegistrationResolver reads it off THIS model further down. It
        // was missing, and a partially-hydrated Eloquent model answers a
        // missing attribute with null rather than complaining, so a shop-level
        // 登録番号 stored correctly in the DB was served as an empty string and
        // every receipt printed without the number 適格請求書 requires. Brand-level
        // numbers hid the fault: the resolver queries Brand separately, so only
        // shops with their own override were affected.
        $branch = Branch::query()
            ->select(['id', 'console_branch_id', 'console_brand_id', 'console_organization_id',
                'slug', 'name', 'currency', 'timezone', 'locale',
                'address', 'phone', 'logo', 'business_hours', 'weekly_hours', 'cart_timeout_minutes',
                'invoice_registration_number'])
            ->find($device->branch_id);

        $settings = ShopOrderSetting::query()
            ->where('branch_id', $device->branch_id)
            ->select(['default_order_item_status', 'enable_quick_order',
                'service_charge_rate', 'currency_code',
                // Drives the workstation's auto-print orchestration (issue #456):
                //   true  → pay-before-prep: print kitchen ticket + receipt together on payment
                //   false → prep-first: print kitchen ticket on order arrival, receipt on payment
                'prep_before_payment',
                // #491 — shop override for the table status a paid table returns
                // to (null = inherit HQ). The workstation applies this LOCALLY the
                // instant it closes a paid order so POS (LAN-first) reflects it
                // without waiting on a Cloud round-trip. The effective value is
                // resolved below and always shipped.
                'table_status_after_payment',
                // Whether the workstation prints the レジ開け opening-cash slip on
                // shift open (workstation reads shop_settings.print_shift_open_report).
                'print_shift_open_report',
                // #1306 — the table-paid slip toggle. The workstation has ALWAYS
                // gated on this key (auto_print.go), but this select is an explicit
                // allowlist, so before it was named here Cloud never sent the key at
                // all: the default "true" held and the OFF branch was unreachable.
                'print_table_paid',
                // 精算 close-report optional-section toggles (workstation gates
                // each section in FormatShiftReport on these).
                'close_report_payment_methods',
                'close_report_service_charge',
                'close_report_denominations',
                'close_report_drawer_check',
                // plan-043 T3.2 — consumption-tax config. The workstation
                // flattens these into its shop_settings key-value table via
                // PullBranch so the local tax resolver + close-report tax
                // breakdown read them without a Cloud round-trip.
                'default_tax_type_id',
                'prices_include_tax',
                'service_charge_tax_rate',
                'close_report_tax_breakdown',
                // plan-045 — tax rounding rule (round/ceil/floor + decimals). The
                // workstation flattens these into shop_settings via PullBranch so
                // its local pricing engine stamps LAN-created orders with the same
                // snapshot Cloud would, keeping tax totals identical across the
                // Cloud/LAN boundary.
                'tax_rounding_mode',
                'tax_rounding_decimals',
                // Item-edit policy — the workstation order engine flattens this
                // into shop_settings via PullBranch and gates its pending-only
                // item update/void guards on it (false = pending-only; true =
                // edit/remove/void an item in any status). LAN-authoritative.
                // Kept for OLD workstation builds (plan-051 deprecates it in
                // favour of the resolved item_voidable_statuses below).
                'allow_item_edit_any_status',
                // plan-051 (#1149/#1150) — raw void matrix column (input to
                // VoidableStatusResolver; the payload ships the RESOLVED list)
                // + stock-deduction timing passthrough (deduction runs on
                // CLOUD; the workstation only displays/mirrors it).
                'item_voidable_statuses',
                'stock_deduction_timing',
                // #876 — Handy direct-payment toggle, mirrored to the LAN so the
                // workstation can gate its handy payment endpoint offline.
                'handy_allow_direct_payment',
                // Ngôn ngữ của MỌI phiếu in. The workstation flattens this into
                // shop_settings via PullBranch and reads it at print time, so a
                // shop keeps printing in the configured language even while the
                // Cloud link is DOWN (the value already sits in local SQLite).
                // null = chưa cấu hình → workstation falls back to
                // branches.locale → settings.pos_print_locale → "ja".
                'print_label_locale',
                // #1152 — display policy for the resolved 登録番号 below.
                'show_seller_registration_on_receipt'])
            ->first();

        // #491 — resolve the EFFECTIVE table-status-after-payment (shop override
        // ?? HQ brand default ?? 'free') and always include it in the settings
        // payload, even when no shop_order_setting row exists — so a brand-level
        // `cleaning` default still reaches the workstation.
        $brandId = $branch
            ? Brand::where('console_brand_id', $branch->console_brand_id)->value('id')
            : null;
        $brandTableStatusDefault = $brandId
            ? BrandOrderPolicy::where('brand_id', $brandId)->value('default_table_status_after_payment')
            : null;
        $effectiveTableStatusAfterPayment =
            $settings?->table_status_after_payment ?? $brandTableStatusDefault ?? 'free';

        // Ngôn ngữ phiếu in — resolve shop ?? HQ HERE, so the workstation only
        // ever sees ONE settled value and never has to know the brand layer
        // exists. That also keeps the offline story intact: the resolved value
        // lands in shop_settings on the next pull and printing reads it from
        // local SQLite with no Cloud round-trip. null stays null so the
        // workstation can fall through to branches.locale →
        // settings.pos_print_locale → "ja".
        $effectivePrintLabelLocale = $settings?->print_label_locale
            ?? ($brandId ? BrandOrderPolicy::where('brand_id', $brandId)->value('default_print_label_locale') : null);

        // #1152 — resolve the インボイス 登録番号 HERE (branch override ?? brand
        // default) so the workstation only ever sees ONE settled value in
        // shop_settings.seller_registration_number — the exact key its print
        // paths already read (print_receipt / fire_kitchen / lan_print).
        // Display toggle OFF ⇒ serialize '' so every workstation build,
        // including old ones, simply prints nothing.
        $sellerRegistrationNumber = '';
        if ($branch && ($settings?->show_seller_registration_on_receipt ?? true)) {
            $sellerRegistrationNumber = (string) (app(SellerRegistrationResolver::class)->resolve($branch) ?? '');
        }

        // plan-051 (#1149) — the workstation gets the RESOLVED voidable-status
        // list (matrix ∪ pending, or the legacy-flag fallback — ONE canonical
        // semantics via VoidableStatusResolver), never the raw column, plus the
        // brand's ACTIVE void reasons for its LAN SQLite mirror. Labels are
        // serialized in the shop's print/display locale (same resolution as
        // print_label_locale above) so the POS picker shows the right language
        // even while the Cloud link is down. allow_item_edit_any_status stays
        // in the payload untouched for old workstation builds.
        $resolvedVoidableStatuses = VoidableStatusResolver::resolve($settings);

        // #1490 (tầng 1 của #1459) — QUỐC GIA NƠI SHOP TỒN TẠI, để thiết bị chọn
        // được CHỨNG TỪ của nước mình. Trước đây workstation không có trục này ở
        // bất kỳ feed nào, nên `FormatVatInvoice` phải suy ra từ ngôn ngữ giao
        // diện của thu ngân: một quán Việt đặt locale ja in ra 適格簡易請求書, một
        // quán Nhật đặt locale vi in ra hoá đơn GTGT. Bốn trục độc lập —
        // compliance-country ≠ currency ≠ timezone ≠ print locale — và trục 1
        // đang bị suy từ trục 4.
        //
        // Đi trong `settings` chứ KHÔNG phải khối branch, có chủ đích: PullBranch
        // flatten `data.settings.*` generic vào shop_settings, còn khối branch là
        // struct Go có trường tường minh. Nhờ vậy mọi bản workstation đang chạy
        // lưu được key này ngay từ lần pull kế tiếp, không cần deploy client —
        // đúng cách `seller_registration_number` (#1152) đã đi.
        //
        // Nguồn là `ComplianceProfileResolver`, KHÔNG phải một cách đọc thứ hai
        // của `organizations.operating_country`: #1445 đã ràng buộc đúng một
        // đường đọc, hai đường là hai lần cơ hội lệch nhau. Kèm theo đó là
        // posture #1153 — org chưa mirror quốc gia thì resolver fail-safe về JP,
        // y như những kind mà admin đã phát cho nó. Nên giá trị này không bao giờ
        // rỗng, và bên đọc KHÔNG được coi 'JP' là "chắc chắn Nhật".
        $operatingCountry = app(ComplianceProfileResolver::class)
            ->forOrganization($branch?->console_organization_id)
            ->country();

        $labelLocale = $effectivePrintLabelLocale ?? $branch?->locale ?? config('app.locale');
        $voidReasons = $brandId
            ? VoidReason::query()
                ->with('translations')
                ->where('brand_id', $brandId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get()
                ->map(fn (VoidReason $reason): array => [
                    'id' => $reason->id,
                    'label' => (string) ($reason->translate($labelLocale, true)?->label
                        ?? $reason->getRawOriginal('label')
                        ?? ''),
                    'stock_effect' => $reason->stock_effect->value,
                    'requires_note' => (bool) $reason->requires_note,
                    'sort_order' => (int) $reason->sort_order,
                ])
                ->values()
                ->all()
            : [];

        $settingsPayload = $branch
            ? array_merge($settings ? $settings->toArray() : [], [
                'table_status_after_payment' => $effectiveTableStatusAfterPayment,
                'print_label_locale' => $effectivePrintLabelLocale,
                'seller_registration_number' => $sellerRegistrationNumber,
                'item_voidable_statuses' => $resolvedVoidableStatuses,
                'void_reasons' => $voidReasons,
                'operating_country' => $operatingCountry,
            ], BroadcastPokeSettings::resolve())
            : null;

        // #2000 bước 4 — 法人名 (tên PHÁP NHÂN), khác `console_brand_id` là thương
        // hiệu. Quy ước hoá đơn Nhật đặt pháp nhân ở dòng đầu, và nó không chỉ là
        // thẩm mỹ: 登録番号 T+13 (#1152) thuộc về pháp nhân, nên in tên thương
        // hiệu cạnh số của pháp nhân là lệch chủ thể.
        //
        // Phải đi vòng qua `console_organization_id`: `branches` mang khoá do
        // Platform cấp, không phải khoá nội bộ của `organizations`. Đọc thẳng một
        // `organization_id` sẽ cho ra null, cột cho phép null, và không gì đỏ ở
        // đâu — cùng bẫy đã trúng ba lần ở #1957.
        $organizationName = $branch
            ? (string) (Organization::query()
                ->where('console_organization_id', $branch->console_organization_id)
                ->value('name') ?? '')
            : '';

        return response()->json([
            'data' => $branch
                ? array_merge($branch->toArray(), [
                    'organization_name' => $organizationName,
                    'settings' => $settingsPayload,
                ])
                : null,
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }
}

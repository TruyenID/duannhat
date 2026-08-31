<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Services\Compliance\ComplianceProfileResolver;
use App\Services\Loyalty\MembershipTierBackgroundService;
use App\Services\Shop\BrandSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Brand-level cart timeout default — read + update.
 *
 * Endpoints:
 *   GET   /api/v1/hq/{brandSlug}/settings/brand
 *   PATCH /api/v1/hq/{brandSlug}/settings/brand
 *
 * `cart_timeout_minutes` is the tầng ① (Tier 1) brand-wide default.
 * Individual menus, shops, and shop-menus can each override it.
 */
class HqBrandSettingsController extends Controller
{
    public function __construct(private readonly BrandSettingsService $settings) {}

    // =========================================================================
    //  Show
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/settings/brand',
        summary: 'Get brand-level cart timeout default',
        description: 'Returns the brand-wide cart_timeout_minutes default (Tier 1 in the 4-tier inheritance chain).',
        tags: ['HQ - Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Brand settings',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(
                                property: 'cart_timeout_minutes',
                                type: 'integer',
                                nullable: true,
                                description: 'Brand-wide cart timeout default. null = no default set.'
                            ),
                            new OA\Property(
                                property: 'point_earn_amount',
                                type: 'number',
                                nullable: true,
                                description: '#1674 — money side of the earn rate ("<amount> money = <points> points"). null = brand uses the system default for its branch currency.'
                            ),
                            new OA\Property(
                                property: 'point_earn_points',
                                type: 'integer',
                                nullable: true,
                                description: '#1674 — points side of the earn rate. Always null or non-null together with point_earn_amount.'
                            ),
                            new OA\Property(
                                property: 'customer_tier_card_backgrounds',
                                type: 'object',
                                description: '#1772 — membership card background per tier, as {tier_key: file_id}. PATCH this shape back. Missing key = tier not configured.',
                                additionalProperties: new OA\AdditionalProperties(type: 'string', format: 'uuid')
                            ),
                            new OA\Property(
                                property: 'customer_tier_card_background_urls',
                                type: 'object',
                                description: '#1772 — READ-ONLY. Same keys resolved to URLs at read time (for preview). Never send this back — it would pin the host it was rendered on.',
                                additionalProperties: new OA\AdditionalProperties(type: 'string', nullable: true)
                            ),
                            new OA\Property(
                                property: 'membership_tiers',
                                type: 'array',
                                description: '#1772 — READ-ONLY. Tier keys from config/loyalty.php, lowest threshold first. The settings screen renders one upload slot per entry.',
                                items: new OA\Items(type: 'string')
                            ),
                        ]
                    ),
                ])
            ),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        return response()->json([
            'data' => $this->settings->settingsPayload($brand),
        ]);
    }

    // =========================================================================
    //  Update
    // =========================================================================

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/settings/brand',
        summary: 'Update brand-level cart timeout default',
        description: 'Sets cart_timeout_minutes on the brand row (Tier 1). Pass null to clear the default.',
        tags: ['HQ - Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'cart_timeout_minutes',
                        type: 'integer',
                        nullable: true,
                        minimum: 1,
                        description: 'Minutes after menu expiry before cart items are disabled. null = no default.'
                    ),
                    new OA\Property(
                        property: 'point_earn_amount',
                        type: 'number',
                        nullable: true,
                        description: '#1674 — must be sent together with point_earn_points. Both null clears the brand rate; sending only one is 422.'
                    ),
                    new OA\Property(
                        property: 'point_earn_points',
                        type: 'integer',
                        nullable: true,
                        description: '#1674 — must be sent together with point_earn_amount.'
                    ),
                    new OA\Property(
                        property: 'customer_tier_card_backgrounds',
                        type: 'object',
                        nullable: true,
                        description: '#1772 — {tier_key: file_id}. Keys must be tiers declared in config/loyalty.php; values must be existing file ids (upload via /api/v1/files/upload). Drop a key (or send null/"") to clear that tier. null or {} clears every tier.',
                        additionalProperties: new OA\AdditionalProperties(type: 'string', format: 'uuid', nullable: true)
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(
        Request $request,
        ComplianceProfileResolver $compliance,
        MembershipTierBackgroundService $tierBackgrounds,
    ): JsonResponse {
        /** @var Brand $requestBrand */
        $requestBrand = $request->attributes->get('brand');
        // #1153 — the registration-number format follows the organization's
        // operating country (JP: インボイス T+13 · VN: mã số thuế).
        $profile = $compliance->forOrganization($requestBrand?->console_organization_id);

        $request->validate([
            'cart_timeout_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            // #2937 — ngưỡng lệch tiền mặt (minor units). `min:0` CÓ CHỦ ĐÍCH:
            // 0 nghĩa là "báo mọi lệch" và là lựa chọn hợp lệ của brand.
            // KHÔNG `nullable` — cột NOT NULL có default, và để client gửi null
            // là mở một đường thứ hai cho "chưa cấu hình" trong khi cột đã có
            // default. Trần 1.000.000 để một lần gõ nhầm không tắt cảnh báo
            // vĩnh viễn mà không ai thấy.
            'cash_variance_tolerance_minor' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'takeaway_payment_timeout_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:120'],
            'customer_header_logo_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            // #2047 — id File thay cho URL tuyệt đối. `exists` giống hệt
            // `customer_tier_card_backgrounds.*` bên dưới: id rác chỉ khiến ảnh
            // không hiện — một triệu chứng câm mà 422 ngay lúc lưu tránh được.
            'customer_header_logo_file_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('files', 'id')],
            'customer_order_logo_file_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('files', 'id')],
            // #1152 — seller registration number, brand default (branch may
            // override). Format is country-profiled (#1153).
            'invoice_registration_number' => ['sometimes', 'nullable', 'string', 'regex:'.$profile->registrationNumberPattern()],
            'customer_order_logo_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'customer_order_subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            // #1772 — ảnh nền thẻ thành viên: map {tier_key: file_id}.
            //
            // `Rule::array(...)` chốt KHOÁ vào đúng thang hạng ở
            // `config/loyalty.php`. Không có nó thì một khoá gõ sai ("platinium")
            // vẫn lưu được, không tầng nào kêu, và người cấu hình đinh ninh đã
            // đặt ảnh cho hạng Bạch kim trong khi khách không bao giờ thấy nó.
            //
            // `exists` trên GIÁ TRỊ vì thứ đi vào đây là id của một File có
            // thật — id rác thì ảnh chỉ đơn giản là không hiện, một triệu chứng
            // câm mà 422 ngay lúc lưu sẽ tránh được.
            'customer_tier_card_backgrounds' => ['sometimes', 'nullable', Rule::array($tierBackgrounds->tierKeys())],
            'customer_tier_card_backgrounds.*' => ['nullable', 'uuid', Rule::exists('files', 'id')],
            // #1674 — cặp tỉ lệ tích điểm: "<amount> tiền = <points> điểm".
            //
            // Cố ý KHÔNG có `sometimes`: nửa cặp không phải một tỉ lệ, nên khoá
            // vắng mặt phải bị coi là null để `required_with` bắt được. Có
            // `sometimes` thì gửi mỗi `point_earn_amount` sẽ lọt qua và ghi vào
            // DB một brand có tiền mà không có điểm.
            //
            // `required_with` bỏ qua giá trị null, nên gửi cả hai là null (xoá
            // cấu hình, trả brand về mặc định hệ thống) vẫn hợp lệ.
            //
            // `gt:0` chứ không phải `min:0`: `amount = 0` khiến
            // `CustomerPointService::pointsForOrder()` trả 0 điểm mà không kêu
            // tiếng nào — một cách tắt tích điểm mà không ai đọc ra được từ
            // màn hình cài đặt.
            'point_earn_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999', 'required_with:point_earn_points'],
            'point_earn_points' => ['nullable', 'integer', 'gt:0', 'max:1000000', 'required_with:point_earn_amount'],
            // plan-037 — brand-default confirmation countdown. Range 1–30
            // matches the per-shop override validator.
            'default_confirmation_timeout_minutes' => ['sometimes', 'integer', 'min:1', 'max:30'],
            // #1160 — brand-default prep minutes per item (customer ETA = this
            // x total quantity). Same 0–120 range as the per-shop override;
            // null clears the brand default.
            'default_prep_minutes_per_item' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            // #491 — HQ default table status after payment.
            'default_table_status_after_payment' => ['sometimes', Rule::in(['free', 'cleaning'])],
            // #890 — shop may edit HQ-origin tables.
            'allow_shop_edit_hq_tables' => ['sometimes', 'boolean'],
            // Ngôn ngữ in mặc định cả chuỗi. Whitelist đúng tập locale toàn hệ
            // thống (omnify.yaml `locale.locales`) — cột là string(8) nên nếu
            // không chặn ở đây, một giá trị rác sẽ sync xuống workstation và
            // phiếu im lặng rơi về nhãn mặc định.
            'default_print_label_locale' => ['sometimes', 'nullable', Rule::in(['ja', 'en', 'vi'])],
        ]);

        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        // Pass only the keys the client actually sent so each policy field can be
        // upserted independently — the service handles the brand-column update +
        // BrandOrderPolicy upserts.
        $data = $request->only([
            'cart_timeout_minutes',
            'takeaway_payment_timeout_minutes',
            'customer_header_logo_url',
            'customer_header_logo_file_id',
            'invoice_registration_number',
            'customer_order_logo_url',
            'customer_order_logo_file_id',
            'customer_order_subtitle',
            'customer_tier_card_backgrounds',
            'point_earn_amount',
            'point_earn_points',
            'default_confirmation_timeout_minutes',
            'default_prep_minutes_per_item',
            'default_table_status_after_payment',
            'allow_shop_edit_hq_tables',
            'default_print_label_locale',
            // #2937 — LỚP LỌC THỨ HAI. Thêm rule vào `validate()` là CHƯA ĐỦ:
            // danh sách này strip mọi key không có mặt, nên một trường mới đi
            // hết đường service mà không bao giờ nhận được giá trị — và test
            // gọi thẳng service vẫn xanh. Cùng họ #2622, ở một tầng khác.
            'cash_variance_tolerance_minor',
        ]);

        return response()->json(['data' => $this->settings->update($brand, $data)]);
    }
}

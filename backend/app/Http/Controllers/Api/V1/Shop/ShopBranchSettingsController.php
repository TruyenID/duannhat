<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Shop\ShopBranchSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Shop-level branch settings — read + update.
 *
 * Endpoints:
 *   GET   /api/v1/shops/{shopSlug}/settings/branch
 *   PATCH /api/v1/shops/{shopSlug}/settings/branch
 *
 * Settings:
 *   - `cart_timeout_minutes` — tầng ③ (Tier 3) shop-default.
 *   - `takeaway_payment_timeout_minutes` — plan-031: shop override for takeaway payment countdown.
 *   - `point_earn_amount` + `point_earn_points` — #1674: ghi đè tỉ lệ tích
 *     điểm cho riêng chi nhánh. Một CẶP nguyên tử: cả hai cùng null (kế thừa
 *     brand) hoặc cả hai cùng dương. Nửa cặp là 422 — nó không phải một tỉ lệ,
 *     và nếu lọt vào DB thì `CustomerPointService` bỏ qua tầng này.
 *
 * The response also includes the tầng ① (brand default) and the effective value for each setting.
 */
class ShopBranchSettingsController extends Controller
{
    // =========================================================================
    //  Show
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/settings/branch',
        summary: 'Get shop-level cart timeout default',
        description: 'Returns the shop cart_timeout_minutes (Tier 3), the HQ brand default (Tier 1), and the effective value resolving through the chain.',
        tags: ['Shop Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Branch settings',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'cart_timeout_minutes', type: 'integer', nullable: true, description: 'Shop-level override (Tier 3). null = not set.'),
                            new OA\Property(property: 'hq_brand_timeout_minutes', type: 'integer', nullable: true, description: 'Brand-wide default (Tier 1). null = not set.'),
                            new OA\Property(property: 'effective_timeout_minutes', type: 'integer', nullable: true, description: 'Resolved value: shop ?? brand. null = neither tier has a value.'),
                            new OA\Property(property: 'point_earn_amount', type: 'number', nullable: true, description: '#1674 — branch override, money side. null = inherit the brand default.'),
                            new OA\Property(property: 'point_earn_points', type: 'integer', nullable: true, description: '#1674 — branch override, points side.'),
                            new OA\Property(property: 'hq_brand_point_earn_amount', type: 'number', nullable: true, description: '#1674 — brand default, money side.'),
                            new OA\Property(property: 'hq_brand_point_earn_points', type: 'integer', nullable: true, description: '#1674 — brand default, points side.'),
                            new OA\Property(property: 'effective_point_earn_amount', type: 'number', nullable: true, description: '#1674 — branch ?? brand. null = both empty, so earning falls back to config(loyalty.earn) for the branch currency.'),
                            new OA\Property(property: 'effective_point_earn_points', type: 'integer', nullable: true, description: '#1674 — branch ?? brand.'),
                            new OA\Property(property: 'faq_inherit_hq', type: 'boolean', description: '#1673 — read the organization-wide FAQ set alongside this branch own entries. No brand tier, so no hq_brand_/effective_ pair.'),
                        ]
                    ),
                ])
            ),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return response()->json(['data' => $this->payload($shop)]);
    }

    // =========================================================================
    //  Update
    // =========================================================================

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/settings/branch',
        summary: 'Update shop-level cart timeout default',
        description: 'Sets cart_timeout_minutes on the branch row (Tier 3). Pass null to clear the override and fall back to the brand default.',
        tags: ['Shop Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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
                        description: 'Minutes after menu expiry before cart items are disabled. null = use brand default.'
                    ),
                    new OA\Property(
                        property: 'point_earn_amount',
                        type: 'number',
                        nullable: true,
                        description: '#1674 — must be sent together with point_earn_points. Both null clears the branch override; sending only one is 422.'
                    ),
                    new OA\Property(
                        property: 'point_earn_points',
                        type: 'integer',
                        nullable: true,
                        description: '#1674 — must be sent together with point_earn_amount.'
                    ),
                    // #1706 — hai trường dưới đây endpoint ĐÃ nhận và ghi từ
                    // lâu nhưng không khai. Một trường ghi được mà không khai là
                    // hai lỗi cùng lúc: người dùng hợp lệ không biết nó tồn
                    // tại, còn người dò API thì biết.
                    new OA\Property(
                        property: 'invoice_registration_number',
                        type: 'string',
                        nullable: true,
                        description: '#1152/#1153 — số đăng ký người bán, GHI ĐÈ giá trị của brand cho riêng chi nhánh này và được SNAPSHOT lên hoá đơn lúc phát hành. Định dạng theo quốc gia của tổ chức: JP インボイス `T` + 13 chữ số · VN mã số thuế. null hoặc chuỗi rỗng = xoá ghi đè, quay về giá trị brand. Sai định dạng là 422.'
                    ),
                    new OA\Property(
                        property: 'faq_inherit_hq',
                        type: 'boolean',
                        description: '#1673 — chi nhánh có hiển thị kèm bộ Câu hỏi thường gặp cấp tổ chức hay không. Tắt đi thì khách chỉ thấy câu hỏi riêng của chi nhánh. Không nhận null.'
                    ),
                    // `takeaway_payment_timeout_minutes` CỐ Ý không có ở đây:
                    // từ #1705 nó chỉ ghi được qua
                    // `PATCH /shops/{shopSlug}/settings/takeaway-payment`.
                    // Endpoint này vẫn TRẢ VỀ nó trong payload đọc.
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated — returns full chain'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, ShopBranchSettingsService $service): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return response()->json(['data' => $this->payload($service->update($request, $shop))]);
    }

    /**
     * Chuỗi kế thừa của mọi cài đặt, cho cả `show` và `update`.
     *
     * Trước #1674 khối này được chép nguyên văn ở hai chỗ; thêm cặp tỉ lệ tích
     * điểm vào bản sao thứ hai mà quên bản đầu là một lỗi im lặng (màn hình đọc
     * một đằng, ghi xong hiện một nẻo), nên gộp lại làm một.
     *
     * @return array<string, mixed>
     */
    private function payload(Branch $shop): array
    {
        $shop->loadMissing('brand');

        // #1674 — mỗi tầng chỉ tính là "có đặt" khi CẢ HAI vế dương; nửa cặp
        // không phải một tỉ lệ, và `CustomerPointService` cũng bỏ qua nó.
        $branchRate = $this->ratePair($shop->point_earn_amount, $shop->point_earn_points);
        $brandRate = $this->ratePair($shop->brand?->point_earn_amount, $shop->brand?->point_earn_points);
        $effectiveRate = $branchRate ?? $brandRate;

        return [
            'cart_timeout_minutes' => $shop->cart_timeout_minutes,
            'hq_brand_timeout_minutes' => $shop->brand?->cart_timeout_minutes,
            'effective_timeout_minutes' => $shop->cart_timeout_minutes ?? $shop->brand?->cart_timeout_minutes,
            'takeaway_payment_timeout_minutes' => $shop->takeaway_payment_timeout_minutes,
            'hq_brand_takeaway_payment_timeout_minutes' => $shop->brand?->takeaway_payment_timeout_minutes,
            'effective_takeaway_payment_timeout_minutes' => $shop->takeaway_payment_timeout_minutes
                ?? $shop->brand?->takeaway_payment_timeout_minutes,
            // #1152 — インボイス T+13: shop override ?? brand default.
            'invoice_registration_number' => $shop->invoice_registration_number,
            'hq_brand_invoice_registration_number' => $shop->brand?->invoice_registration_number,
            'effective_invoice_registration_number' => $shop->invoice_registration_number
                ?? $shop->brand?->invoice_registration_number,
            // #1674 — tỉ lệ tích điểm: chi nhánh ?? brand. Cả hai cùng null ⇒
            // `effective_*` cũng null, nghĩa là rơi tiếp về mặc định hệ thống
            // theo đơn vị tiền — giá trị đó KHÔNG trả ở đây vì nó là chuyện của
            // `config('loyalty.earn')`, không phải một ô ai đó đã đặt.
            'point_earn_amount' => $branchRate[0] ?? null,
            'point_earn_points' => $branchRate[1] ?? null,
            'hq_brand_point_earn_amount' => $brandRate[0] ?? null,
            'hq_brand_point_earn_points' => $brandRate[1] ?? null,
            'effective_point_earn_amount' => $effectiveRate[0] ?? null,
            'effective_point_earn_points' => $effectiveRate[1] ?? null,
            // #1673 — chi nhánh có đọc kèm bộ FAQ của HQ hay không. KHÔNG có
            // tầng "kế thừa từ brand" như các trường trên, nên cũng không có
            // cặp `hq_brand_*` / `effective_*`: FAQ chỉ có hai cấp (tổ chức và
            // chi nhánh) và đây là giá trị duy nhất.
            'faq_inherit_hq' => (bool) $shop->faq_inherit_hq,
        ];
    }

    /**
     * Cặp tỉ lệ dùng được, hoặc null nếu tầng này chưa đặt (kể cả nửa cặp).
     *
     * @return array{float, int}|null
     */
    private function ratePair(mixed $amount, mixed $points): ?array
    {
        $amount = (float) ($amount ?? 0);
        $points = (int) ($points ?? 0);

        return ($amount > 0 && $points > 0) ? [$amount, $points] : null;
    }
}

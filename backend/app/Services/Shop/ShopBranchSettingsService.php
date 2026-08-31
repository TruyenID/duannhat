<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Services\Compliance\ComplianceProfileResolver;
use Illuminate\Http\Request;

/**
 * #1696 (child of #1666) — the validate+write body behind the two endpoints
 * that write shop-level columns on `branches`:
 *
 *   PATCH /api/v1/shops/{shopSlug}/settings/branch            → {@see update()}
 *   PATCH /api/v1/shops/{shopSlug}/settings/takeaway-payment  → {@see updateTakeawayPaymentTimeout()}
 *
 * ONE service for both, and the reason is measured rather than assumed: the
 * takeaway endpoint's ENTIRE write surface is a single column
 * (`takeaway_payment_timeout_minutes`) that the branch endpoint already writes,
 * under a 5..120 rule whose bounds AND error string were duplicated verbatim in
 * both controllers. Two services would have preserved that duplication — which
 * is the drift this extraction exists to remove. The two endpoints keep their
 * own response envelopes (the takeaway one returns a 3-key subset); that is
 * presentation and stays in the controllers.
 *
 * This is code MOTION. Every 422 body is emitted through
 * `abort(response()->json(...))` — the same device
 * {@see ShopOrderSettingsService} uses for its 409s — precisely so the response
 * shape does NOT change. A `FormRequest` would have re-wrapped these bodies in
 * Laravel's own envelope (`message` becomes the first error string instead of
 * the literal "Validation error."), and admin-web reads these responses. That
 * would be a breaking change, so the hand-rolled checks move ACROSS unchanged
 * rather than being converted on the way.
 *
 * The one behavioural difference between the two entry points is deliberate and
 * pre-existing: the branch endpoint is a true partial update (a key absent from
 * the body is left alone), while the takeaway endpoint ALWAYS writes its column,
 * so a PATCH omitting the key clears the override. Preserved as-is.
 */
class ShopBranchSettingsService
{
    /**
     * Shortest and longest takeaway payment countdown an operator may set, in
     * minutes. Single definition — both endpoints validated against their own
     * copy of these bounds before #1696.
     */
    private const TAKEAWAY_TIMEOUT_MIN = 5;

    private const TAKEAWAY_TIMEOUT_MAX = 120;

    public function __construct(private readonly ComplianceProfileResolver $complianceProfiles) {}

    /**
     * Apply a partial update to a branch's settings — only the keys the client
     * actually sent — and return the refreshed branch.
     *
     * `$request` is passed through rather than flattened into an array for the
     * same reason {@see ShopOrderSettingsService::update()} does it: the body
     * distinguishes "key absent" from "key sent as null" via `has()`, and
     * re-deriving that from an array is exactly the kind of incidental edit this
     * extraction must not make.
     */
    public function update(Request $request, Branch $shop): Branch
    {
        $updateData = [];

        // cart_timeout_minutes
        if ($request->has('cart_timeout_minutes')) {
            $value = $request->input('cart_timeout_minutes');
            if ($value !== null) {
                $intValue = is_numeric($value) ? (int) $value : null;
                if ($intValue === null || $intValue < 1 || $intValue > 1440) {
                    $this->reject('cart_timeout_minutes', 'The cart timeout minutes must be between 1 and 1440.');
                }
                $updateData['cart_timeout_minutes'] = $intValue;
            } else {
                $updateData['cart_timeout_minutes'] = null;
            }
        }

        // #1705 — `takeaway_payment_timeout_minutes` KHÔNG ghi được từ đây nữa.
        //
        // Trước đó hai endpoint cùng ghi cột này với HAI ngữ nghĩa khác nhau:
        // `settings/branch` là partial update thật (thiếu khoá = không đụng),
        // còn `settings/takeaway-payment` LUÔN ghi cột (thiếu khoá = xoá
        // override). Nên một PATCH rỗng tới endpoint kia âm thầm xoá giá trị mà
        // màn hình này vừa đặt, và ngược lại.
        //
        // Chủ sở hữu là `settings/takeaway-payment` — đã đo: admin-web CHƯA BAO
        // GIỜ gửi khoá này qua `settings/branch` (nó chỉ gửi
        // cart_timeout_minutes · invoice_registration_number · cặp point-earn ·
        // faq_inherit_hq). Nên gỡ khỏi bề mặt GHI ở đây không phá client nào.
        //
        // Vẫn TRẢ VỀ trong payload đọc, kèm giá trị brand và giá trị hiệu lực:
        // màn hình cài đặt chi nhánh hiển thị chúng, chỉ là không sửa ở đó.

        // #1152 — invoice_registration_number (null/empty clears the shop
        // override → falls back to the brand default). Format is
        // country-profiled (#1153): JP インボイス T+13 · VN mã số thuế.
        if ($request->has('invoice_registration_number')) {
            $profile = $this->complianceProfiles->forOrganization($shop?->console_organization_id);
            $value = $request->input('invoice_registration_number');
            if ($value !== null && $value !== '') {
                if (! is_string($value) || ! preg_match($profile->registrationNumberPattern(), $value)) {
                    $this->reject('invoice_registration_number', $profile->registrationNumberError());
                }
                $updateData['invoice_registration_number'] = $value;
            } else {
                $updateData['invoice_registration_number'] = null;
            }
        }

        // #1674 — cặp tỉ lệ tích điểm. Nguyên tử: gửi một khoá mà thiếu khoá
        // kia là 422, vì nửa cặp không phải một tỉ lệ — ghi được nó vào DB
        // nghĩa là chi nhánh trông như "đã đặt" trong khi tính điểm vẫn kế thừa
        // brand. Cả hai null = xoá ghi đè, quay về kế thừa.
        $hasAmount = $request->has('point_earn_amount');
        $hasPoints = $request->has('point_earn_points');

        if ($hasAmount || $hasPoints) {
            if (! $hasAmount || ! $hasPoints) {
                $this->reject(
                    $hasAmount ? 'point_earn_points' : 'point_earn_amount',
                    'point_earn_amount and point_earn_points must be sent together.',
                );
            }

            $amount = $request->input('point_earn_amount');
            $points = $request->input('point_earn_points');

            if ($amount === null && $points === null) {
                $updateData['point_earn_amount'] = null;
                $updateData['point_earn_points'] = null;
            } else {
                $errors = [];

                // `> 0` chứ không phải `>= 0`: amount = 0 khiến việc tính điểm
                // trả về 0 mà không kêu tiếng nào — một cách tắt tích điểm
                // không ai đọc ra được từ màn hình cài đặt.
                if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 9999999999) {
                    $errors['point_earn_amount'] = ['The point earn amount must be greater than 0.'];
                }

                if (! is_numeric($points) || (int) $points != $points || (int) $points <= 0 || (int) $points > 1000000) {
                    $errors['point_earn_points'] = ['The point earn points must be a whole number greater than 0.'];
                }

                if ($errors !== []) {
                    abort(response()->json(['message' => 'Validation error.', 'errors' => $errors], 422));
                }

                $updateData['point_earn_amount'] = (float) $amount;
                $updateData['point_earn_points'] = (int) $points;
            }
        }

        // #1673 — công tắc kế thừa FAQ của HQ. Ở đây thay vì một endpoint
        // riêng: nó là cài đặt của chi nhánh, đúng chỗ với cart timeout và
        // số đăng ký hoá đơn.
        if ($request->has('faq_inherit_hq')) {
            $value = $request->input('faq_inherit_hq');

            if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                $this->reject('faq_inherit_hq', 'The faq inherit hq field must be true or false.');
            }

            $updateData['faq_inherit_hq'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $shop->update($updateData);

        return $shop->refresh();
    }

    /**
     * The single-setting endpoint. Unlike {@see update()} this ALWAYS writes the
     * column, so a body without the key clears the shop override and the branch
     * falls back to the brand default — pre-existing behaviour, kept.
     */
    public function updateTakeawayPaymentTimeout(Branch $shop, mixed $value): Branch
    {
        $shop->update([
            'takeaway_payment_timeout_minutes' => $this->normalizeTakeawayPaymentTimeout($value),
        ]);

        return $shop;
    }

    /**
     * null (clear the override) or an int within the allowed window; anything
     * else aborts with the 422 body both controllers used to build by hand.
     */
    private function normalizeTakeawayPaymentTimeout(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $intValue = is_numeric($value) ? (int) $value : null;

        if ($intValue === null || $intValue < self::TAKEAWAY_TIMEOUT_MIN || $intValue > self::TAKEAWAY_TIMEOUT_MAX) {
            $this->reject(
                'takeaway_payment_timeout_minutes',
                'The takeaway payment timeout minutes must be between '
                    .self::TAKEAWAY_TIMEOUT_MIN.' and '.self::TAKEAWAY_TIMEOUT_MAX.'.',
            );
        }

        return $intValue;
    }

    /**
     * Emit the hand-rolled 422 envelope both controllers shipped, byte for byte.
     *
     * Deliberately NOT `ValidationException`: that would rewrite `message` to
     * the first error string. admin-web reads these bodies, so the envelope is
     * the contract.
     *
     * @return never
     */
    private function reject(string $field, string $message): void
    {
        abort(response()->json([
            'message' => 'Validation error.',
            'errors' => [$field => [$message]],
        ], 422));
    }
}

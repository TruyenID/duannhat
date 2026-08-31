<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Contracts\OrderSnapshot;
use Database\Seeders\IamSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
| Feature tests get a baseline `org-001` Organization row created in beforeEach
| so factories that hard-code `organization_id => 'org-001'` (the canonical
| dev org used across the suite) don't trip the FK constraint. Tests that need
| extra orgs can still create their own.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Use a valid uuid for org-001 so request validators that check
        // uuid format (vd Material organization_id) accept it.
        Organization::factory()->create([
            'id' => '00000000-0000-0000-0000-000000000001',
            'console_organization_id' => '00000000-0000-0000-0000-000000000001',
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Arch');

// Plan-019 Unit/Promotion tests instantiate Eloquent models that touch
// Astrotomic\Translatable which calls config(). Bind TestCase so the app
// boots even for unit tests under Promotion/.
pest()->extend(TestCase::class)
    ->in('Unit/Promotion');

// KdsRuleViolation calls config() + response() helpers — needs the app to boot.
pest()->extend(TestCase::class)
    ->in('Unit/Exceptions');

// RebaseStorageUrl cast reads config('filesystems.uploads') — needs app booted.
pest()->extend(TestCase::class)
    ->in('Unit/Casts');

// Plan-050 — settlement row assembly / fee estimator unit tests use model
// factories (connection + provider rows), so they need the app + a database.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Services/Settlement');

pest()->extend(TestCase::class)
    ->in('Browser');

/*
 * Plan-036 T3.0 — Eloquent strict mode for the manager-till-tracking
 * surface. Scoped to the plan-036 feature suite (Shop/ShopTillTracking*
 * and Shop/TillTracking/*) so it catches N+1 in these endpoints without
 * destabilising the rest of the suite, which has not yet been audited
 * for strict-mode compliance. Re-scope this to global `Feature` once
 * the rest of the codebase is opted in.
 */
pest()->beforeEach(function () {
    Model::preventLazyLoading();
})->afterEach(function () {
    // preventLazyLoading() flips a GLOBAL static flag, not a per-test setting.
    // Without this reset it stays ON for every test that runs AFTER a plan-036
    // test in the same process, making unrelated tests that legitimately
    // lazy-load (e.g. translatable models like PaymentMethod) throw a
    // LazyLoadingViolationException → 500. Reset it so strict mode truly stays
    // scoped to the files below.
    Model::preventLazyLoading(false);
})->in(
    'Feature/Shop/ShopTillTrackingDashboardTest.php',
    'Feature/Shop/ShopTillTrackingSessionsTest.php',
    'Feature/Shop/ShopTillTrackingSessionDetailTest.php',
    'Feature/Shop/ShopTillTrackingZReportTest.php',
    'Feature/Shop/TillTracking',
    'Feature/Policies/ShopTillTrackingPolicyTest.php',
);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Grant a user IAM access to an organization by assigning org-admin role.
 * Call this in beforeEach after creating the user to satisfy ResolveBrandFromSlug
 * and ResolveShopFromSlug IAM checks.
 */
function grantOrgAccess(User $user, string $organizationId): void
{
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    $role = Role::query()->where('slug', 'org-admin')->firstOrFail();
    $user->assignRole($role, $organizationId);
}

/**
 * Tạo một câu hỏi thường gặp qua chính đường ghi thật, ở phạm vi của `$url`
 * (`/hq/{brand}/faqs` = cấp tổ chức, `/shops/{shop}/faqs` = riêng chi nhánh).
 *
 * Ở đây chứ không ở file test vì HAI file dùng chung (`ShopFaqTest` #1673 và
 * `ShopFaqVisibilityTest` #1684): hàm khai trong một file test chỉ tồn tại khi
 * file đó được nạp, nên chạy riêng file thứ hai sẽ chết "undefined function".
 */
function makeFaq(string $url, string $question, array $extra = []): array
{
    return test()->postJson($url, array_merge([
        'vi' => ['question' => $question, 'answer' => "Trả lời: {$question}"],
    ], $extra))->assertCreated()->json('data');
}

/** Bật/tắt kế thừa FAQ cả cụm qua endpoint cài đặt thật, không sửa thẳng model. */
function setInherit(Branch $shop, bool $on): void
{
    test()->patchJson("/api/v1/shops/{$shop->slug}/settings/branch", [
        'faq_inherit_hq' => $on,
    ])->assertOk();
}

/**
 * #1594 — ảnh chụp Ordering công bố, lấy qua CỔNG.
 *
 * Payments nhận `OrderSnapshot` chứ không nhận `CustomerOrder` (mint QR PayPay,
 * Stripe Terminal…). Test dựng model bằng factory, nên nó cần đúng một bước
 * chuyển — và bước đó phải đi qua `OrderQueryPort` chứ không phải
 * `CustomerOrderSnapshot::fromModel()`: cái sau nằm trong `Order\Internal`, và
 * gọi thẳng nó ở test là ghim một đường mà production không đi.
 *
 * Đọc lại từ DB, nên đây cũng là `$order->fresh()` của phía ảnh chụp.
 */
function orderSnapshot(CustomerOrder $order): OrderSnapshot
{
    return app(OrderQueryPort::class)->findById(
        (string) $order->organization_id,
        (string) $order->id,
    ) ?? throw new RuntimeException('Order '.$order->id.' has no snapshot — wrong organization_id?');
}

/**
 * `status` có thể là enum cast hoặc chuỗi tuỳ cách hydrate — chuẩn hoá về chuỗi.
 *
 * #2778 — SỐNG Ở ĐÂY, không ở trong một file test.
 *
 * Nó từng khai trong `VoidRefundLineIsRefusedTest.php` nhưng
 * `RefundTraceProtectionTest.php` cũng gọi. Chạy tuần tự thì mọi file test nằm
 * chung MỘT process nên hàm đã tồn tại lúc file thứ hai chạy — đúng kiểu phụ
 * thuộc vô hình. Chạy `--parallel` thì hai file rơi vào hai process khác nhau
 * và file thứ hai chết với `Call to undefined function statusValue()`.
 */
function statusValue(CustomerOrderItem $item): string
{
    $v = $item->status;

    return is_object($v) && property_exists($v, 'value') ? (string) $v->value : (string) $v;
}
